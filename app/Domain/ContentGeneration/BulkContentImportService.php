<?php

namespace App\Domain\ContentGeneration;

use App\Models\Admin;
use App\Models\Task;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Support\AdminActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BulkContentImportService
{
    public function __construct(
        private readonly BulkContentImportParser $parser,
        private readonly TaskLifecycleService $taskLifecycleService,
        private readonly DistributionOrchestrator $distributionOrchestrator,
    ) {}

    /**
     * @return array{tasks_created: int, rows_imported: int}
     */
    public function import(string $filePath, int $aiModelId, bool $needReview, Admin $actor, string $externalEvidencePolicy = 'off', array $externalGeneralDomains = []): array
    {
        $parsed = $this->parser->parse($filePath);
        if ($parsed['errors'] !== []) {
            throw new BulkContentImportValidationException($parsed['errors']);
        }

        $conflictErrors = $this->parser->conflicts($parsed['rows']);
        if ($conflictErrors !== []) {
            throw new BulkContentImportValidationException($conflictErrors);
        }

        $groups = [];
        foreach ($parsed['rows'] as $row) {
            $groups[$row->groupKey()][] = $row;
        }

        // The whole batch must be all-or-nothing: each group's Task+TitleLibrary+channel-sync is already
        // atomic via its own DB::transaction() inside createTaskForGroup(), but without this outer
        // transaction a runtime failure in group N (e.g. a category deleted between upload and processing)
        // would leave groups 1..N-1 committed as real Tasks while import() still throws out to the
        // controller. Laravel nests DB::transaction() calls as savepoints, so createTaskForGroup()'s own
        // transaction continues to work unchanged inside this one.
        $tasksCreated = DB::transaction(function () use ($groups, $aiModelId, $needReview, $actor, $externalEvidencePolicy, $externalGeneralDomains): int {
            $tasksCreated = 0;
            foreach ($groups as $rowsInGroup) {
                $this->createTaskForGroup($rowsInGroup, $aiModelId, $needReview, $actor, $externalEvidencePolicy, $externalGeneralDomains);
                $tasksCreated++;
            }

            return $tasksCreated;
        });

        return ['tasks_created' => $tasksCreated, 'rows_imported' => count($parsed['rows'])];
    }

    /** @param list<BulkContentImportRow> $rows */
    private function createTaskForGroup(array $rows, int $aiModelId, bool $needReview, Admin $actor, string $externalEvidencePolicy, array $externalGeneralDomains): void
    {
        $first = $rows[0];

        DB::transaction(function () use ($rows, $first, $aiModelId, $needReview, $actor, $externalEvidencePolicy, $externalGeneralDomains): void {
            $library = TitleLibrary::query()->create([
                'name' => 'Bulk import '.now()->format('Y-m-d H:i').' — '.Str::limit($first->topic, 40),
                'generation_type' => 'manual',
            ]);

            foreach ($rows as $row) {
                Title::query()->create([
                    'library_id' => $library->id,
                    'title' => $row->topic,
                    'keyword' => (string) ($row->primaryKeyword ?? ''),
                    'core_question' => $row->coreQuestion,
                    'reference_urls' => $row->referenceUrls,
                ]);
            }

            $rowCount = count($rows);
            $taskData = [
                'name' => 'Bulk import '.now()->format('Y-m-d H:i').' — '.Str::limit($first->topic, 40),
                'title_library_id' => $library->id,
                'image_library_id' => null,
                'image_count' => 0,
                'ai_model_id' => $aiModelId,
                'author_id' => null,
                'need_review' => $needReview ? 1 : 0,
                'draft_limit' => $rowCount,
                'article_limit' => $rowCount,
                'is_loop' => 0,
                'status' => 'active',
                'publish_scope' => 'local_and_distribution',
                'distribution_strategy' => 'broadcast',
                'model_selection_mode' => 'fixed',
                'auto_keywords' => 0,
                'auto_description' => 0,
                'category_mode' => 'fixed',
                'fixed_category_id' => $first->categoryId,
                'generation_engine' => 'quality_gated',
                'external_evidence_policy' => $externalEvidencePolicy,
                'external_general_domains' => $externalGeneralDomains,
                'prompt_preset_id' => $first->promptPresetId,
                'knowledge_mode' => $first->knowledgeMode,
                'knowledge_brand_id' => $first->knowledgeBrandId,
                'knowledge_site_id' => $first->knowledgeSiteId,
                'knowledge_directory_id' => $first->knowledgeDirectoryId,
                'knowledge_base_ids' => $first->knowledgeBaseId === null ? [] : [$first->knowledgeBaseId],
            ];

            $channelIds = [$first->distributionChannelId];
            $this->distributionOrchestrator->lockTaskChannelSelection(null, $channelIds);
            $created = $this->taskLifecycleService->createTask($taskData);
            $task = Task::query()->whereKey((int) ($created['id'] ?? 0))->firstOrFail();
            $this->distributionOrchestrator->syncTaskChannels($task, $channelIds);

            AdminActivityLogger::log($actor, 'bulk_content_import:task_created', [
                'target_type' => 'task',
                'target_id' => $task->id,
                'details' => [
                    'row_count' => count($rows),
                    'distribution_channel_id' => $first->distributionChannelId,
                    'prompt_preset_id' => $first->promptPresetId,
                    'category_id' => $first->categoryId,
                ],
            ]);
        });
    }
}
