<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PromptModule;
use App\Models\PromptPreset;
use App\Models\PromptPresetModuleVersion;
use App\Models\PromptPresetVersion;
use Database\Seeders\PromptCompositionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SeedPresetBlueprintTemplatesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfills_a_litb_preset_with_blueprint_template_and_title_crafting(): void
    {
        // The 2026_08_15_000400 migration now seeds this row permanently for every
        // environment (including test DBs, via RefreshDatabase running all migrations),
        // so reuse it instead of colliding with it on a fresh insert.
        $actor = Admin::query()->firstOrCreate(['username' => 'geoflow-system'], [
            'password' => bcrypt(bin2hex(random_bytes(32))),
            'role' => 'system',
            'status' => 'active',
        ]);
        $this->seed(PromptCompositionSeeder::class);
        $this->artisan('geoflow:content-strategy:seed-title-crafting-module')->assertSuccessful();

        $preset = PromptPreset::query()->create([
            'preset_key' => 'litb.geo_category_authority.en',
            'display_name' => 'LITB category authority',
            'is_system' => true,
            'is_enabled' => true,
        ]);
        $seoStructureVersion = PromptModule::query()->where('module_key', 'seo_structure')->firstOrFail()->currentVersion;
        // PromptPresetService::publish() runs MandatoryPromptModuleGuard::assertComplete(),
        // which requires every is_system+is_mandatory+is_enabled module in the whole
        // database (not just the ones this preset already uses) to be bound before a
        // version can go to production — PromptCompositionSeeder seeds 6 such modules
        // (foundation_truth, geo_citability, seo_structure, language_market,
        // output_format, quality_checks) with applicability=null, so all 6 must be
        // bound here or publish() throws. This mirrors the real risk this task
        // surfaced: a preset bound only to a different "module family" (as every real
        // LitbPromptLibrarySeeder preset is) fails this same guard against a fully
        // seeded database — see the concerns section of the task report.
        $mandatoryModuleVersions = PromptModule::query()
            ->where('is_system', true)
            ->where('is_mandatory', true)
            ->whereNotNull('current_version_id')
            ->get()
            ->map(fn (PromptModule $module) => $module->currentVersion);

        // Created as DRAFT first: prompt_preset_module_versions has a DB-level
        // immutability trigger that rejects inserts once the parent version's
        // lifecycle_status is production/deprecated/archived (see migration
        // 2026_08_11_000100_enforce_content_generation_audit_immutability), so
        // the binding must be inserted before the version is flipped to
        // production — mirrors the ordering PromptCompositionSeeder itself uses.
        $version = PromptPresetVersion::query()->forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => 1,
            'matching_rules' => ['content_type' => ['category_authority']],
            'generation_policy' => [],
            'conflict_policy' => null,
            'lifecycle_status' => PromptPresetVersion::STATUS_DRAFT,
            'created_by_admin_id' => $actor->id,
        ]);
        // selection_source is explicitly 'system' here (not the column's 'preset'
        // default) to mirror real seeded data — PromptCompositionSeeder and
        // LitbPromptLibrarySeeder both stamp their bindings 'system' (or 'legacy'),
        // never 'preset' — so the migrated-binding assertion below actually proves
        // the command preserves original provenance rather than merely matching a
        // coincidental default.
        foreach ($mandatoryModuleVersions->values() as $index => $moduleVersion) {
            PromptPresetModuleVersion::query()->create([
                'prompt_preset_version_id' => $version->id,
                'prompt_module_version_id' => $moduleVersion->id,
                'sort_order' => $index + 1,
                'is_required' => true,
                'selection_source' => 'system',
            ]);
        }
        $version->forceFill(['lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION])->save();
        $preset->forceFill(['current_version_id' => $version->id])->save();

        $this->artisan('geoflow:content-strategy:seed-preset-blueprint-templates')->assertSuccessful();

        $preset->refresh();
        $newVersion = $preset->currentVersion;
        $this->assertSame(2, $newVersion->version);
        $this->assertNotNull($newVersion->blueprint_template);
        $this->assertSame(1800, $newVersion->blueprint_template['target_length']);
        $this->assertSame('GLOBAL', $newVersion->default_brief_fields['market_code']);
        $titleCraftingVersionId = PromptModule::query()->where('module_key', 'title_crafting')->firstOrFail()->current_version_id;
        $this->assertTrue(
            PromptPresetModuleVersion::query()
                ->where('prompt_preset_version_id', $newVersion->id)
                ->where('prompt_module_version_id', $titleCraftingVersionId)
                ->exists()
        );
        $migratedSeoStructureBinding = PromptPresetModuleVersion::query()
            ->where('prompt_preset_version_id', $newVersion->id)
            ->where('prompt_module_version_id', $seoStructureVersion->id)
            ->first();
        $this->assertNotNull(
            $migratedSeoStructureBinding,
            'Existing module bindings must be preserved in the new version, not dropped.'
        );
        $this->assertSame(
            'system',
            $migratedSeoStructureBinding->selection_source,
            'Migrated bindings must keep their original selection_source provenance, not fall back to createVersionForLockedPreset()\'s "preset" default.'
        );
    }

    public function test_backfill_upgrades_a_stale_mandatory_module_binding_to_its_current_version(): void
    {
        $actor = Admin::query()->firstOrCreate(['username' => 'geoflow-system'], [
            'password' => bcrypt(bin2hex(random_bytes(32))),
            'role' => 'system',
            'status' => 'active',
        ]);
        $this->seed(PromptCompositionSeeder::class);

        $preset = $this->productionPreset(
            'litb.geo_category_authority.en',
            $actor,
            bindAllMandatoryModules: true,
        );
        $staleSeoStructureVersionId = PromptModule::query()
            ->where('module_key', 'seo_structure')
            ->firstOrFail()
            ->current_version_id;

        $this->artisan('geoflow:content-strategy:seed-title-crafting-module')->assertSuccessful();
        $currentSeoStructureVersionId = PromptModule::query()
            ->where('module_key', 'seo_structure')
            ->firstOrFail()
            ->current_version_id;
        $this->assertNotSame($staleSeoStructureVersionId, $currentSeoStructureVersionId);

        $this->artisan('geoflow:content-strategy:seed-preset-blueprint-templates')->assertSuccessful();

        $preset->refresh();
        $this->assertTrue(
            PromptPresetModuleVersion::query()
                ->where('prompt_preset_version_id', $preset->current_version_id)
                ->where('prompt_module_version_id', $currentSeoStructureVersionId)
                ->exists(),
        );
        $this->assertFalse(
            PromptPresetModuleVersion::query()
                ->where('prompt_preset_version_id', $preset->current_version_id)
                ->where('prompt_module_version_id', $staleSeoStructureVersionId)
                ->exists(),
        );
    }

    /**
     * C4: `PromptPresetService::publish()` calls `MandatoryPromptModuleGuard::assertComplete()`,
     * which throws for ANY unbound DB-wide mandatory module — not just title_crafting.
     * Without per-preset error handling, preset N throwing aborted the whole command,
     * leaving presets 1..N-1 on new published versions and N onward untouched with no
     * summary of what happened. One bad preset must not block the rest.
     */
    public function test_a_preset_that_fails_the_mandatory_module_guard_does_not_block_the_others(): void
    {
        $actor = Admin::query()->firstOrCreate(['username' => 'geoflow-system'], [
            'password' => bcrypt(bin2hex(random_bytes(32))),
            'role' => 'system',
            'status' => 'active',
        ]);
        $this->seed(PromptCompositionSeeder::class);
        $this->artisan('geoflow:content-strategy:seed-title-crafting-module')->assertSuccessful();

        // Created first so it is reached first in the command's loop — proving the
        // presets after it are still processed rather than skipped by an abort.
        $failing = $this->productionPreset('litb.incomplete_bindings.en', $actor, bindAllMandatoryModules: false);
        $failingOriginalVersionId = $failing->current_version_id;
        $healthy = $this->productionPreset('litb.geo_category_authority.en', $actor, bindAllMandatoryModules: true);

        $this->artisan('geoflow:content-strategy:seed-preset-blueprint-templates')
            ->expectsOutputToContain('litb.incomplete_bindings.en')
            ->assertFailed();

        // The failing preset is left exactly as it was — no half-applied version.
        $failing->refresh();
        $this->assertSame($failingOriginalVersionId, $failing->current_version_id);
        $this->assertSame(1, $failing->currentVersion->version);
        $this->assertNull($failing->currentVersion->blueprint_template);

        // The healthy preset was still backfilled despite its sibling failing.
        $healthy->refresh();
        $this->assertSame(2, $healthy->currentVersion->version);
        $this->assertNotNull($healthy->currentVersion->blueprint_template);
        $titleCraftingVersionId = PromptModule::query()->where('module_key', 'title_crafting')->firstOrFail()->current_version_id;
        $this->assertTrue(
            PromptPresetModuleVersion::query()
                ->where('prompt_preset_version_id', $healthy->currentVersion->id)
                ->where('prompt_module_version_id', $titleCraftingVersionId)
                ->exists()
        );
    }

    /**
     * Builds a preset with a production current version. When
     * `$bindAllMandatoryModules` is false it binds only a single mandatory module, so
     * `MandatoryPromptModuleGuard::assertComplete()` rejects any attempt to publish a
     * new version for it.
     */
    private function productionPreset(string $presetKey, Admin $actor, bool $bindAllMandatoryModules): PromptPreset
    {
        $preset = PromptPreset::query()->create([
            'preset_key' => $presetKey,
            'display_name' => $presetKey,
            'is_system' => true,
            'is_enabled' => true,
        ]);

        $mandatoryModules = PromptModule::query()
            ->where('is_system', true)
            ->where('is_mandatory', true)
            ->whereNotNull('current_version_id')
            ->get();
        $moduleVersions = $bindAllMandatoryModules
            ? $mandatoryModules->map(fn (PromptModule $module) => $module->currentVersion)
            : $mandatoryModules->take(1)->map(fn (PromptModule $module) => $module->currentVersion);

        // Bindings must be inserted while the version is still DRAFT — see the
        // immutability trigger note in the first test above.
        $version = PromptPresetVersion::query()->forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => 1,
            'matching_rules' => [],
            'generation_policy' => [],
            'conflict_policy' => null,
            'lifecycle_status' => PromptPresetVersion::STATUS_DRAFT,
            'created_by_admin_id' => $actor->id,
        ]);
        foreach ($moduleVersions->values() as $index => $moduleVersion) {
            PromptPresetModuleVersion::query()->create([
                'prompt_preset_version_id' => $version->id,
                'prompt_module_version_id' => $moduleVersion->id,
                'sort_order' => $index + 1,
                'is_required' => true,
                'selection_source' => 'system',
            ]);
        }
        $version->forceFill(['lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION])->save();
        $preset->forceFill(['current_version_id' => $version->id])->save();

        return $preset->refresh();
    }

    public function test_does_not_touch_the_four_excluded_generic_presets(): void
    {
        // See the comment above on firstOrCreate — the migration now seeds this row.
        Admin::query()->firstOrCreate(['username' => 'geoflow-system'], [
            'password' => bcrypt(bin2hex(random_bytes(32))),
            'role' => 'system',
            'status' => 'active',
        ]);
        $this->seed(PromptCompositionSeeder::class);
        $this->artisan('geoflow:content-strategy:seed-title-crafting-module')->assertSuccessful();

        $preset = PromptPreset::query()->where('preset_key', 'legacy_geo_trust_en')->firstOrFail();
        $originalVersionId = $preset->current_version_id;

        $this->artisan('geoflow:content-strategy:seed-preset-blueprint-templates')->assertSuccessful();

        $preset->refresh();
        $this->assertSame($originalVersionId, $preset->current_version_id);
        $this->assertSame(1, $preset->currentVersion->version);
    }
}
