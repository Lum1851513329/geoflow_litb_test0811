<?php

namespace App\Console\Commands;

use App\Domain\ContentGeneration\GeneratedArticleParser;
use App\Domain\ContentGeneration\GenerationAdoptionService;
use App\Models\Admin;
use App\Models\Author;
use App\Models\Category;
use App\Models\ContentBrief;
use App\Models\ContentGenerationRun;
use App\Models\Task;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class AdoptTaskDrivenGenerationRunsCommand extends Command
{
    protected $signature = 'geoflow:content-strategy:adopt-task-driven-runs {--limit=100}';

    protected $description = 'Auto-adopt ready-for-review generation runs that belong to a quality-gated Task';

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
            } catch (DomainException $exception) {
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

        $adoptions->adopt($run, [
            'title' => $extracted['title'],
            'content' => $content,
            'category_id' => $this->resolveCategoryId($brief, $task),
            'author_id' => $this->resolveAuthorId($task),
            'task_id' => (int) $task->id,
        ], $systemAdmin);
    }

    private function appendReferencesSection(ContentGenerationRun $run, string $content): string
    {
        $blueprint = (array) ($run->blueprintVersion->blueprint ?? []);
        if (! (bool) ($blueprint['show_references_section'] ?? false)) {
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
