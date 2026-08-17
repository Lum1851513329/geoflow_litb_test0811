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
