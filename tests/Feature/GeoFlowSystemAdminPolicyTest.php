<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\ContentBlueprintVersion;
use App\Models\ContentBrief;
use App\Models\ContentGenerationRun;
use App\Models\ContentStrategyVersion;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class GeoFlowSystemAdminPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_a_system_admin_that_is_not_a_superadmin(): void
    {
        $this->artisan('migrate');

        $systemAdmin = Admin::query()->where('username', 'geoflow-system')->firstOrFail();
        $this->assertFalse($systemAdmin->isSuperAdmin());
        $this->assertSame('active', $systemAdmin->status);
    }

    public function test_any_active_admin_can_view_a_task_driven_brief_they_did_not_create(): void
    {
        $this->artisan('migrate');
        $systemAdmin = Admin::query()->where('username', 'geoflow-system')->firstOrFail();
        $regularAdmin = Admin::query()->create([
            'username' => 'regular-viewer', 'password' => bcrypt(bin2hex(random_bytes(32))),
            'role' => 'admin', 'status' => 'active',
        ]);
        $task = Task::query()->create(['name' => 'Test', 'status' => 'paused']);
        $brief = ContentBrief::query()->create([
            'topic' => 'Test', 'market_code' => 'US', 'language_code' => 'en', 'audience' => 'x',
            'core_question' => 'x', 'business_goal' => 'x', 'content_type' => 'x',
            'status' => ContentBrief::STATUS_STRATEGY_READY, 'created_by_admin_id' => $systemAdmin->id,
        ]);
        // `task_id` is not in ContentBrief::$fillable (mass assignment is intentionally
        // restricted to human-editable fields), so set it directly and save.
        $brief->task_id = $task->id;
        $brief->save();

        $this->assertTrue(Gate::forUser($regularAdmin)->allows('view', $brief));
        $this->assertFalse(Gate::forUser($regularAdmin)->allows('update', $brief));
    }

    public function test_a_regular_admin_still_cannot_view_a_manually_created_brief_owned_by_someone_else(): void
    {
        $this->artisan('migrate');
        $owner = Admin::query()->create([
            'username' => 'owner-admin', 'password' => bcrypt(bin2hex(random_bytes(32))),
            'role' => 'admin', 'status' => 'active',
        ]);
        $regularAdmin = Admin::query()->create([
            'username' => 'other-admin', 'password' => bcrypt(bin2hex(random_bytes(32))),
            'role' => 'admin', 'status' => 'active',
        ]);
        $brief = ContentBrief::query()->create([
            'topic' => 'Test', 'market_code' => 'US', 'language_code' => 'en', 'audience' => 'x',
            'core_question' => 'x', 'business_goal' => 'x', 'content_type' => 'x',
            'status' => ContentBrief::STATUS_STRATEGY_READY, 'created_by_admin_id' => $owner->id,
        ]);

        $this->assertFalse(Gate::forUser($regularAdmin)->allows('view', $brief));
    }

    public function test_any_active_admin_can_view_a_task_driven_generation_run_they_did_not_create(): void
    {
        $this->artisan('migrate');
        $systemAdmin = Admin::query()->where('username', 'geoflow-system')->firstOrFail();
        $regularAdmin = Admin::query()->create([
            'username' => 'run-viewer', 'password' => bcrypt(bin2hex(random_bytes(32))),
            'role' => 'admin', 'status' => 'active',
        ]);
        $task = Task::query()->create(['name' => 'Test', 'status' => 'paused']);
        $brief = ContentBrief::query()->create([
            'topic' => 'Test', 'market_code' => 'US', 'language_code' => 'en', 'audience' => 'x',
            'core_question' => 'x', 'business_goal' => 'x', 'content_type' => 'x',
            'status' => ContentBrief::STATUS_STRATEGY_READY, 'created_by_admin_id' => $systemAdmin->id,
        ]);
        $brief->task_id = $task->id;
        $brief->save();

        // ContentGenerationRun has several required, non-fillable columns (composite FKs
        // tying strategy/blueprint versions to the brief) — build a real chain the same
        // way tests/Feature/GenerationAdoptionServiceTest.php's readyRun() helper does,
        // via forceCreate, rather than guessing at a shortcut that would violate the
        // table's FK constraints.
        $strategy = ContentStrategyVersion::forceCreate([
            'content_brief_id' => $brief->id,
            'version' => 1,
            'strategy' => ['primary_intent' => 'informational'],
            'lifecycle_status' => ContentStrategyVersion::STATUS_CONFIRMED,
        ]);
        $blueprint = ContentBlueprintVersion::forceCreate([
            'content_brief_id' => $brief->id,
            'content_strategy_version_id' => $strategy->id,
            'version' => 1,
            'blueprint' => ['sections' => []],
            'lifecycle_status' => ContentBlueprintVersion::STATUS_CONFIRMED,
        ]);
        $preset = PromptPreset::query()->create([
            'preset_key' => 'system-admin-test-'.str()->uuid(),
            'display_name' => 'System admin test preset',
        ]);
        $presetVersion = PromptPresetVersion::forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => 1,
            'matching_rules' => [],
            'generation_policy' => [],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
        ]);
        $model = AiModel::query()->create([
            'name' => 'System admin test model',
            'version' => '2026-08',
            'api_key' => 'test-key',
            'api_url' => 'https://model.example.test/v1',
            'model_id' => 'system-admin-test-model',
            'max_tokens' => 4096,
        ]);
        $run = ContentGenerationRun::forceCreate([
            'content_brief_id' => $brief->id,
            'strategy_version_id' => $strategy->id,
            'blueprint_version_id' => $blueprint->id,
            'preset_version_id' => $presetVersion->id,
            'ai_model_id' => $model->id,
            'compiled_prompt' => 'Write a test article.',
            'status' => ContentGenerationRun::STATUS_PENDING,
            'idempotency_key' => 'system-admin-test-'.str()->uuid(),
        ]);

        $this->assertTrue(Gate::forUser($regularAdmin)->allows('view', $run));
        $this->assertFalse(Gate::forUser($regularAdmin)->allows('adopt', $run));
    }
}
