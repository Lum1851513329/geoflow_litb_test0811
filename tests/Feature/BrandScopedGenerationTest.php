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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
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
}
