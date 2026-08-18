<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\ContentBrief;
use App\Models\ContentGenerationRun;
use App\Models\ContentGenerationRunEvidence;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use App\Models\Task;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\WorkerExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class WorkerExecutionServiceQualityGatedTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_quality_gated_task_creates_a_brief_and_reserves_a_run_instead_of_an_article(): void
    {
        config(['geoflow.content_strategy_enabled' => true, 'geoflow.modular_prompts_enabled' => true, 'geoflow.generation_quality_gate_enabled' => true]);
        // The `geoflow-system` Admin is permanently seeded by
        // `2026_08_15_000400_seed_geoflow_system_admin.php`, which RefreshDatabase re-runs
        // for every test — so it already exists here and must not be created again.
        self::assertTrue(Admin::query()->where('username', 'geoflow-system')->exists());
        $preset = PromptPreset::query()->create([
            'preset_key' => 'test.worker-quality-gated', 'display_name' => 'Test', 'is_system' => false, 'is_enabled' => true,
        ]);
        $version = PromptPresetVersion::query()->forceCreate([
            'prompt_preset_id' => $preset->id, 'version' => 1, 'matching_rules' => [], 'generation_policy' => [],
            'blueprint_template' => [
                'target_length' => 1800,
                'sections' => [[
                    'key' => 'answer', 'heading' => 'Direct answer', 'purpose' => 'Answer it.',
                    'questions_to_answer' => ['{{core_question}}'], 'minimum_words' => 100,
                    'cta_allowed' => false, 'required_fact_ids' => [],
                ]],
                'required_entities' => [], 'show_references_section' => false,
            ],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
        ]);
        $preset->forceFill(['current_version_id' => $version->id])->save();
        $aiModel = AiModel::query()->create([
            'name' => 'Test model', 'status' => 'active', 'model_type' => 'chat',
            'api_url' => 'https://example.invalid', 'api_key' => 'test', 'model_id' => 'test-model',
        ]);
        $library = TitleLibrary::query()->create(['name' => 'Test library']);
        $title = Title::query()->create(['library_id' => $library->id, 'title' => 'How to Choose Running Shoes', 'core_question' => 'What matters most?']);
        $task = Task::query()->create([
            'name' => 'Quality gated task', 'title_library_id' => $library->id, 'ai_model_id' => $aiModel->id,
            'generation_engine' => 'quality_gated', 'prompt_preset_id' => $preset->id,
            'status' => 'active', 'schedule_enabled' => 1, 'article_limit' => 10, 'draft_limit' => 5,
        ]);

        $result = $this->app->make(WorkerExecutionService::class)->executeTask((int) $task->id);

        $this->assertNull($result['article_id']);
        $this->assertSame('generate_quality_gated_draft', $result['meta']['action']);
        $this->assertTrue(ContentGenerationRun::query()->whereKey($result['meta']['content_generation_run_id'])->exists());
        $this->assertSame(1, $title->fresh()->used_count);
    }

    /**
     * C1: the Task's knowledge bases must reach the generation run as evidence.
     * Without this the model is told "use only supplied, reviewed evidence" while
     * being handed none at all.
     */
    public function test_reserves_the_run_with_the_tasks_reviewed_knowledge_chunks_as_evidence(): void
    {
        $context = $this->qualityGatedTask();

        $reviewedChunk = $this->knowledgeChunkFor($context['task'], 'reviewed');

        $result = $this->app->make(WorkerExecutionService::class)->executeTask((int) $context['task']->id);

        $runId = (int) $result['meta']['content_generation_run_id'];
        $evidenceChunkIds = ContentGenerationRunEvidence::query()
            ->where('content_generation_run_id', $runId)
            ->pluck('knowledge_chunk_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $this->assertContains((int) $reviewedChunk->id, $evidenceChunkIds);
    }

    /**
     * C1 (negative half): only knowledge bases that actually passed review may be
     * used as evidence — mirroring `LitbQuarterlyGenerationCoordinator`'s
     * `review_status = 'reviewed'` convention.
     */
    public function test_does_not_pass_unreviewed_knowledge_chunks_as_evidence(): void
    {
        $context = $this->qualityGatedTask();

        $unreviewedChunk = $this->knowledgeChunkFor($context['task'], 'pending');

        $result = $this->app->make(WorkerExecutionService::class)->executeTask((int) $context['task']->id);

        $runId = (int) $result['meta']['content_generation_run_id'];
        $evidenceChunkIds = ContentGenerationRunEvidence::query()
            ->where('content_generation_run_id', $runId)
            ->pluck('knowledge_chunk_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $this->assertNotContains((int) $unreviewedChunk->id, $evidenceChunkIds);
    }

    /**
     * I6: `reserve()` failing must roll back the Brief/Strategy/Blueprint that
     * `ContentBriefQuickCreateService` committed, otherwise a misconfigured Task
     * leaves a fresh orphan brief behind on every single scheduler tick.
     */
    public function test_a_failed_reservation_leaves_no_orphaned_brief_behind(): void
    {
        $context = $this->qualityGatedTask();
        // An inactive model makes `reserve()` throw *after* the brief was created.
        $context['ai_model']->forceFill(['status' => 'inactive'])->save();

        try {
            $this->app->make(WorkerExecutionService::class)->executeTask((int) $context['task']->id);
            $this->fail('Expected the reservation to fail for an inactive AI model.');
        } catch (\Throwable) {
            // The failure itself is the precondition under test.
        }

        $this->assertSame(0, ContentBrief::query()->where('task_id', $context['task']->id)->count());
        $this->assertSame(0, ContentGenerationRun::query()->count());
        // The title must not be consumed either, so the next tick retries cleanly.
        $this->assertSame(0, (int) $context['title']->fresh()->used_count);
    }

    /**
     * I6: a deterministic idempotency key closes the queue-retry duplicate window.
     */
    public function test_reserves_the_run_with_a_deterministic_idempotency_key(): void
    {
        $context = $this->qualityGatedTask();

        $result = $this->app->make(WorkerExecutionService::class)->executeTask((int) $context['task']->id);

        $run = ContentGenerationRun::query()->findOrFail($result['meta']['content_generation_run_id']);
        $this->assertSame(
            'task:'.$context['task']->id.':title:'.$context['title']->id.':use:0',
            $run->idempotency_key
        );
    }

    /**
     * I1: `needs_evidence` / `model_quality_failed` / `manual_review_required` are
     * terminal-until-a-human-acts states with no automated release path. Counting
     * them against `draft_limit` permanently deadlocks the Task.
     */
    #[DataProvider('humanBlockedRunStatuses')]
    public function test_human_blocked_runs_do_not_permanently_occupy_the_draft_pool(string $status): void
    {
        $context = $this->qualityGatedTask();
        $context['task']->forceFill(['draft_limit' => 1])->save();

        // First tick produces one run, filling the single draft slot.
        $first = $this->app->make(WorkerExecutionService::class)->executeTask((int) $context['task']->id);
        ContentGenerationRun::query()
            ->whereKey($first['meta']['content_generation_run_id'])
            ->update(['status' => $status]);

        Title::query()->create([
            'library_id' => $context['library']->id, 'title' => 'A second topic', 'core_question' => 'And?',
        ]);

        // With the run parked in a human-blocked state the slot must be free again.
        $second = $this->app->make(WorkerExecutionService::class)->executeTask((int) $context['task']->id);

        $this->assertSame('generate_quality_gated_draft', $second['meta']['action']);
    }

    /** @return array<string, array{string}> */
    public static function humanBlockedRunStatuses(): array
    {
        return [
            'needs_evidence' => [ContentGenerationRun::STATUS_NEEDS_EVIDENCE],
            'model_quality_failed' => [ContentGenerationRun::STATUS_MODEL_QUALITY_FAILED],
            'manual_review_required' => [ContentGenerationRun::STATUS_MANUAL_REVIEW_REQUIRED],
        ];
    }

    /**
     * I1 (guard): genuinely in-flight runs must still consume a slot, otherwise the
     * fix would let a Task generate without bound.
     */
    public function test_in_flight_runs_still_occupy_the_draft_pool(): void
    {
        $context = $this->qualityGatedTask();
        $context['task']->forceFill(['draft_limit' => 1])->save();

        $this->app->make(WorkerExecutionService::class)->executeTask((int) $context['task']->id);
        Title::query()->create([
            'library_id' => $context['library']->id, 'title' => 'A second topic', 'core_question' => 'And?',
        ]);

        $second = $this->app->make(WorkerExecutionService::class)->executeTask((int) $context['task']->id);

        $this->assertSame('noop', $second['meta']['action']);
        $this->assertStringContainsString('草稿池已满', (string) $second['meta']['reason']);
    }

    /**
     * @return array{task: Task, title: Title, library: TitleLibrary, ai_model: AiModel, preset: PromptPreset}
     */
    private function qualityGatedTask(): array
    {
        config([
            'geoflow.content_strategy_enabled' => true,
            'geoflow.modular_prompts_enabled' => true,
            'geoflow.generation_quality_gate_enabled' => true,
        ]);
        self::assertTrue(Admin::query()->where('username', 'geoflow-system')->exists());

        $preset = PromptPreset::query()->create([
            'preset_key' => 'test.worker-quality-gated-'.uniqid(), 'display_name' => 'Test',
            'is_system' => false, 'is_enabled' => true,
        ]);
        $version = PromptPresetVersion::query()->forceCreate([
            'prompt_preset_id' => $preset->id, 'version' => 1, 'matching_rules' => [], 'generation_policy' => [],
            'blueprint_template' => [
                'target_length' => 1800,
                'sections' => [[
                    'key' => 'answer', 'heading' => 'Direct answer', 'purpose' => 'Answer it.',
                    'questions_to_answer' => ['{{core_question}}'], 'minimum_words' => 100,
                    'cta_allowed' => false, 'required_fact_ids' => [],
                ]],
                'required_entities' => [], 'show_references_section' => false,
            ],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
        ]);
        $preset->forceFill(['current_version_id' => $version->id])->save();

        $aiModel = AiModel::query()->create([
            'name' => 'Test model', 'status' => 'active', 'model_type' => 'chat',
            'api_url' => 'https://example.invalid', 'api_key' => 'test', 'model_id' => 'test-model',
        ]);
        $library = TitleLibrary::query()->create(['name' => 'Test library']);
        $title = Title::query()->create([
            'library_id' => $library->id, 'title' => 'How to Choose Running Shoes',
            'core_question' => 'What matters most?',
        ]);
        $task = Task::query()->create([
            'name' => 'Quality gated task', 'title_library_id' => $library->id, 'ai_model_id' => $aiModel->id,
            'generation_engine' => 'quality_gated', 'prompt_preset_id' => $preset->id,
            'status' => 'active', 'schedule_enabled' => 1, 'article_limit' => 10, 'draft_limit' => 5,
        ]);

        return ['task' => $task, 'title' => $title, 'library' => $library, 'ai_model' => $aiModel, 'preset' => $preset];
    }

    private function knowledgeChunkFor(Task $task, string $reviewStatus): KnowledgeChunk
    {
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => 'Task knowledge '.uniqid(),
            'content' => 'Product facts the article may cite.',
            'review_status' => $reviewStatus,
        ]);
        DB::table('task_knowledge_bases')->insert([
            'task_id' => (int) $task->id,
            'knowledge_base_id' => (int) $knowledgeBase->id,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return KnowledgeChunk::query()->create([
            'knowledge_base_id' => $knowledgeBase->id,
            'chunk_index' => 0,
            'content' => 'Reviewed evidence about running shoes.',
            'content_hash' => hash('sha256', uniqid('', true)),
        ]);
    }
}
