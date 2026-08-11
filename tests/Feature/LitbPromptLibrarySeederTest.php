<?php

namespace Tests\Feature;

use App\Models\PromptPreset;
use Database\Seeders\LitbPromptLibrarySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LitbPromptLibrarySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_all_production_presets_once_with_mandatory_modules(): void
    {
        $this->seed(LitbPromptLibrarySeeder::class);
        $this->seed(LitbPromptLibrarySeeder::class);

        $presets = PromptPreset::query()->where('preset_key', 'like', 'litb.%')->with('currentVersion.moduleVersions')->get();
        $this->assertCount(11, $presets);
        foreach ($presets as $preset) {
            $this->assertNotNull($preset->currentVersion, $preset->preset_key);
            $this->assertSame('production', $preset->currentVersion->lifecycle_status);
            $this->assertGreaterThanOrEqual(13, $preset->currentVersion->moduleVersions->count(), $preset->preset_key);
        }
        $this->assertSame(1, PromptPreset::query()->where('preset_key', 'litb.geo_product_buying_guide.en')->count());
    }
}
