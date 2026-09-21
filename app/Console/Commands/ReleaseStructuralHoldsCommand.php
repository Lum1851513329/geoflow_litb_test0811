<?php

namespace App\Console\Commands;

use App\Domain\ContentGeneration\GeneratedArticleParser;
use App\Domain\ContentGeneration\GenerationAdoptionService;
use App\Models\Admin;
use App\Models\Author;
use App\Models\Category;
use App\Models\ContentGenerationRun;
use App\Models\ContentQualityReport;
use App\Services\GeoFlow\ArticleWorkflowTransitionService;
use App\Support\GeoFlow\ArticleWorkflow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 放行"只被结构性规则拦下"的历史 run。
 *
 * 这四条检查在 DeterministicQualityGate 里已经从 error 降级为 warning，但
 * content_quality_reports 里的判决是当时写死的快照，不会随代码改变而重算，
 * 于是这批 run 一直停在 manual_review_required。本命令只放行 issue 全部落在
 * 降级集合内的 run —— 只要还夹带 invalid_blueprint、duplicate_paragraph 这类
 * 仍然是 error 的问题，就原样留在人工复核里。
 */
final class ReleaseStructuralHoldsCommand extends Command
{
    /** 已降级为 warning 的检查项；run 的 issue 必须全部落在这个集合里才放行。 */
    private const RELAXED_CODES = [
        'section_count_mismatch',
        'section_too_thin',
        'target_length_out_of_range',
        'missing_question_coverage',
    ];

    protected $signature = 'geoflow:content-strategy:release-structural-holds
        {--dry-run : 只列出会被放行的 run，不做任何写入}
        {--publish : 采纳后把文章推到 published/approved}
        {--limit=200}';

    protected $description = 'Adopt generation runs held only by quality-gate rules that are now warnings';

    public function __construct(
        private readonly ArticleWorkflowTransitionService $articleWorkflowTransitionService,
    ) {
        parent::__construct();
    }

    public function handle(GenerationAdoptionService $adoptions, GeneratedArticleParser $parser): int
    {
        $actor = Admin::query()->where('username', 'admin')->first()
            ?? Admin::query()->orderBy('id')->first();
        if (! $actor) {
            $this->error('No admin account available to record as the adopting actor.');

            return self::FAILURE;
        }

        $limit = max(1, min(500, (int) $this->option('limit')));
        $dryRun = (bool) $this->option('dry-run');
        $publish = (bool) $this->option('publish');

        $runs = ContentGenerationRun::query()
            ->where('status', ContentGenerationRun::STATUS_MANUAL_REVIEW_REQUIRED)
            ->with(['contentBrief'])
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $eligible = $runs->filter(fn (ContentGenerationRun $run): bool => $this->isStructuralOnly($run));

        $this->line(sprintf('%d run(s) in manual review; %d held only by relaxed rules.', $runs->count(), $eligible->count()));

        if ($dryRun) {
            foreach ($eligible as $run) {
                $this->line(sprintf('  run %-5d %s', $run->id, mb_substr((string) $run->contentBrief?->topic, 0, 70)));
            }

            return self::SUCCESS;
        }

        $adopted = 0;
        $published = 0;
        $skipped = 0;

        foreach ($eligible as $run) {
            $extracted = $parser->extractTitle((string) $run->raw_output);
            if ($extracted['title'] === null) {
                $this->warn(sprintf('  run %d skipped: no leading H1 title.', $run->id));
                $skipped++;

                continue;
            }

            try {
                // adopt() 只接受 ready_for_review。状态本身不在不可变字段里，
                // 触发器只禁止 adopted_output 在非 ready_for_review 时被写入。
                ContentGenerationRun::query()->whereKey($run->id)
                    ->update(['status' => ContentGenerationRun::STATUS_READY_FOR_REVIEW]);
                $run->refresh();

                $article = $adoptions->adopt($run, [
                    'title' => $extracted['title'],
                    'content' => $extracted['content'],
                    'category_id' => $this->resolveCategoryId($run),
                    'author_id' => $this->resolveAuthorId(),
                    'task_id' => $run->contentBrief?->task_id,
                ], $actor);
                $adopted++;

                if ($publish) {
                    $this->articleWorkflowTransitionService->transition(
                        $article,
                        ArticleWorkflow::normalizeState('published', 'approved', now()->toDateTimeString()),
                        'release_structural_holds',
                        (int) $actor->id,
                    );
                    $published++;
                }
            } catch (Throwable $exception) {
                Log::warning('Structural hold could not be released', [
                    'content_generation_run_id' => $run->id,
                    'message' => $exception->getMessage(),
                ]);
                $this->warn(sprintf('  run %d failed: %s', $run->id, $exception->getMessage()));
                $skipped++;
            }
        }

        $this->info(sprintf('Adopted %d, published %d, skipped %d.', $adopted, $published, $skipped));

        return self::SUCCESS;
    }

    /** run 的所有拦截项是否都落在已降级集合内。 */
    private function isStructuralOnly(ContentGenerationRun $run): bool
    {
        $reports = ContentQualityReport::query()
            ->where('content_generation_run_id', $run->id)
            ->where('report_type', 'deterministic')
            ->where('status', ContentGenerationRun::STATUS_MANUAL_REVIEW_REQUIRED)
            ->get();

        if ($reports->isEmpty()) {
            return false;
        }

        $codes = [];
        foreach ($reports as $report) {
            foreach ((array) $report->issues as $issue) {
                $code = (string) ($issue['code'] ?? '');
                if ($code !== '') {
                    $codes[$code] = true;
                }
            }
        }

        return $codes !== [] && array_diff(array_keys($codes), self::RELAXED_CODES) === [];
    }

    /**
     * 有的历史 brief.category_id 指向一个显然不是内容分类的脏数据（例如把
     * URL smart-import 误建的分类当成了正式分类，slug 长得像域名）。这类分类
     * 一旦被当成兜底默认值，会把文章批量导流到一个用户根本看不懂的分类下——
     * 2026-09 的一批发布就是这样被发现的。因此兜底分类必须显式命名，
     * 不能再用"排序最靠前的分类"这种容易被脏数据劫持的启发式。
     */
    private const FALLBACK_CATEGORY_NAME = 'LightInTheBox Guides';

    private function resolveCategoryId(ContentGenerationRun $run): ?int
    {
        $categoryId = (int) ($run->contentBrief?->category_id ?? 0);
        if ($categoryId > 0 && Category::query()->whereKey($categoryId)->exists()) {
            return $categoryId;
        }

        return Category::query()->where('name', self::FALLBACK_CATEGORY_NAME)->value('id')
            ?? Category::query()->orderBy('sort_order')->orderBy('id')->value('id');
    }

    private function resolveAuthorId(): int
    {
        $author = Author::query()->orderBy('id')->first();
        if ($author) {
            return (int) $author->id;
        }

        return (int) Author::query()->firstOrCreate(
            ['name' => 'GEOFlow'],
            ['bio' => 'Default GEOFlow author for automated content generation.']
        )->id;
    }
}
