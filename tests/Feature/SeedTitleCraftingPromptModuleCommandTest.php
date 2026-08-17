<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PromptModule;
use App\Models\PromptModuleVersion;
use Database\Seeders\PromptCompositionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SeedTitleCraftingPromptModuleCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_title_crafting_module_and_updates_seo_structure(): void
    {
        // The 2026_08_15_000400 migration now seeds this row permanently for every
        // environment (including test DBs, via RefreshDatabase running all migrations),
        // so reuse it instead of colliding with it on a fresh insert.
        Admin::query()->firstOrCreate(['username' => 'geoflow-system'], [
            'password' => bcrypt(bin2hex(random_bytes(32))),
            'role' => 'system',
            'status' => 'active',
        ]);
        $this->seed(PromptCompositionSeeder::class);

        $this->artisan('geoflow:content-strategy:seed-title-crafting-module')->assertSuccessful();

        $titleCrafting = PromptModule::query()->where('module_key', 'title_crafting')->firstOrFail();
        $this->assertTrue((bool) $titleCrafting->is_mandatory);
        $this->assertNull($titleCrafting->applicability);
        $version = $titleCrafting->currentVersion;
        $this->assertSame(PromptModuleVersion::STATUS_PRODUCTION, $version->lifecycle_status);
        $this->assertStringContainsString('# Title text', $version->content);

        $seoStructure = PromptModule::query()->where('module_key', 'seo_structure')->firstOrFail();
        $this->assertStringContainsString("role label describing that section's purpose", $seoStructure->currentVersion->content);
    }

    public function test_is_idempotent_on_a_second_run(): void
    {
        // The 2026_08_15_000400 migration now seeds this row permanently for every
        // environment (including test DBs, via RefreshDatabase running all migrations),
        // so reuse it instead of colliding with it on a fresh insert.
        Admin::query()->firstOrCreate(['username' => 'geoflow-system'], [
            'password' => bcrypt(bin2hex(random_bytes(32))),
            'role' => 'system',
            'status' => 'active',
        ]);
        $this->seed(PromptCompositionSeeder::class);

        $this->artisan('geoflow:content-strategy:seed-title-crafting-module')->assertSuccessful();
        $this->artisan('geoflow:content-strategy:seed-title-crafting-module')->assertSuccessful();

        $this->assertSame(1, PromptModule::query()->where('module_key', 'title_crafting')->count());
        $seoStructure = PromptModule::query()->where('module_key', 'seo_structure')->firstOrFail();
        $this->assertSame(2, $seoStructure->versions()->count());
    }
}
