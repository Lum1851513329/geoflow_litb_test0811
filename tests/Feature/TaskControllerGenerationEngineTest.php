<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Category;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use App\Models\TitleLibrary;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 14: TaskController/TaskLifecycleService 支持在创建任务时选择
 * generation_engine=quality_gated（质量门禁生成引擎）。
 */
final class TaskControllerGenerationEngineTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(string $username): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => 'Task Engine Tester',
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    private function makeAiModel(): AiModel
    {
        return AiModel::query()->create([
            'name' => '测试模型',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'),
            'model_id' => 'test-model',
            'model_type' => 'chat',
            'api_url' => 'https://api.example.com/v1',
            'status' => 'active',
        ]);
    }

    public function test_creating_a_quality_gated_task_requires_the_pipeline_to_be_enabled(): void
    {
        config(['geoflow.content_strategy_enabled' => false]);

        $admin = $this->makeAdmin('task-engine-tester');
        Category::query()->create(['name' => '科技资讯', 'slug' => 'tech']);
        $library = TitleLibrary::query()->create(['name' => 'Test library']);
        $aiModel = $this->makeAiModel();

        $response = $this->actingAs($admin, 'admin')->post(route('admin.tasks.store'), [
            'task_name' => 'Test', 'title_library_id' => $library->id, 'ai_model_id' => $aiModel->id,
            'status' => 'paused', 'generation_engine' => 'quality_gated',
        ]);

        $response->assertSessionHasErrors('generation_engine');
    }

    public function test_creating_a_quality_gated_task_succeeds_with_a_valid_preset(): void
    {
        config([
            'geoflow.content_strategy_enabled' => true,
            'geoflow.modular_prompts_enabled' => true,
            'geoflow.generation_quality_gate_enabled' => true,
        ]);

        $admin = $this->makeAdmin('task-engine-tester-2');
        Category::query()->create(['name' => '科技资讯', 'slug' => 'tech']);
        $library = TitleLibrary::query()->create(['name' => 'Test library']);
        $aiModel = $this->makeAiModel();
        $preset = PromptPreset::query()->create(['preset_key' => 'test.task-engine', 'display_name' => 'Test', 'is_system' => false, 'is_enabled' => true]);
        $version = PromptPresetVersion::query()->forceCreate([
            'prompt_preset_id' => $preset->id, 'version' => 1, 'matching_rules' => [], 'generation_policy' => [],
            'blueprint_template' => ['target_length' => 1800, 'sections' => []],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
        ]);
        $preset->forceFill(['current_version_id' => $version->id])->save();

        $response = $this->actingAs($admin, 'admin')->post(route('admin.tasks.store'), [
            'task_name' => 'Test', 'title_library_id' => $library->id, 'ai_model_id' => $aiModel->id,
            'status' => 'paused', 'generation_engine' => 'quality_gated', 'prompt_preset_id' => $preset->id,
        ]);

        $response->assertSessionDoesntHaveErrors();
        $response->assertRedirect(route('admin.tasks.index'));

        $this->assertDatabaseHas('tasks', [
            'name' => 'Test',
            'generation_engine' => 'quality_gated',
            'prompt_preset_id' => $preset->id,
            'prompt_id' => null,
        ]);
    }

    public function test_creating_a_legacy_task_still_requires_prompt_id(): void
    {
        $admin = $this->makeAdmin('task-engine-tester-3');
        Category::query()->create(['name' => '科技资讯', 'slug' => 'tech']);
        $library = TitleLibrary::query()->create(['name' => 'Test library']);
        $aiModel = $this->makeAiModel();

        $response = $this->actingAs($admin, 'admin')->post(route('admin.tasks.store'), [
            'task_name' => 'Test', 'title_library_id' => $library->id, 'ai_model_id' => $aiModel->id,
            'status' => 'paused',
        ]);

        $response->assertSessionHasErrors('prompt_id');
    }
}
