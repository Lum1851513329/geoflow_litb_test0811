<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\KnowledgeBrand;
use App\Models\KnowledgeDirectory;
use App\Models\KnowledgeSite;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use App\Services\GeoFlow\Semrush\SemrushImportPreviewSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

final class SemrushImportControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_uses_the_selected_acegolf_profile_and_defaults_to_external_evidence(): void
    {
        Storage::fake('local');
        $brand = $this->brandFixture();
        $csv = UploadedFile::fake()->createWithContent('topics.csv', implode("\n", [
            'name,country,volume,difficulty,intents',
            'How to choose golf irons,us,1200,35,informational:70;commercial:30',
        ]));

        $preview = $this->app->make(SemrushImportPreviewSession::class)->begin($csv, [
            'distribution_channel_id' => 1,
            'knowledge_brand_id' => $brand->id,
            'knowledge_site_id' => null,
            'knowledge_directory_id' => null,
            'limit' => 10,
        ], 7);

        $this->assertSame('general', $preview['conversion']['accepted'][0]['knowledge_mode']);
        $this->assertSame('on_insufficient', $preview['conversion']['accepted'][0]['external_evidence_policy']);
        $this->assertSame($brand->key, $this->app->make(SemrushImportPreviewSession::class)->resolve($preview['token'], 7)['knowledge_brand_id'] ? $brand->key : null);
    }

    public function test_confirm_resolution_rejects_a_preview_when_the_brand_profile_changed(): void
    {
        Storage::fake('local');
        $brand = $this->brandFixture();
        $csv = UploadedFile::fake()->createWithContent('topics.csv', implode("\n", [
            'name,country,volume,difficulty,intents',
            'How to choose golf irons,us,1200,35,informational:70;commercial:30',
        ]));
        $service = $this->app->make(SemrushImportPreviewSession::class);
        $preview = $service->begin($csv, [
            'distribution_channel_id' => 1,
            'knowledge_brand_id' => $brand->id,
            'limit' => 10,
        ], 7);

        $brand->forceFill(['name' => 'AceGolf Updated'])->save();

        $this->expectException(InvalidArgumentException::class);
        $service->resolve($preview['token'], 7);
    }

    private function brandFixture(): KnowledgeBrand
    {
        $brand = KnowledgeBrand::query()->create(['key' => 'acegolf', 'name' => 'AceGolf']);
        KnowledgeSite::query()->create([
            'brand_id' => $brand->id,
            'key' => 'acegolf-main',
            'canonical_host' => 'www.acegolfs.com',
            'role' => 'storefront',
        ]);
        KnowledgeDirectory::query()->create([
            'brand_id' => $brand->id,
            'key' => 'root',
            'path' => '/root',
            'name' => 'Golf',
        ]);
        Category::query()->create(['name' => 'Golf Buying Guides', 'slug' => 'golf-buying-guides']);
        $preset = PromptPreset::query()->create([
            'preset_key' => 'acegolf.buying-guide.en',
            'display_name' => 'AceGolf Buying Guide',
            'is_enabled' => true,
        ]);
        $version = PromptPresetVersion::query()->forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => 1,
            'matching_rules' => ['content_type' => ['buying_guide']],
            'generation_policy' => [],
            'blueprint_template' => ['sections' => []],
            'default_brief_fields' => ['content_type' => 'buying_guide'],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
        ]);
        $preset->forceFill(['current_version_id' => $version->id])->save();

        return $brand;
    }
}
