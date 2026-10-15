<?php

namespace Tests\Feature;

use App\Models\PromptPreset;
use Database\Seeders\LitbPromptLibrarySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LitbPromptLibrarySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_all_production_presets_once_with_core_and_conditional_modules(): void
    {
        $this->seed(LitbPromptLibrarySeeder::class);
        $this->seed(LitbPromptLibrarySeeder::class);

        $presets = PromptPreset::query()->where('preset_key', 'like', 'litb.%')->with('currentVersion.moduleVersions')->get();
        $this->assertCount(11, $presets);
        foreach ($presets as $preset) {
            $this->assertNotNull($preset->currentVersion, $preset->preset_key);
            $this->assertSame('production', $preset->currentVersion->lifecycle_status);
            $this->assertSame(13, $preset->currentVersion->moduleVersions->count(), $preset->preset_key);
        }
        $this->assertSame(1, PromptPreset::query()->where('preset_key', 'litb.geo_product_buying_guide.en')->count());

        $guide = $presets->firstWhere('preset_key', 'litb.geo_product_buying_guide.en')->currentVersion;
        $required = $guide->moduleVersions
            ->filter(fn ($binding): bool => $binding->is_required)
            ->map(fn ($binding): string => $binding->promptModuleVersion->module->module_key)
            ->values()
            ->all();
        $this->assertSame([
            'litb.foundation.truth_evidence',
            'litb.geo.citable_answers',
            'litb.seo.search_intent',
            'litb.seo.structure',
            'litb.language.market_en_global',
            'litb.output.markdown_article',
            'litb.quality.preflight',
            'litb.content.buying_guide',
        ], $required);

        $links = $guide->moduleVersions->first(
            fn ($binding): bool => $binding->promptModuleVersion->module->module_key === 'litb.seo.links_cta',
        );
        $this->assertFalse($links->is_required);
        $this->assertSame('not_empty', data_get($links->configuration, 'conditions.0.operator'));
    }
}
