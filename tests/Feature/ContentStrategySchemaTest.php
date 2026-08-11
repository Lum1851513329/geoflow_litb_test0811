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
            'prompt_module_id', 'version', 'content', 'variable_contract', 'content_sha256',
            'snapshot_sha256', 'lifecycle_status',
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
            'blueprint' => ['working_title' => 'Wedding guest dress guide'],
            'lifecycle_status' => ContentBlueprintVersion::STATUS_CONFIRMED,
        ]);

        $module = PromptModule::query()->create([
            'module_key' => 'wedding-guest-guide',
            'display_name' => 'Wedding guest guide',
            'module_type' => 'content_type',
        ]);
        $moduleVersion = PromptModuleVersion::forceCreate([
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
        $presetVersion = PromptPresetVersion::forceCreate([
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
        $run = ContentGenerationRun::forceCreate([
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
        PromptModuleVersion::forceCreate([
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
        $snapshotMigration = require database_path('migrations/2026_08_10_010320_add_prompt_module_snapshot_identity.php');

        $snapshotMigration->down();
        $generationMigration->down();
        $promptMigration->down();
        $strategyMigration->down();

        $this->assertFalse(Schema::hasTable('content_briefs'));
        $this->assertFalse(Schema::hasTable('prompt_modules'));
        $this->assertFalse(Schema::hasTable('content_generation_runs'));

        $strategyMigration->up();
        $promptMigration->up();
        $generationMigration->up();
        $snapshotMigration->up();

        $this->assertTrue(Schema::hasTable('content_generation_runs'));
        $this->assertTrue(Schema::hasColumn('prompt_module_versions', 'snapshot_sha256'));
    }

    public function test_snapshot_identity_migration_backfills_legacy_rows_and_allows_contract_evolution(): void
    {
        $migration = require database_path('migrations/2026_08_10_010320_add_prompt_module_snapshot_identity.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('prompt_module_versions', 'snapshot_sha256'));

        $module = $this->createModule('snapshot-upgrade');
        $content = 'Stable body with an evolving contract.';
        $legacy = PromptModuleVersion::forceCreate([
            'prompt_module_id' => $module->id,
            'version' => 1,
            'content' => $content,
            'variable_contract' => ['variables' => []],
            'output_contract' => ['format' => 'markdown'],
            'parameter_defaults' => ['tone' => 'legacy'],
            'content_sha256' => hash('sha256', $content),
            'lifecycle_status' => PromptModuleVersion::STATUS_PRODUCTION,
        ]);

        $migration->up();

        $legacy->refresh();
        $this->assertTrue(Schema::hasColumn('prompt_module_versions', 'snapshot_sha256'));
        $this->assertSame(hash('sha256', $content), $legacy->content_sha256);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $legacy->snapshot_sha256);
        $evolved = PromptModuleVersion::forceCreate([
            'prompt_module_id' => $module->id,
            'version' => 2,
            'content' => $content,
            'variable_contract' => ['variables' => [
                'audience' => ['type' => 'string', 'required' => true],
            ]],
            'output_contract' => ['format' => 'markdown'],
            'parameter_defaults' => ['tone' => 'current'],
            'lifecycle_status' => PromptModuleVersion::STATUS_PRODUCTION,
        ]);
        $this->assertSame($legacy->content_sha256, $evolved->content_sha256);
        $this->assertNotSame($legacy->snapshot_sha256, $evolved->snapshot_sha256);
        $this->assertDatabaseRejects(fn () => PromptModuleVersion::query()->whereKey($evolved)->update([
            'snapshot_sha256' => str_repeat('a', 64),
        ]));
    }

    public function test_version_tables_require_contiguous_per_parent_numbers(): void
    {
        $brief = $this->createBrief('strategy');
        $strategyOne = $this->createStrategyVersion($brief, 1);
        $strategyTwo = $this->createStrategyVersion($brief, 2);
        $this->assertSame(1, $strategyOne->version);
        $this->assertSame(2, $strategyTwo->version);
        $this->assertDatabaseRejects(fn () => $this->createStrategyVersion($brief, 4));
        $this->assertDatabaseRejects(fn () => $this->createStrategyVersion($brief, 2));
        $this->assertDatabaseRejects(fn () => $this->createStrategyVersion($this->createBrief('strategy-first'), 2));

        $blueprintOne = $this->createBlueprintVersion($brief, $strategyOne, 1);
        $blueprintTwo = $this->createBlueprintVersion($brief, $strategyTwo, 2);
        $this->assertSame(1, $blueprintOne->version);
        $this->assertSame(2, $blueprintTwo->version);
        $this->assertDatabaseRejects(fn () => $this->createBlueprintVersion($brief, $strategyTwo, 4));
        $this->assertDatabaseRejects(fn () => $this->createBlueprintVersion($brief, $strategyTwo, 2));
        $firstBlueprintBrief = $this->createBrief('blueprint-first');
        $this->assertDatabaseRejects(fn () => $this->createBlueprintVersion(
            $firstBlueprintBrief,
            $this->createStrategyVersion($firstBlueprintBrief, 1),
            2,
        ));

        $module = $this->createModule('sequence');
        $moduleOne = $this->createModuleVersion($module, 1);
        $moduleTwo = $this->createModuleVersion($module, 2);
        $this->assertSame(1, $moduleOne->version);
        $this->assertSame(2, $moduleTwo->version);
        $this->assertDatabaseRejects(fn () => $this->createModuleVersion($module, 4));
        $this->assertDatabaseRejects(fn () => $this->createModuleVersion($module, 2));
        $this->assertDatabaseRejects(fn () => $this->createModuleVersion($this->createModule('module-first'), 2));

        $preset = $this->createPreset('sequence');
        $presetOne = $this->createPresetVersion($preset, 1);
        $presetTwo = $this->createPresetVersion($preset, 2);
        $this->assertSame(1, $presetOne->version);
        $this->assertSame(2, $presetTwo->version);
        $this->assertDatabaseRejects(fn () => $this->createPresetVersion($preset, 4));
        $this->assertDatabaseRejects(fn () => $this->createPresetVersion($preset, 2));
        $this->assertDatabaseRejects(fn () => $this->createPresetVersion($this->createPreset('preset-first'), 2));
    }

    public function test_database_guards_reject_immutable_snapshot_updates_and_deletions(): void
    {
        $pipeline = $this->createPipeline('immutable');
        $pipeline['strategy']->lifecycle_status = ContentStrategyVersion::STATUS_ARCHIVED;
        $pipeline['strategy']->save();
        $this->assertSame(ContentStrategyVersion::STATUS_ARCHIVED, $pipeline['strategy']->fresh()->lifecycle_status);

        $this->assertDatabaseRejects(fn () => ContentStrategyVersion::query()->whereKey($pipeline['strategy'])->update([
            'strategy' => ['primary_intent' => 'commercial'],
        ]));
        $this->assertDatabaseRejects(fn () => ContentBlueprintVersion::query()->whereKey($pipeline['blueprint'])->update([
            'blueprint' => ['working_title' => 'Replacement title'],
        ]));
        $this->assertDatabaseRejects(fn () => PromptModuleVersion::query()->whereKey($pipeline['module_version'])->update([
            'content' => 'Replacement content',
        ]));
        $this->assertDatabaseRejects(fn () => PromptModuleVersion::query()->whereKey($pipeline['module_version'])->update([
            'snapshot_sha256' => str_repeat('f', 64),
        ]));
        $this->assertDatabaseRejects(fn () => PromptPresetVersion::query()->whereKey($pipeline['preset_version'])->update([
            'matching_rules' => ['market_code' => 'GB'],
        ]));
        $this->assertDatabaseRejects(fn () => ContentGenerationRun::query()->whereKey($pipeline['run'])->update([
            'compiled_prompt' => 'Replacement prompt',
        ]));

        $strategyTwo = $this->createStrategyVersion($pipeline['brief'], 2);
        $blueprintTwo = $this->createBlueprintVersion($pipeline['brief'], $strategyTwo, 2);
        $moduleTwo = $this->createModuleVersion($pipeline['module'], 2);
        $presetTwo = $this->createPresetVersion($pipeline['preset'], 2);
        $this->assertDatabaseRejects(fn () => $strategyTwo->delete());
        $this->assertDatabaseRejects(fn () => $blueprintTwo->delete());
        $this->assertDatabaseRejects(fn () => $moduleTwo->delete());
        $this->assertDatabaseRejects(fn () => $presetTwo->delete());
        $this->assertDatabaseRejects(fn () => $pipeline['run']->delete());
    }

    public function test_database_guards_enforce_stable_keys_idempotency_and_same_brief_lineage(): void
    {
        $pipeline = $this->createPipeline('lineage-one');
        $otherBrief = $this->createBrief('lineage-two');
        $otherStrategy = $this->createStrategyVersion($otherBrief, 1);
        $otherBlueprint = $this->createBlueprintVersion($otherBrief, $otherStrategy, 1);

        $this->assertDatabaseRejects(fn () => PromptModule::query()->create([
            'module_key' => $pipeline['module']->module_key,
            'display_name' => 'Duplicate module',
            'module_type' => PromptModule::TYPE_GEO,
        ]));
        $this->assertDatabaseRejects(fn () => PromptPreset::query()->create([
            'preset_key' => $pipeline['preset']->preset_key,
            'display_name' => 'Duplicate preset',
        ]));
        $this->assertDatabaseRejects(fn () => ContentGenerationRun::forceCreate([
            'content_brief_id' => $pipeline['brief']->id,
            'strategy_version_id' => $pipeline['strategy']->id,
            'blueprint_version_id' => $pipeline['blueprint']->id,
            'preset_version_id' => $pipeline['preset_version']->id,
            'ai_model_id' => $pipeline['ai_model']->id,
            'compiled_prompt' => 'Duplicate idempotency key.',
            'status' => ContentGenerationRun::STATUS_QUEUED,
            'idempotency_key' => $pipeline['run']->idempotency_key,
        ]));
        $this->assertDatabaseRejects(fn () => $this->createBlueprintVersion(
            $otherBrief,
            $pipeline['strategy'],
            2,
        ));
        $this->assertDatabaseRejects(fn () => ContentGenerationRun::forceCreate([
            'content_brief_id' => $otherBrief->id,
            'strategy_version_id' => $pipeline['strategy']->id,
            'blueprint_version_id' => $otherBlueprint->id,
            'preset_version_id' => $pipeline['preset_version']->id,
            'ai_model_id' => $pipeline['ai_model']->id,
            'compiled_prompt' => 'Mismatched strategy brief lineage.',
            'status' => ContentGenerationRun::STATUS_QUEUED,
            'idempotency_key' => 'mismatched-strategy-brief-lineage',
        ]));
        $this->assertDatabaseRejects(fn () => ContentGenerationRun::forceCreate([
            'content_brief_id' => $otherBrief->id,
            'strategy_version_id' => $otherStrategy->id,
            'blueprint_version_id' => $pipeline['blueprint']->id,
            'preset_version_id' => $pipeline['preset_version']->id,
            'ai_model_id' => $pipeline['ai_model']->id,
            'compiled_prompt' => 'Mismatched blueprint brief lineage.',
            'status' => ContentGenerationRun::STATUS_QUEUED,
            'idempotency_key' => 'mismatched-blueprint-brief-lineage',
        ]));

        $this->assertTrue($otherBlueprint->contentBrief->is($otherBrief));
    }

    /** @return array{brief: ContentBrief, strategy: ContentStrategyVersion, blueprint: ContentBlueprintVersion, module: PromptModule, module_version: PromptModuleVersion, preset: PromptPreset, preset_version: PromptPresetVersion, ai_model: AiModel, run: ContentGenerationRun} */
    private function createPipeline(string $suffix): array
    {
        $brief = $this->createBrief($suffix);
        $strategy = $this->createStrategyVersion($brief, 1);
        $blueprint = $this->createBlueprintVersion($brief, $strategy, 1);
        $module = $this->createModule($suffix);
        $moduleVersion = $this->createModuleVersion($module, 1);
        $preset = $this->createPreset($suffix);
        $presetVersion = $this->createPresetVersion($preset, 1);
        $aiModel = AiModel::query()->create([
            'name' => "Test model {$suffix}",
            'api_key' => 'test-key',
            'model_id' => "test-model-{$suffix}",
        ]);
        $run = ContentGenerationRun::forceCreate([
            'content_brief_id' => $brief->id,
            'strategy_version_id' => $strategy->id,
            'blueprint_version_id' => $blueprint->id,
            'preset_version_id' => $presetVersion->id,
            'ai_model_id' => $aiModel->id,
            'compiled_prompt' => "Write a helpful {$suffix} guide.",
            'status' => ContentGenerationRun::STATUS_QUEUED,
            'idempotency_key' => "content-run-{$suffix}",
        ]);

        return compact('brief', 'strategy', 'blueprint', 'module', 'moduleVersion', 'preset', 'presetVersion', 'aiModel', 'run') + [
            'module_version' => $moduleVersion,
            'preset_version' => $presetVersion,
            'ai_model' => $aiModel,
        ];
    }

    private function createBrief(string $suffix): ContentBrief
    {
        return ContentBrief::query()->create([
            'topic' => "Topic {$suffix}",
            'market_code' => 'US',
            'language_code' => 'en',
            'audience' => 'Wedding guests',
            'core_question' => 'What should I wear to a wedding?',
            'business_goal' => 'Help visitors choose a suitable dress',
            'content_type' => 'guide',
            'status' => ContentBrief::STATUS_DRAFT,
        ]);
    }

    private function createStrategyVersion(ContentBrief $brief, int $version): ContentStrategyVersion
    {
        return ContentStrategyVersion::forceCreate([
            'content_brief_id' => $brief->id,
            'version' => $version,
            'strategy' => ['primary_intent' => 'informational'],
            'lifecycle_status' => ContentStrategyVersion::STATUS_CONFIRMED,
        ]);
    }

    private function createBlueprintVersion(ContentBrief $brief, ContentStrategyVersion $strategy, int $version): ContentBlueprintVersion
    {
        return ContentBlueprintVersion::forceCreate([
            'content_brief_id' => $brief->id,
            'content_strategy_version_id' => $strategy->id,
            'version' => $version,
            'blueprint' => ['working_title' => 'Wedding guest dress guide'],
            'lifecycle_status' => ContentBlueprintVersion::STATUS_CONFIRMED,
        ]);
    }

    private function createModule(string $suffix): PromptModule
    {
        return PromptModule::query()->create([
            'module_key' => "module-{$suffix}",
            'display_name' => "Module {$suffix}",
            'module_type' => PromptModule::TYPE_CONTENT_TYPE,
        ]);
    }

    private function createModuleVersion(PromptModule $module, int $version): PromptModuleVersion
    {
        return PromptModuleVersion::forceCreate([
            'prompt_module_id' => $module->id,
            'version' => $version,
            'content' => "Write version {$version} for {{ audience }}.",
            'variable_contract' => ['required' => ['audience']],
            'lifecycle_status' => PromptModuleVersion::STATUS_PRODUCTION,
        ]);
    }

    private function createPreset(string $suffix): PromptPreset
    {
        return PromptPreset::query()->create([
            'preset_key' => "preset-{$suffix}",
            'display_name' => "Preset {$suffix}",
        ]);
    }

    private function createPresetVersion(PromptPreset $preset, int $version): PromptPresetVersion
    {
        return PromptPresetVersion::forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => $version,
            'matching_rules' => ['market_code' => 'US'],
            'generation_policy' => ['max_tokens' => 1200],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
        ]);
    }

    private function assertDatabaseRejects(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected the database to reject the operation.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
