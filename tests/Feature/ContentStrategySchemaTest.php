<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\ContentBlueprintVersion;
use App\Models\ContentBrief;
use App\Models\ContentGenerationRun;
use App\Models\ContentGenerationRunStep;
use App\Models\ContentStrategyVersion;
use App\Models\PromptModule;
use App\Models\PromptModuleVersion;
use App\Models\PromptPreset;
use App\Models\PromptPresetModuleVersion;
use App\Models\PromptPresetVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ContentStrategySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_pipeline_schema_preserves_versioned_lineage(): void
    {
        $this->assertTrue(Schema::hasColumns('content_briefs', [
            'topic', 'market_code', 'language_code', 'audience', 'core_question',
            'business_goal', 'content_type', 'planned_publish_at', 'status',
        ]));
        $this->assertTrue(Schema::hasColumns('prompt_module_versions', [
            'prompt_module_id', 'version', 'content', 'variable_contract', 'lifecycle_status',
        ]));
        $this->assertTrue(Schema::hasColumns('content_generation_runs', [
            'content_brief_id', 'strategy_version_id', 'blueprint_version_id',
            'preset_version_id', 'ai_model_id', 'article_id', 'compiled_prompt',
            'raw_output', 'adopted_output', 'status', 'idempotency_key',
        ]));
    }

    public function test_versioned_pipeline_records_preserve_stable_keys_and_lineage(): void
    {
        $brief = ContentBrief::query()->create([
            'topic' => 'Choosing a wedding guest dress',
            'market_code' => 'US',
            'language_code' => 'en',
            'audience' => 'Wedding guests',
            'core_question' => 'What should I wear to a wedding?',
            'business_goal' => 'Help visitors choose a suitable dress',
            'content_type' => 'guide',
            'planned_publish_at' => '2026-08-20 09:00:00',
            'status' => ContentBrief::STATUS_DRAFT,
        ]);
        $strategy = ContentStrategyVersion::query()->create([
            'content_brief_id' => $brief->id,
            'version' => 1,
            'strategy' => ['primary_intent' => 'informational'],
            'lifecycle_status' => ContentStrategyVersion::STATUS_CONFIRMED,
        ]);
        $blueprint = ContentBlueprintVersion::query()->create([
            'content_brief_id' => $brief->id,
            'content_strategy_version_id' => $strategy->id,
            'version' => 1,
            'blueprint' => ['working_title' => 'Wedding guest dress guide'],
            'lifecycle_status' => ContentBlueprintVersion::STATUS_CONFIRMED,
        ]);

        $module = PromptModule::query()->create([
            'module_key' => 'wedding-guest-guide',
            'display_name' => 'Wedding guest guide',
            'module_type' => 'content_type',
        ]);
        $moduleVersion = PromptModuleVersion::query()->create([
            'prompt_module_id' => $module->id,
            'version' => 1,
            'content' => 'Write for {{ audience }}.',
            'variable_contract' => ['required' => ['audience']],
            'lifecycle_status' => PromptModuleVersion::STATUS_PRODUCTION,
        ]);
        $preset = PromptPreset::query()->create([
            'preset_key' => 'us-wedding-guide',
            'display_name' => 'US wedding guide',
        ]);
        $presetVersion = PromptPresetVersion::query()->create([
            'prompt_preset_id' => $preset->id,
            'version' => 1,
            'matching_rules' => ['market_code' => 'US'],
            'generation_policy' => ['max_tokens' => 1200],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
        ]);
        PromptPresetModuleVersion::query()->create([
            'prompt_preset_version_id' => $presetVersion->id,
            'prompt_module_version_id' => $moduleVersion->id,
            'sort_order' => 10,
            'is_required' => true,
        ]);
        $aiModel = AiModel::query()->create([
            'name' => 'Test model',
            'api_key' => 'test-key',
            'model_id' => 'test-model',
        ]);
        $run = ContentGenerationRun::query()->create([
            'content_brief_id' => $brief->id,
            'strategy_version_id' => $strategy->id,
            'blueprint_version_id' => $blueprint->id,
            'preset_version_id' => $presetVersion->id,
            'ai_model_id' => $aiModel->id,
            'compiled_prompt' => 'Write a helpful wedding guest guide.',
            'status' => ContentGenerationRun::STATUS_QUEUED,
            'idempotency_key' => 'content-run-001',
        ]);
        ContentGenerationRunStep::query()->create([
            'content_generation_run_id' => $run->id,
            'sequence' => 1,
            'step_type' => ContentGenerationRunStep::TYPE_INITIAL_GENERATION,
            'status' => ContentGenerationRunStep::STATUS_QUEUED,
        ]);

        $this->assertTrue($brief->strategyVersions()->sole()->is($strategy));
        $this->assertTrue($brief->blueprintVersions()->sole()->is($blueprint));
        $this->assertTrue($module->versions()->sole()->is($moduleVersion));
        $this->assertTrue($presetVersion->moduleVersions()->sole()->promptModuleVersion->is($moduleVersion));
        $this->assertSame(['required' => ['audience']], $moduleVersion->fresh()->variable_contract);
        $this->assertInstanceOf(ContentGenerationRunStep::class, $run->steps()->sole());

        $this->expectException(QueryException::class);
        PromptModuleVersion::query()->create([
            'prompt_module_id' => $module->id,
            'version' => 1,
            'content' => 'A replacement is a new version.',
            'variable_contract' => [],
            'lifecycle_status' => PromptModuleVersion::STATUS_DRAFT,
        ]);
    }

    public function test_content_pipeline_migrations_roll_back_in_reverse_dependency_order(): void
    {
        $strategyMigration = require database_path('migrations/2026_08_10_010000_create_content_strategy_tables.php');
        $promptMigration = require database_path('migrations/2026_08_10_010100_create_prompt_composition_tables.php');
        $generationMigration = require database_path('migrations/2026_08_10_010200_create_content_generation_trace_tables.php');

        $generationMigration->down();
        $promptMigration->down();
        $strategyMigration->down();

        $this->assertFalse(Schema::hasTable('content_briefs'));
        $this->assertFalse(Schema::hasTable('prompt_modules'));
        $this->assertFalse(Schema::hasTable('content_generation_runs'));

        $strategyMigration->up();
        $promptMigration->up();
        $generationMigration->up();

        $this->assertTrue(Schema::hasTable('content_generation_runs'));
    }
}
