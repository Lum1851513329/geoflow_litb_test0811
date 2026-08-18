<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Category;
use App\Models\Prompt;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use App\Models\Task;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Services\GeoFlow\TaskLifecycleService;
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

    /**
     * 创建一个"current_version_id 已配置且带 blueprint_template 的生产态版本"预设，
     * 供质量门禁引擎场景使用；quality_gated 校验通过所必需的最小可用预设。
     */
    private function makePresetWithProductionBlueprint(string $key = 'test.task-engine'): PromptPreset
    {
        $preset = PromptPreset::query()->create(['preset_key' => $key, 'display_name' => 'Test', 'is_system' => false, 'is_enabled' => true]);
        $version = PromptPresetVersion::query()->forceCreate([
            'prompt_preset_id' => $preset->id, 'version' => 1, 'matching_rules' => [], 'generation_policy' => [],
            'blueprint_template' => ['target_length' => 1800, 'sections' => []],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
        ]);
        $preset->forceFill(['current_version_id' => $version->id])->save();

        return $preset;
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

    /**
     * 回归用例：TaskController::store()/update() 并非唯一入口——API 侧
     * App\Http\Controllers\Api\V1\TaskController::store()/update() 直接调用
     * TaskLifecycleService::createTask()/updateTask()，且 tasks 相关路由没有挂
     * EnsureGeoFlowFeatureEnabled 网关。因此 quality_gated 引擎的两项前置条件——
     * 三个 geoflow.* 流水线开关全部开启、预设有一个带 blueprint_template 的生产态
     * 当前版本——必须由 TaskLifecycleService::normalizeTaskInput() 自身强制执行，
     * 不能依赖 TaskController（Blade 后台）层已经做过。这里绕开两个控制器，
     * 直接调用服务层，模拟"任何拥有 tasks:write scope 的 API 调用方"。
     */
    public function test_service_layer_rejects_quality_gated_task_creation_when_pipeline_flags_are_disabled(): void
    {
        config([
            'geoflow.content_strategy_enabled' => false,
            'geoflow.modular_prompts_enabled' => false,
            'geoflow.generation_quality_gate_enabled' => false,
        ]);

        Category::query()->create(['name' => '科技资讯', 'slug' => 'tech']);
        $library = TitleLibrary::query()->create(['name' => 'Test library']);
        $aiModel = $this->makeAiModel();
        // 预设本身完全合格（有生产态版本 + blueprint_template）——用来隔离验证：
        // 就算预设没问题，流水线开关关闭时服务层依然必须拒绝。
        $preset = $this->makePresetWithProductionBlueprint();

        $service = app(TaskLifecycleService::class);

        try {
            $service->createTask([
                'name' => 'API bypass test', 'title_library_id' => $library->id, 'ai_model_id' => $aiModel->id,
                'status' => 'paused', 'generation_engine' => 'quality_gated', 'prompt_preset_id' => $preset->id,
            ]);
            $this->fail('Expected ApiException was not thrown.');
        } catch (ApiException $exception) {
            $this->assertArrayHasKey('generation_engine', $exception->getDetails()['field_errors'] ?? []);
        }

        $this->assertDatabaseMissing('tasks', ['name' => 'API bypass test']);
    }

    public function test_service_layer_rejects_quality_gated_task_creation_when_preset_lacks_a_production_blueprint(): void
    {
        config([
            'geoflow.content_strategy_enabled' => true,
            'geoflow.modular_prompts_enabled' => true,
            'geoflow.generation_quality_gate_enabled' => true,
        ]);

        Category::query()->create(['name' => '科技资讯', 'slug' => 'tech']);
        $library = TitleLibrary::query()->create(['name' => 'Test library']);
        $aiModel = $this->makeAiModel();
        // 复现审阅者报告的场景：预设存在，但没有 current_version_id（没有任何版本、
        // 更没有 blueprint_template）。
        $presetWithoutVersion = PromptPreset::query()->create([
            'preset_key' => 'test.task-engine-no-version', 'display_name' => 'No Version', 'is_system' => false, 'is_enabled' => true,
        ]);

        $service = app(TaskLifecycleService::class);

        try {
            $service->createTask([
                'name' => 'API bypass test 2', 'title_library_id' => $library->id, 'ai_model_id' => $aiModel->id,
                'status' => 'paused', 'generation_engine' => 'quality_gated', 'prompt_preset_id' => $presetWithoutVersion->id,
            ]);
            $this->fail('Expected ApiException was not thrown.');
        } catch (ApiException $exception) {
            $this->assertArrayHasKey('prompt_preset_id', $exception->getDetails()['field_errors'] ?? []);
        }

        $this->assertDatabaseMissing('tasks', ['name' => 'API bypass test 2']);
    }

    /**
     * 回归用例：局部更新只提交 generation_engine=legacy、不重发 prompt_preset_id，
     * 之前遗留的预设引用也必须被清空，而不是原样保留在库里。
     */
    public function test_switching_a_task_back_to_legacy_via_partial_update_nulls_the_stale_prompt_preset_id(): void
    {
        config([
            'geoflow.content_strategy_enabled' => true,
            'geoflow.modular_prompts_enabled' => true,
            'geoflow.generation_quality_gate_enabled' => true,
        ]);

        Category::query()->create(['name' => '科技资讯', 'slug' => 'tech']);
        $library = TitleLibrary::query()->create(['name' => 'Test library']);
        $aiModel = $this->makeAiModel();
        $preset = $this->makePresetWithProductionBlueprint();

        $service = app(TaskLifecycleService::class);
        $created = $service->createTask([
            'name' => 'Stale preset test', 'title_library_id' => $library->id, 'ai_model_id' => $aiModel->id,
            'status' => 'paused', 'generation_engine' => 'quality_gated', 'prompt_preset_id' => $preset->id,
        ]);
        $taskId = (int) $created['id'];

        $this->assertDatabaseHas('tasks', ['id' => $taskId, 'generation_engine' => 'quality_gated', 'prompt_preset_id' => $preset->id]);

        // 局部更新：只切换 generation_engine，故意不重发 prompt_preset_id。
        $service->updateTask($taskId, ['generation_engine' => 'legacy']);

        $task = Task::query()->whereKey($taskId)->firstOrFail();
        $this->assertSame('legacy', $task->generation_engine);
        $this->assertNull($task->prompt_preset_id);
    }

    /**
     * 对照用例：完全不提交 generation_engine 的局部更新，不应触碰已存在的
     * prompt_preset_id——这是"未提及字段即保持不变"的常规局部更新语义,
     * 用来确认上面的"清空过期引用"修复没有过度触发。
     */
    public function test_partial_update_that_never_mentions_generation_engine_leaves_prompt_preset_id_untouched(): void
    {
        config([
            'geoflow.content_strategy_enabled' => true,
            'geoflow.modular_prompts_enabled' => true,
            'geoflow.generation_quality_gate_enabled' => true,
        ]);

        Category::query()->create(['name' => '科技资讯', 'slug' => 'tech']);
        $library = TitleLibrary::query()->create(['name' => 'Test library']);
        $aiModel = $this->makeAiModel();
        $preset = $this->makePresetWithProductionBlueprint();

        $service = app(TaskLifecycleService::class);
        $created = $service->createTask([
            'name' => 'Untouched preset test', 'title_library_id' => $library->id, 'ai_model_id' => $aiModel->id,
            'status' => 'paused', 'generation_engine' => 'quality_gated', 'prompt_preset_id' => $preset->id,
        ]);
        $taskId = (int) $created['id'];

        // 局部更新：只改任务名，完全不提 generation_engine。
        $service->updateTask($taskId, ['name' => 'Renamed']);

        $task = Task::query()->whereKey($taskId)->firstOrFail();
        $this->assertSame('quality_gated', $task->generation_engine);
        $this->assertSame($preset->id, $task->prompt_preset_id);
    }

    /**
     * I5 回归用例：`TaskController::buildTaskPayload()` 之前无条件塞入
     * `'generation_engine' => $payload['generation_engine'] ?? 'legacy'`。但质量门禁
     * 三开关关闭时表单根本不渲染引擎选择器，于是对一个已存在的 quality_gated 任务做
     * 任何编辑（哪怕只是改个名字）都不会提交 generation_engine，控制器却会补一个
     * 'legacy' 默认值，`normalizeTaskInput()` 随即把 prompt_preset_id 清空——在管理员
     * 毫无察觉的情况下摧毁该任务的质量门禁配置。
     *
     * 这里走真实的 HTTP 编辑路径（而不是直接调服务层），因为出问题的正是控制器那一层。
     */
    public function test_editing_a_quality_gated_task_with_the_flags_off_preserves_its_engine_and_preset(): void
    {
        config([
            'geoflow.content_strategy_enabled' => true,
            'geoflow.modular_prompts_enabled' => true,
            'geoflow.generation_quality_gate_enabled' => true,
        ]);

        $admin = $this->makeAdmin('task-engine-tester-i5');
        $category = Category::query()->create(['name' => '科技资讯', 'slug' => 'tech']);
        $library = TitleLibrary::query()->create(['name' => 'Test library']);
        $aiModel = $this->makeAiModel();
        $preset = $this->makePresetWithProductionBlueprint();
        $prompt = Prompt::query()->create(['name' => '正文提示词', 'type' => 'content', 'content' => '请写 {{title}}']);

        $created = app(TaskLifecycleService::class)->createTask([
            'name' => 'Quality gated task', 'title_library_id' => $library->id, 'ai_model_id' => $aiModel->id,
            'status' => 'paused', 'generation_engine' => 'quality_gated', 'prompt_preset_id' => $preset->id,
        ]);
        $taskId = (int) $created['id'];

        // 关掉开关：此时编辑页不再渲染引擎选择器，提交的表单里没有 generation_engine。
        config([
            'geoflow.content_strategy_enabled' => false,
            'geoflow.modular_prompts_enabled' => false,
            'geoflow.generation_quality_gate_enabled' => false,
        ]);

        $task = Task::query()->whereKey($taskId)->firstOrFail();
        $revision = app(DistributionOrchestrator::class)->taskRevision($task);

        $response = $this->actingAs($admin, 'admin')->put(route('admin.tasks.update', ['taskId' => $taskId]), [
            // 只改名字。generation_engine / prompt_preset_id 都没有出现在提交里，
            // 与开关关闭时表单的实际提交内容一致。引擎选择器不渲染时，表单退回旧版
            // “内容配置”区块，因此 prompt_id 会被一并提交（required_unless 规则）。
            'task_name' => 'Renamed while flags are off',
            'title_library_id' => $library->id,
            'prompt_id' => $prompt->id,
            'ai_model_id' => $aiModel->id,
            'fixed_category_id' => $category->id,
            'status' => 'paused',
            'publish_scope' => 'local_only',
            'article_limit' => 3,
            'draft_limit' => 2,
            'publish_interval' => 60,
            'category_mode' => 'fixed',
            'model_selection_mode' => 'fixed',
            'task_revision' => $revision,
        ]);

        $response->assertSessionHasNoErrors();

        $task = Task::query()->whereKey($taskId)->firstOrFail();
        $this->assertSame('Renamed while flags are off', $task->name);
        $this->assertSame('quality_gated', $task->generation_engine, 'The engine must survive an edit made while the selector was not rendered.');
        $this->assertSame($preset->id, $task->prompt_preset_id, 'The preset binding must not be silently destroyed.');
    }
}
