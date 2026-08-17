<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\ContentGenerationRun;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use App\Models\Task;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\WorkerExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
