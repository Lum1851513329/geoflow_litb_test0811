<?php

namespace Tests\Feature;

use App\Domain\ContentGeneration\ContentGenerationRequestService;
use App\Domain\ContentGeneration\GenerationEvidenceSnapshotter;
use App\Domain\ContentStrategy\ContentBriefQuickCreateService;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\ContentBrief;
use App\Models\ContentGenerationRun;
use App\Models\Keyword;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeBrand;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDirectory;
use App\Models\KnowledgeSite;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\ArticleContentGenerationService;
use App\Services\GeoFlow\BrandKnowledgeQueryBuilder;
use App\Services\GeoFlow\JobQueueService;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Services\GeoFlow\WorkerExecutionService;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class BrandScopedGenerationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        Queue::fake();
        config(['geoflow.content_strategy_enabled' => true, 'geoflow.modular_prompts_enabled' => true, 'geoflow.generation_quality_gate_enabled' => true]);
        $brand = KnowledgeBrand::create(['key' => 'brand', 'name' => 'Brand']);
        $site = KnowledgeSite::create(['brand_id' => $brand->id, 'key' => 'shop', 'canonical_host' => 'shop.test', 'role' => 'commerce-primary']);
        $directory = KnowledgeDirectory::create(['brand_id' => $brand->id, 'key' => 'care', 'path' => 'care', 'name' => 'Care']);
        $base = KnowledgeBase::create(['name' => 'Care', 'brand_id' => $brand->id, 'site_id' => $site->id, 'primary_directory_id' => $directory->id, 'language_code' => 'en', 'review_status' => 'reviewed', 'lifecycle_status' => 'active', 'generation_eligible' => true, 'canonical_document_key' => 'care', 'source_url' => 'https://shop.test/care']);
        $chunk = KnowledgeChunk::create(['knowledge_base_id' => $base->id, 'chunk_index' => 0, 'content' => 'Linen dress care uses cold water.']);
        $preset = PromptPreset::create(['preset_key' => 'test', 'display_name' => 'Test', 'is_enabled' => true]);
        $version = PromptPresetVersion::forceCreate(['prompt_preset_id' => $preset->id, 'version' => 1, 'matching_rules' => [], 'generation_policy' => [], 'blueprint_template' => ['sections' => []], 'lifecycle_status' => 'production']);
        $preset->forceFill(['current_version_id' => $version->id])->save();
        $actor = Admin::where('username', 'geoflow-system')->sole();
        $scope = ['knowledge_mode' => 'brand_auto', 'knowledge_brand_id' => $brand->id, 'knowledge_site_id' => $site->id, 'knowledge_directory_id' => $directory->id];
        $brief = app(ContentBriefQuickCreateService::class)->create(array_merge(['topic' => 'Linen dress care'], $scope), $preset, $actor);
        // Explicit storage keeps reserve tests independent of quick-create propagation.
        $brief->update($scope);
        $model = AiModel::create(['name' => 'Test', 'status' => 'active', 'model_type' => 'chat', 'api_url' => 'https://example.invalid', 'api_key' => 'test', 'model_id' => 'test']);
        $request = ['strategy_version_id' => $brief->strategyVersions()->sole()->id, 'blueprint_version_id' => $brief->blueprintVersions()->sole()->id, 'preset_version_id' => $version->id, 'ai_model_id' => $model->id, 'idempotency_key' => 'brand-test'];

        return compact('brief', 'request', 'actor', 'chunk', 'base', 'preset', 'scope');
    }

    public function test_reserve_selects_scoped_evidence_and_replay_preserves_snapshot(): void
    {
        extract($this->fixture());
        $service = app(ContentGenerationRequestService::class);
        $run = $service->reserve($brief, $request, $actor)['run'];
        $this->assertSame([$chunk->id], $run->evidence()->pluck('knowledge_chunk_id')->all());
        $this->assertSame('https://shop.test/care', $run->evidence()->sole()->evidence_snapshot['source_url']);
        $snapshot = $run->knowledge_retrieval_snapshot;
        $this->assertSame('Linen dress care uses cold water.', $snapshot['evidence'][0]['content']);
        $chunk->update(['content' => 'Changed']);
        $base->update(['generation_eligible' => false]);
        $replay = $service->reserve($brief, $request, $actor);
        $this->assertFalse($replay['created']);
        $this->assertSame($snapshot, $replay['run']->knowledge_retrieval_snapshot);
    }

    public function test_empty_evidence_rolls_back_run(): void
    {
        extract($this->fixture());
        $base->update(['generation_eligible' => false]);
        try {
            app(ContentGenerationRequestService::class)->reserve($brief, $request, $actor);
            $this->fail('Empty selection accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('knowledge_chunk_ids', $e->errors());
        }
        $this->assertSame(0, ContentGenerationRun::count());
        Queue::assertNothingPushed();
    }

    public function test_free_text_and_stale_explicit_chunks_are_rejected(): void
    {
        extract($this->fixture());
        foreach ([['evidence' => [['fact_id' => 'free', 'content' => 'Unverified']]], ['knowledge_chunk_ids' => [$chunk->id]]] as $extra) {
            $base->update(['generation_eligible' => false]);
            try {
                app(ContentGenerationRequestService::class)->reserve($brief, array_merge($request, $extra), $actor);
                $this->fail('Unsafe evidence accepted');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, ContentGenerationRun::count());
    }

    public function test_quick_create_copies_scope_and_language(): void
    {
        extract($this->fixture());
        $new = app(ContentBriefQuickCreateService::class)->create(array_merge(['topic' => 'Care', 'language_code' => 'fr'], $scope), $preset, $actor);
        $this->assertSame('brand_auto', $new->knowledge_mode);
        $this->assertSame($scope['knowledge_brand_id'], $new->knowledge_brand_id);
        $this->assertSame('fr', $new->language_code);
    }

    public function test_task_partial_edits_preserve_scope_and_legacy_switch_clears_it(): void
    {
        extract($this->fixture());
        $task = Task::create(array_merge(['name' => 'Task', 'status' => 'paused', 'knowledge_base_id' => $base->id], $scope));
        $service = app(TaskLifecycleService::class);
        $service->updateTask($task->id, ['name' => 'Renamed', 'knowledge_directory_id' => null]);
        $this->assertSame($scope['knowledge_brand_id'], $task->fresh()->knowledge_brand_id);
        $this->assertNull($task->fresh()->knowledge_directory_id);
        $this->assertNull($task->fresh()->knowledge_base_id);
        $service->updateTask($task->id, ['knowledge_mode' => 'legacy_manual']);
        $this->assertNull($task->fresh()->knowledge_brand_id);
    }

    public function test_quality_worker_uses_brand_retrieval_without_library_pivots(): void
    {
        extract($this->fixture());
        $library = TitleLibrary::create(['name' => 'Titles']);
        Title::create(['library_id' => $library->id, 'title' => 'Practical maintenance guide', 'keyword' => 'linen dress']);
        $task = Task::create(array_merge(['name' => 'Task', 'status' => 'active', 'schedule_enabled' => 1, 'title_library_id' => $library->id, 'ai_model_id' => $request['ai_model_id'], 'generation_engine' => 'quality_gated', 'prompt_preset_id' => $preset->id, 'article_limit' => 10, 'draft_limit' => 5], $scope));
        $result = app(WorkerExecutionService::class)->executeTask($task->id);
        $run = ContentGenerationRun::findOrFail($result['meta']['content_generation_run_id']);
        $this->assertSame([$chunk->id], $run->evidence()->pluck('knowledge_chunk_id')->all());
        $this->assertSame($scope['knowledge_brand_id'], $run->contentBrief->knowledge_brand_id);
    }

    public function test_direct_snapshot_rejects_foreign_scope_and_legacy_mixed_scope(): void
    {
        extract($this->fixture());
        $brand = KnowledgeBrand::create(['key' => 'other', 'name' => 'Other']);
        $site = KnowledgeSite::create(['brand_id' => $brand->id, 'key' => 'other', 'canonical_host' => 'other.test', 'role' => 'commerce-primary']);
        $directory = KnowledgeDirectory::create(['brand_id' => $brand->id, 'key' => 'other', 'path' => 'other', 'name' => 'Other']);
        $other = KnowledgeBase::create(['name' => 'Other', 'review_status' => 'reviewed', 'brand_id' => $brand->id, 'site_id' => $site->id, 'primary_directory_id' => $directory->id, 'language_code' => 'en', 'canonical_document_key' => 'other']);
        $foreign = KnowledgeChunk::create(['knowledge_base_id' => $other->id, 'chunk_index' => 0, 'content' => 'Other evidence']);
        foreach (['brand_auto', 'legacy_manual'] as $mode) {
            $brief->update(['knowledge_mode' => $mode]);
            $run = ContentGenerationRun::forceCreate(array_merge($request, ['content_brief_id' => $brief->id, 'status' => 'pending', 'idempotency_key' => $mode]));
            try {
                app(GenerationEvidenceSnapshotter::class)->snapshot($run, [$chunk->id, $foreign->id], [], [], $actor);
                $this->fail('Mixed scope accepted');
            } catch (ValidationException) {
                $this->assertSame(0, $run->evidence()->count());
            }
        }
    }

    public function test_legacy_brand_worker_persists_exact_audit_before_model_call(): void
    {
        extract($this->fixture());
        $library = TitleLibrary::create(['name' => 'Titles']);
        Title::create(['library_id' => $library->id, 'title' => 'Linen dress care']);
        $task = Task::create(array_merge(['name' => 'Task', 'status' => 'active', 'schedule_enabled' => 1, 'title_library_id' => $library->id, 'ai_model_id' => $request['ai_model_id'], 'generation_engine' => 'legacy', 'article_limit' => 10, 'draft_limit' => 5], $scope));
        $audit = TaskRun::create(['task_id' => $task->id, 'status' => 'running', 'meta' => ['payload' => ['test' => true]]]);
        $this->mock(ArticleContentGenerationService::class, function ($mock) use ($audit) {
            $mock->shouldReceive('generate')->once()->andReturnUsing(function ($model, $prompt) use ($audit) {
                $this->assertSame('Linen dress care uses cold water.', $audit->fresh()->meta['knowledge_retrieval_snapshot']['evidence'][0]['content']);
                $this->assertStringContainsString('Linen dress care uses cold water.', $prompt);
                throw new \RuntimeException('test-model-stopped');
            });
        });
        try {
            app(WorkerExecutionService::class)->executeTask($task->id, $audit->id);
            $this->fail('Model should stop');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('test-model-stopped', $e->getMessage());
        }
        $this->assertSame([$chunk->id], $audit->fresh()->meta['knowledge_retrieval_snapshot']['chunk_ids']);
        app(JobQueueService::class)->completeJob($audit->id, $task->id, null, 0, ['knowledge_retrieval_snapshot' => ['tampered']]);
        $this->assertSame([$chunk->id], $audit->fresh()->meta['knowledge_retrieval_snapshot']['chunk_ids']);
        try {
            app(WorkerExecutionService::class)->executeTask($task->id);
            $this->fail('Missing audit accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('TaskRun audit', $e->getMessage());
        }
    }

    public function test_retrieval_snapshot_cannot_be_replaced_or_added_after_generation(): void
    {
        extract($this->fixture());
        $run = app(ContentGenerationRequestService::class)->reserve($brief, $request, $actor)['run'];
        $snapshot = $run->knowledge_retrieval_snapshot;
        try {
            app(GenerationEvidenceSnapshotter::class)->snapshot($run, [$chunk->id], [], [], $actor);
            $this->fail('Snapshot overwritten');
        } catch (ValidationException) {
            $this->assertSame($snapshot, $run->fresh()->knowledge_retrieval_snapshot);
        }
        $later = ContentGenerationRun::forceCreate(array_merge($request, ['content_brief_id' => $brief->id, 'status' => 'generating', 'idempotency_key' => 'late']));
        try {
            app(GenerationEvidenceSnapshotter::class)->snapshot($later, [$chunk->id], [], [], $actor);
            $this->fail('Late snapshot accepted');
        } catch (ValidationException) {
            $this->assertNull($later->fresh()->knowledge_retrieval_snapshot);
        }
    }

    public function test_legacy_requests_keep_their_original_fingerprint_and_unowned_evidence(): void
    {
        extract($this->fixture());
        $brief->update(['knowledge_mode' => 'legacy_manual']);
        $base = KnowledgeBase::create(['name' => 'Historical', 'review_status' => 'reviewed']);
        $chunk = KnowledgeChunk::create(['knowledge_base_id' => $base->id, 'chunk_index' => 0, 'content' => 'Historical reviewed fact']);
        $request['knowledge_chunk_ids'] = [$chunk->id];
        $run = app(ContentGenerationRequestService::class)->reserve($brief, $request, $actor)['run'];
        $payload = ['content_brief_id' => $brief->id, 'strategy_version_id' => $request['strategy_version_id'], 'blueprint_version_id' => $request['blueprint_version_id'], 'preset_version_id' => $request['preset_version_id'], 'ai_model_id' => $request['ai_model_id'], 'knowledge_chunk_ids' => [$chunk->id], 'evidence' => []];
        $this->assertSame(hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), $run->request_fingerprint);
        $this->assertSame('Historical reviewed fact', $run->evidence()->sole()->evidence_snapshot['content']);
    }

    public function test_query_keeps_primary_keyword_ahead_of_long_topic_and_supports_titles(): void
    {
        $brief = new ContentBrief(['topic' => str_repeat('broad topic ', 100), 'core_question' => 'Care instructions?']);
        $brief->setRelation('primaryKeyword', new Keyword(['keyword' => 'linen dress']));
        $brief->setRelation('keywordCluster', null);
        $builder = app(BrandKnowledgeQueryBuilder::class);
        $this->assertStringStartsWith('linen dress ', $builder->forBrief($brief));
        $this->assertStringContainsString('Care instructions?', $builder->forBrief($brief));
        $this->assertSame('linen dress Washing How to wash?', $builder->forTitle(new Title(['keyword' => 'linen dress', 'title' => 'Washing', 'core_question' => 'How to wash?'])));
    }

    public function test_explicit_retrieval_keyword_is_not_diluted_by_generated_title_context(): void
    {
        $brief = new ContentBrief([
            'topic' => 'A long generated title about an unrelated product category',
            'core_question' => 'What should shoppers know about that generated title?',
            'asset_provenance' => ['retrieval_keyword' => 'LightInTheBox return policy and return process'],
        ]);

        $this->assertSame(
            'LightInTheBox return policy and return process',
            app(BrandKnowledgeQueryBuilder::class)->forBrief($brief),
        );
    }

    public function test_legacy_task_rejects_mixed_owned_and_unowned_libraries(): void
    {
        extract($this->fixture());
        $unowned = KnowledgeBase::create(['name' => 'Historical', 'review_status' => 'reviewed']);
        $library = TitleLibrary::create(['name' => 'Titles']);
        Title::create(['library_id' => $library->id, 'title' => 'Linen dress care']);
        $task = Task::create(['name' => 'Task', 'status' => 'active', 'schedule_enabled' => 1, 'title_library_id' => $library->id, 'ai_model_id' => $request['ai_model_id'], 'generation_engine' => 'quality_gated', 'prompt_preset_id' => $preset->id, 'article_limit' => 10, 'draft_limit' => 5]);
        $task->knowledgeBases()->sync([$base->id, $unowned->id]);
        $this->expectException(ValidationException::class);
        app(WorkerExecutionService::class)->executeTask($task->id);
    }

    public function test_task_create_persists_scope_and_clears_manual_library_bindings(): void
    {
        extract($this->fixture());
        $library = TitleLibrary::create(['name' => 'Titles']);
        app(TaskLifecycleService::class)->createTask(array_merge(['name' => 'Created brand task', 'status' => 'paused', 'title_library_id' => $library->id, 'ai_model_id' => $request['ai_model_id'], 'generation_engine' => 'quality_gated', 'prompt_preset_id' => $preset->id, 'knowledge_base_ids' => [$base->id]], $scope));
        $task = Task::where('name', 'Created brand task')->sole();
        $this->assertSame($scope['knowledge_brand_id'], $task->knowledge_brand_id);
        $this->assertSame('brand_auto', $task->knowledge_mode);
        $this->assertNull($task->knowledge_base_id);
        $this->assertSame(0, $task->knowledgeBases()->count());
    }

    public function test_legacy_snapshot_rechecks_actual_locked_chunk_parents_after_reparenting(): void
    {
        extract($this->fixture());
        $brief->update(['knowledge_mode' => 'legacy_manual']);
        $second = KnowledgeChunk::create(['knowledge_base_id' => $base->id, 'chunk_index' => 1, 'content' => 'Second reviewed fact']);
        $foreignBrand = KnowledgeBrand::create(['key' => 'foreign', 'name' => 'Foreign']);
        $foreignSite = KnowledgeSite::create(['brand_id' => $foreignBrand->id, 'key' => 'foreign', 'canonical_host' => 'foreign.test', 'role' => 'commerce-primary']);
        $foreignDirectory = KnowledgeDirectory::create(['brand_id' => $foreignBrand->id, 'key' => 'foreign', 'path' => 'foreign', 'name' => 'Foreign']);
        $foreignBase = KnowledgeBase::create(['name' => 'Foreign', 'review_status' => 'reviewed', 'brand_id' => $foreignBrand->id, 'site_id' => $foreignSite->id, 'primary_directory_id' => $foreignDirectory->id, 'language_code' => 'en', 'canonical_document_key' => 'foreign']);
        $run = ContentGenerationRun::forceCreate(array_merge($request, ['content_brief_id' => $brief->id, 'status' => 'pending']));
        $reparented = false;
        DB::listen(function ($query) use (&$reparented, $second, $foreignBase) {
            // A competing writer wins after the preliminary parent read, before chunk locks.
            if (! $reparented && str_starts_with($query->sql, 'select * from "knowledge_bases"')) {
                $reparented = true;
                KnowledgeChunk::whereKey($second->id)->update(['knowledge_base_id' => $foreignBase->id]);
            }
        });
        try {
            app(GenerationEvidenceSnapshotter::class)->snapshot($run, [$chunk->id, $second->id], [], [], $actor);
            $this->fail('Reparented mixed-scope evidence was copied');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('knowledge_chunk_ids', $exception->errors());
        }
        $this->assertTrue($reparented);
        $this->assertSame(0, $run->evidence()->count());
    }

    public function test_name_only_edit_preserves_scope_changed_before_transaction_lock(): void
    {
        extract($this->fixture());
        $task = Task::create(array_merge(['name' => 'Task', 'status' => 'paused'], $scope));
        $newDirectory = KnowledgeDirectory::create(['brand_id' => $scope['knowledge_brand_id'], 'key' => 'new', 'path' => 'new', 'name' => 'New']);
        $changed = false;
        Event::listen(TransactionBeginning::class, function () use (&$changed, $task, $newDirectory) {
            // Simulate a committed scope edit immediately before this updater locks the row.
            if (! $changed) {
                $changed = true;
                Task::whereKey($task->id)->update(['knowledge_directory_id' => $newDirectory->id]);
            }
        });
        app(TaskLifecycleService::class)->updateTask($task->id, ['name' => 'Renamed']);
        $this->assertTrue($changed);
        $this->assertSame('Renamed', $task->fresh()->name);
        $this->assertSame($newDirectory->id, $task->fresh()->knowledge_directory_id);
    }

    public function test_direct_snapshot_retries_a_deadlock_without_duplicate_evidence(): void
    {
        extract($this->fixture());
        $brief->update(['knowledge_mode' => 'legacy_manual']);
        $run = ContentGenerationRun::forceCreate(array_merge($request, ['content_brief_id' => $brief->id, 'status' => 'pending']));
        // Exercise the direct public boundary without RefreshDatabase's outer transaction.
        DB::commit();
        $attempts = 0;
        DB::listen(function ($query) use (&$attempts) {
            if (str_starts_with($query->sql, 'select * from "content_generation_runs"')) {
                $attempts++;
                if ($attempts === 1) {
                    throw new DeadlockException('deadlock detected');
                }
            }
        });
        try {
            app(GenerationEvidenceSnapshotter::class)->snapshot($run, [$chunk->id], [], [], $actor);
            $this->assertSame(2, $attempts);
            $this->assertSame(1, $run->evidence()->count());
            $this->assertSame('Linen dress care uses cold water.', $run->evidence()->sole()->evidence_snapshot['content']);
        } finally {
            RefreshDatabaseState::$migrated = false;
        }
    }

    public function test_quality_worker_retries_outer_transaction_without_orphan_briefs_or_consuming_title_twice(): void
    {
        extract($this->fixture());
        $library = TitleLibrary::create(['name' => 'Titles']);
        $title = Title::create(['library_id' => $library->id, 'title' => 'Linen dress care']);
        $task = Task::create(array_merge(['name' => 'Task', 'status' => 'active', 'schedule_enabled' => 1, 'title_library_id' => $library->id, 'ai_model_id' => $request['ai_model_id'], 'generation_engine' => 'quality_gated', 'prompt_preset_id' => $preset->id, 'article_limit' => 10, 'draft_limit' => 5], $scope));
        DB::commit();
        $interrupted = false;
        DB::listen(function ($query) use (&$interrupted) {
            if (! $interrupted && str_starts_with($query->sql, 'select * from "content_generation_runs"') && ! str_contains($query->sql, 'idempotency_key')) {
                $interrupted = true;
                throw new DeadlockException('deadlock detected');
            }
        });
        try {
            $result = app(WorkerExecutionService::class)->executeTask($task->id);
            $this->assertTrue($interrupted);
            $this->assertSame(1, ContentBrief::where('task_id', $task->id)->count());
            $this->assertSame(1, ContentGenerationRun::count());
            $this->assertSame(1, $title->fresh()->used_count);
            $run = ContentGenerationRun::findOrFail($result['meta']['content_generation_run_id']);
            $this->assertSame([$chunk->id], $run->evidence()->pluck('knowledge_chunk_id')->all());
        } finally {
            RefreshDatabaseState::$migrated = false;
        }
    }

    public static function lateEvidenceChanges(): array
    {
        $cases = [];
        foreach (['quality_gated', 'legacy'] as $engine) {
            foreach (['body', 'brand', 'directory', 'eligibility'] as $change) {
                $cases[$engine.' '.$change] = [$engine, $change];
            }
        }

        return $cases;
    }

    #[DataProvider('lateEvidenceChanges')]
    public function test_late_evidence_is_revalidated_and_rebuilt_under_selected_row_locks(string $engine, string $change): void
    {
        extract($this->fixture());
        $chunk->update(['content' => 'Unrelated inventory']);
        $library = TitleLibrary::create(['name' => 'Titles']);
        Title::create(['library_id' => $library->id, 'title' => 'Linen dress care']);
        $task = Task::create(array_merge(['name' => 'Task', 'status' => 'active', 'schedule_enabled' => 1, 'title_library_id' => $library->id, 'ai_model_id' => $request['ai_model_id'], 'generation_engine' => $engine, 'prompt_preset_id' => $preset->id, 'article_limit' => 10, 'draft_limit' => 5], $scope));
        $audit = TaskRun::create(['task_id' => $task->id, 'status' => 'running']);
        $foreignBrand = KnowledgeBrand::create(['key' => 'foreign', 'name' => 'Foreign']);
        $foreignSite = KnowledgeSite::create(['brand_id' => $foreignBrand->id, 'key' => 'foreign', 'canonical_host' => 'foreign.test', 'role' => 'commerce-primary']);
        $foreignDirectory = KnowledgeDirectory::create(['brand_id' => $foreignBrand->id, 'key' => 'foreign', 'path' => 'foreign', 'name' => 'Foreign']);
        $sibling = KnowledgeDirectory::create(['brand_id' => $scope['knowledge_brand_id'], 'key' => 'sibling', 'path' => 'sibling', 'name' => 'Sibling']);

        // SQLite cannot exercise PG row contention. Preserve lock intent as harmless SQL
        // comments, then inject committed writes only before the affected row is locked.
        $connection = DB::connection();
        $grammar = $connection->getQueryGrammar();
        $connection->setQueryGrammar(new class($connection) extends SQLiteGrammar
        {
            protected function compileLock(Builder $query, $value)
            {
                return $value === true ? '/* selected-row-lock */' : '';
            }
        });
        $lateBase = null;
        $lateChunk = null;
        $selected = false;
        $changed = false;
        $lockedBases = [];
        $lockedChunks = [];
        $connection->beforeExecuting(function ($sql) use (&$lateBase, &$lateChunk, &$selected, &$changed, &$lockedBases, &$lockedChunks, $base, $change, $foreignBrand, $foreignSite, $foreignDirectory, $sibling) {
            if ($lateBase === null && str_contains($sql, 'from "knowledge_chunks"') && str_contains($sql, 'limit 301')) {
                $lateBase = $base->replicate();
                $lateBase->canonical_document_key = 'late';
                $lateBase->save();
                $lateChunk = KnowledgeChunk::create(['knowledge_base_id' => $lateBase->id, 'chunk_index' => 0, 'content' => 'Linen dress care uses cold water.']);
            }
            $lockingEvidence = str_contains($sql, 'selected-row-lock') && (str_contains($sql, 'from "knowledge_bases"') || str_contains($sql, 'from "knowledge_chunks"'));
            $persistingAudit = str_starts_with($sql, 'update "task_runs"') || str_starts_with($sql, 'update "content_generation_runs"');
            if ($selected && ! $changed && ($lockingEvidence || $persistingAudit)) {
                $changed = true;
                if ($change === 'body') {
                    $this->assertNotContains($lateChunk->id, $lockedChunks, 'The interleaving must precede the chunk lock.');
                    $lateChunk->update(['content' => 'Linen dress care now uses lukewarm water.']);
                } else {
                    $this->assertNotContains($lateBase->id, $lockedBases, 'The interleaving must precede the parent lock.');
                    $lateBase->update(match ($change) {
                        'brand' => ['brand_id' => $foreignBrand->id, 'site_id' => $foreignSite->id, 'primary_directory_id' => $foreignDirectory->id],
                        'directory' => ['primary_directory_id' => $sibling->id],
                        'eligibility' => ['generation_eligible' => false],
                    });
                }
            }
        });
        DB::listen(function ($query) use (&$selected, &$lockedBases, &$lockedChunks) {
            if (str_contains($query->sql, 'from "knowledge_chunks"') && str_contains($query->sql, 'limit 301')) {
                $selected = true;
            }
            if (str_contains($query->sql, 'selected-row-lock') && (str_contains($query->sql, 'from "knowledge_bases"') || str_contains($query->sql, 'from "knowledge_chunks"'))) {
                $rows = $query->connection->select(str_replace('/* selected-row-lock */', '', $query->sql), $query->bindings);
                if (str_contains($query->sql, 'from "knowledge_bases"')) {
                    $lockedBases = array_merge($lockedBases, array_column($rows, 'id'));
                } else {
                    $lockedChunks = array_merge($lockedChunks, array_column($rows, 'id'));
                }
            }
        });
        $modelPrompt = null;
        $this->mock(ArticleContentGenerationService::class, function ($mock) use (&$modelPrompt) {
            $mock->shouldReceive('generate')->andReturnUsing(function ($model, $prompt) use (&$modelPrompt) {
                $modelPrompt = $prompt;
                throw new \RuntimeException('test-model-stopped');
            });
        });

        try {
            $failure = null;
            try {
                $result = app(WorkerExecutionService::class)->executeTask($task->id, $audit->id);
            } catch (ValidationException|\RuntimeException $exception) {
                $failure = $exception;
            }
            $this->assertTrue($changed);
            if ($change !== 'body') {
                $this->assertInstanceOf(ValidationException::class, $failure, 'Changed scope/eligibility must reject reservation.');
                $this->assertArrayHasKey('knowledge_chunk_ids', $failure->errors());
                $this->assertNull($modelPrompt);
                $this->assertSame(0, ContentGenerationRun::count());
                $this->assertArrayNotHasKey('knowledge_retrieval_snapshot', (array) $audit->fresh()->meta);
                Queue::assertNothingPushed();
            } else {
                if ($engine === 'quality_gated') {
                    $this->assertNull($failure);
                    $run = ContentGenerationRun::findOrFail($result['meta']['content_generation_run_id']);
                    $snapshot = $run->knowledge_retrieval_snapshot;
                    $this->assertSame('Linen dress care now uses lukewarm water.', $run->evidence()->sole()->evidence_snapshot['content']);
                } else {
                    $this->assertInstanceOf(\RuntimeException::class, $failure);
                    $this->assertStringContainsString('test-model-stopped', $failure->getMessage());
                    $this->assertStringContainsString('Linen dress care now uses lukewarm water.', $modelPrompt);
                    $snapshot = $audit->fresh()->meta['knowledge_retrieval_snapshot'];
                }
                $this->assertContains($lateBase->id, $lockedBases);
                $this->assertContains($lateChunk->id, $lockedChunks);
                $this->assertSame('Linen dress care now uses lukewarm water.', $snapshot['evidence'][0]['content']);
                $this->assertSame('automatic', $snapshot['evidence'][0]['selection_method']);
                $this->assertSame($engine === 'quality_gated'
                    ? 'Linen dress care What should a reader know before deciding on Linen dress care?'
                    : 'Linen dress care', $snapshot['query']);
            }
        } finally {
            $connection->setQueryGrammar($grammar);
        }
    }

    public function test_http_replay_survives_deleted_excluded_evidence_without_bypassing_guards(): void
    {
        extract($this->fixture());
        $excludedBase = $base->replicate();
        $excludedBase->canonical_document_key = 'excluded';
        $excludedBase->save();
        $excluded = KnowledgeChunk::create(['knowledge_base_id' => $excludedBase->id, 'chunk_index' => 0, 'content' => 'Linen dress care alternative.']);
        $request['knowledge_chunk_ids'] = [$chunk->id];
        $request['excluded_knowledge_chunk_ids'] = [$excluded->id];
        $url = route('admin.content-briefs.generations.store', $brief);
        $this->actingAs($actor, 'admin')->post($url, $request)->assertSessionHasNoErrors();
        $run = ContentGenerationRun::sole();
        $snapshot = $run->knowledge_retrieval_snapshot;
        $excluded->delete();

        $this->post($url, $request)->assertRedirect(route('admin.content-generations.show', $run))->assertSessionHasNoErrors();
        $this->assertSame($snapshot, $run->fresh()->knowledge_retrieval_snapshot);
        $this->post($url, array_merge($request, ['excluded_knowledge_chunk_ids' => []]))->assertSessionHasErrors('idempotency_key');
        $this->post($url, array_merge($request, ['excluded_knowledge_chunk_ids' => ['invalid']]))->assertSessionHasErrors('excluded_knowledge_chunk_ids.0');
        $this->post($url, array_merge($request, ['idempotency_key' => 'fresh-request']))->assertSessionHasErrors('excluded_knowledge_chunk_ids.0');
        $otherActor = Admin::create(['username' => 'other-super', 'email' => 'other@example.test', 'password' => 'password', 'status' => 'active', 'role' => 'super_admin']);
        $this->actingAs($otherActor, 'admin')->post($url, $request)->assertSessionHasErrors('idempotency_key');
        $this->assertSame(1, ContentGenerationRun::count());
    }

    public static function incompatibleRetryConfigurations(): array
    {
        return [
            'mode change' => [['knowledge_mode' => 'legacy_manual']],
            'engine change' => [['generation_engine' => 'quality_gated']],
            'both changes' => [['knowledge_mode' => 'legacy_manual', 'generation_engine' => 'quality_gated']],
        ];
    }

    #[DataProvider('incompatibleRetryConfigurations')]
    public function test_failed_brand_model_retry_rejects_mode_or_engine_changes_before_routing(array $changes): void
    {
        extract($this->fixture());
        $library = TitleLibrary::create(['name' => 'Titles']);
        $title = Title::create(['library_id' => $library->id, 'title' => 'Linen dress care']);
        $task = Task::create(array_merge(['name' => 'Task', 'status' => 'active', 'schedule_enabled' => 1, 'title_library_id' => $library->id, 'ai_model_id' => $request['ai_model_id'], 'generation_engine' => 'legacy', 'prompt_preset_id' => $preset->id, 'article_limit' => 10, 'draft_limit' => 5], $scope));
        $audit = TaskRun::create(['task_id' => $task->id, 'status' => 'running']);
        $calls = 0;
        $this->mock(ArticleContentGenerationService::class, function ($mock) use (&$calls) {
            $mock->shouldReceive('generate')->andReturnUsing(function () use (&$calls) {
                $calls++;
                throw new \RuntimeException('test-model-stopped');
            });
        });
        try {
            app(WorkerExecutionService::class)->executeTask($task->id, $audit->id);
            $this->fail('The fake model must fail.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('test-model-stopped', $exception->getMessage());
        }
        $snapshot = $audit->fresh()->meta['knowledge_retrieval_snapshot'];
        $task->update($changes);
        try {
            app(WorkerExecutionService::class)->executeTask($task->id, $audit->id);
            $this->fail('An incompatible audited retry was accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('enqueue a new run', $exception->getMessage());
        }
        $this->assertSame(1, $calls);
        $this->assertSame($snapshot, $audit->fresh()->meta['knowledge_retrieval_snapshot']);
        $this->assertSame(0, ContentGenerationRun::count());
        $this->assertSame(0, $title->fresh()->used_count);
        Queue::assertNothingPushed();
    }
}
