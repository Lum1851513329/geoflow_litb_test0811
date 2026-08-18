<?php

namespace App\Console\Commands;

use App\Domain\ContentGeneration\GeneratedArticleParser;
use App\Domain\ContentGeneration\GenerationAdoptionService;
use App\Exceptions\ArticleRiskGateException;
use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\ContentBrief;
use App\Models\ContentGenerationRun;
use App\Models\Task;
use App\Services\GeoFlow\ArticleWorkflowTransitionService;
use App\Support\GeoFlow\ArticleWorkflow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AdoptTaskDrivenGenerationRunsCommand extends Command
{
    protected $signature = 'geoflow:content-strategy:adopt-task-driven-runs {--limit=100}';

    protected $description = 'Auto-adopt ready-for-review generation runs that belong to a quality-gated Task';

    public function __construct(
        private readonly ArticleWorkflowTransitionService $articleWorkflowTransitionService,
    ) {
        parent::__construct();
    }

    public function handle(GenerationAdoptionService $adoptions, GeneratedArticleParser $parser): int
    {
        $systemAdmin = Admin::query()->where('username', 'geoflow-system')->first();
        if (! $systemAdmin) {
            $this->error('No geoflow-system admin found — run "geoflow:content-strategy:seed-system-admin" first.');

            return self::FAILURE;
        }

        $runs = ContentGenerationRun::query()
            ->where('status', ContentGenerationRun::STATUS_READY_FOR_REVIEW)
            ->whereIn('content_brief_id', ContentBrief::query()->whereNotNull('task_id')->select('id'))
            ->with(['contentBrief.task', 'contentBrief.category'])
            ->orderBy('id')
            ->limit(max(1, min(500, (int) $this->option('limit'))))
            ->get();

        $adopted = 0;
        foreach ($runs as $run) {
            $task = $run->contentBrief?->task;
            if ($task === null || $task->status !== 'active') {
                continue;
            }

            try {
                $this->adoptOne($run, $adoptions, $parser, $systemAdmin);
                $adopted++;
            } catch (Throwable $exception) {
                // 一条 run 失败不能拖垮整批：除了 `GenerationAdoptionService` 抛的
                // `DomainException`，`ArticleGeoFlowService::normalizeReference()` 还会
                // 抛 `ApiException`（RuntimeException 家族，与 DomainException 无继承关系）。
                // 只捕获 DomainException 会让这类错误中断整个批次，而调度每分钟重跑又会
                // 在同一条 run 上以完全相同的方式再失败一次。
                Log::warning('Task-driven run could not be auto-adopted', [
                    'content_generation_run_id' => $run->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $this->info("Auto-adopted {$adopted} task-driven generation run(s).");

        return self::SUCCESS;
    }

    private function adoptOne(ContentGenerationRun $run, GenerationAdoptionService $adoptions, GeneratedArticleParser $parser, Admin $systemAdmin): void
    {
        $extracted = $parser->extractTitle((string) $run->raw_output);
        if ($extracted['title'] === null) {
            Log::warning('Task-driven run reached ready_for_review without a leading H1 title; leaving for manual review', [
                'content_generation_run_id' => $run->id,
            ]);

            return;
        }

        $content = $this->appendReferencesSection($run, $extracted['content']);
        $brief = $run->contentBrief;
        $task = $brief->task;

        $article = $adoptions->adopt($run, [
            'title' => $extracted['title'],
            'content' => $content,
            'category_id' => $this->resolveCategoryId($brief, $task),
            'author_id' => $this->resolveAuthorId($task),
            'task_id' => (int) $task->id,
        ], $systemAdmin);

        $this->recordTaskGeneration($task);
        $this->approveWhenReviewNotRequired($article, $task);
    }

    /**
     * 质量门禁分支的计数记账。旧引擎在建 Article 的事务里自增 `created_count`/
     * `loop_count`，新引擎此前两个都没有动过（只动了 Title 的使用计数），于是
     * `getGenerationBlockReason()` 里的"文章总数上限"永远不会被触发：is_loop=1
     * 的任务会无限生成，is_loop=0 的任务则永远走不到 done、直到标题库耗尽后
     * `pickTitle()` 开始抛错。
     *
     * 记在"采纳成功"这一刻而不是 reserve 时刻：只有真正落地成 Article 的 run 才算
     * 一篇文章，质量门禁失败或长期等待证据的 run 不应该占用总量配额。
     *
     * `next_publish_at` 的补种逻辑与旧引擎保持一致（含 `normalizePublishInterval()`
     * 的 60 秒下限与 3600 秒默认值），否则首篇文章会因为 next_publish_at 为空而
     * 被 `publishDueDraftArticle()` 立刻放行，绕开任务自身的发布节奏。
     */
    private function recordTaskGeneration(Task $task): void
    {
        $update = [
            'created_count' => DB::raw('COALESCE(created_count,0)+1'),
            'loop_count' => DB::raw('COALESCE(loop_count,0)+1'),
            'updated_at' => now(),
        ];

        if ($task->next_publish_at === null || ! $task->next_publish_at->greaterThan(now())) {
            $update['next_publish_at'] = now()->addSeconds(max(60, (int) ($task->publish_interval ?? 3600)));
        }

        Task::query()->whereKey($task->id)->update($update);
    }

    /**
     * `GenerationAdoptionService::adopt()` 无条件把文章建成 draft/pending——它同时服务
     * 于既有的人工采纳 HTTP 流程，不能为任务分支改行为。因此 `need_review=0` 的语义
     * 必须由本命令补上，做法与旧引擎完全一致：把文章推到 draft/approved，风险闸门
     * 拒绝时回落到 draft/pending。
     *
     * 不补这一步的话，`publishDueDraftArticle()` 只捞 review_status 为
     * approved/auto_approved 的草稿，零人工干预的任务会把文章永远堆在 pending。
     */
    private function approveWhenReviewNotRequired(Article $article, Task $task): void
    {
        if ((int) ($task->need_review ?? 1) !== 0) {
            return;
        }

        try {
            $this->articleWorkflowTransitionService->transition(
                $article,
                ArticleWorkflow::normalizeState('draft', 'approved'),
                'worker_publish_task_driven_adoption',
                null,
                null,
                false,
                ArticleWorkflow::normalizeState('draft', 'pending'),
            );
        } catch (ArticleRiskGateException) {
            // 风险闸门拒绝时文章保持 adopted + pending，等人工复核，不影响本次采纳本身。
        }
    }

    /**
     * `show_references_section` 只从 Preset 版本的 `blueprint_template` 读取，不再从
     * 每篇 brief 的 `ContentBlueprintVersion.blueprint` 读取：`PromptCompiler::render()`
     * 会把 per-brief blueprint 原样塞进提示词的 CONTENT BLUEPRINT 段落，模型看到一个
     * 没有任何解释的 `"show_references_section": true` 之后，往往会自己再写一个
     * `## References` 标题，凭空多出第 N+1 个顶层小节，直接踩中按位置比对的
     * `section_count_mismatch` 检查。Preset 的模板本身从不进入提示词，所以这个纯编辑
     * 侧的开关放在那里才是安全的权威来源。
     */
    private function appendReferencesSection(ContentGenerationRun $run, string $content): string
    {
        $template = (array) ($run->presetVersion->blueprint_template ?? []);
        if (! (bool) ($template['show_references_section'] ?? false)) {
            return $content;
        }

        $referenceUrls = (array) ($run->contentBrief->reference_urls ?? []);
        if ($referenceUrls === []) {
            return $content;
        }

        $links = array_map(static fn (string $url): string => '- '.trim($url), $referenceUrls);

        return $content."\n\n## References\n".implode("\n", $links);
    }

    /**
     * `ArticleGeoFlowService::normalizeCreateInput()` resolves `author_id` via
     * `normalizeReference(Author::class, ..., true)`, whose `true` third argument
     * means "required" — a null/zero/non-existent id makes `createArticle()` throw
     * an `ApiException` (not a `DomainException`), which this command's per-run
     * `catch (DomainException ...)` would NOT catch, aborting the whole batch.
     * Mirror `WorkerExecutionService::pickAuthor()`'s fallback so we never pass a
     * value that can fail that required check.
     */
    private function resolveAuthorId(Task $task): int
    {
        $authorId = (int) ($task->custom_author_id ?: $task->author_id);
        if ($authorId > 0 && Author::query()->whereKey($authorId)->exists()) {
            return $authorId;
        }

        $author = Author::query()->orderBy('id')->first();
        if ($author) {
            return (int) $author->id;
        }

        return (int) Author::query()->firstOrCreate(
            ['name' => 'GEOFlow'],
            ['bio' => 'Default GEOFlow author for automated content generation.']
        )->id;
    }

    /**
     * `category_id` goes through the same required `normalizeReference()` call as
     * `author_id`. `ContentBrief.category_id` is normally already populated by
     * `WorkerExecutionService::pickCategory()`'s own fallback at brief-creation
     * time, but mirror that fallback here too so a brief created without a
     * resolvable category doesn't blow up adoption with an uncaught `ApiException`.
     */
    private function resolveCategoryId(ContentBrief $brief, Task $task): ?int
    {
        $categoryId = (int) ($brief->category_id ?? $task->fixed_category_id ?? 0);
        if ($categoryId > 0 && Category::query()->whereKey($categoryId)->exists()) {
            return $categoryId;
        }

        return Category::query()->orderBy('sort_order')->orderBy('id')->value('id');
    }
}
