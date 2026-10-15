<?php

namespace Tests\Unit\Semrush;

use App\Models\KnowledgeBrand;
use App\Models\KnowledgeDirectory;
use App\Models\KnowledgeSite;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use App\Services\GeoFlow\Semrush\SemrushBrandProfileResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

final class SemrushBrandProfileResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_different_brand_profiles_without_litb_constants(): void
    {
        [$litb] = $this->brandFixture('lightinthebox', 'LightInTheBox', 'lightinthebox-main', 'www.lightinthebox.com');
        [$acegolf] = $this->brandFixture('acegolf', 'AceGolf', 'acegolf-main', 'www.acegolfs.com');

        $resolver = $this->app->make(SemrushBrandProfileResolver::class);
        $litbProfile = $resolver->resolve($litb->id);
        $aceGolfProfile = $resolver->resolve($acegolf->id);

        $this->assertSame('lightinthebox', $litbProfile['brand']['key']);
        $this->assertSame('acegolf', $aceGolfProfile['brand']['key']);
        $this->assertSame('www.acegolfs.com', $aceGolfProfile['site']['canonical_host']);
        $this->assertNotSame($litbProfile['snapshot_hash'], $aceGolfProfile['snapshot_hash']);
        $this->assertSame([], $aceGolfProfile['official_domains'] === ['www.lightinthebox.com'] ? ['unexpected'] : []);
    }

    public function test_requires_a_site_when_a_brand_has_multiple_sites(): void
    {
        [$brand] = $this->brandFixture('multi', 'Multi Brand', 'main', 'main.example.com');
        KnowledgeSite::query()->create([
            'brand_id' => $brand->id,
            'key' => 'secondary',
            'canonical_host' => 'secondary.example.com',
            'role' => 'storefront',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->app->make(SemrushBrandProfileResolver::class)->resolve($brand->id);
    }

    /** @return array{0: KnowledgeBrand, 1: KnowledgeSite} */
    private function brandFixture(string $key, string $name, string $siteKey, string $host): array
    {
        $brand = KnowledgeBrand::query()->create(['key' => $key, 'name' => $name]);
        $site = KnowledgeSite::query()->create([
            'brand_id' => $brand->id,
            'key' => $siteKey,
            'canonical_host' => $host,
            'role' => 'storefront',
        ]);
        KnowledgeDirectory::query()->create([
            'brand_id' => $brand->id,
            'key' => 'root',
            'path' => '/root',
            'name' => 'Root',
        ]);
        $preset = PromptPreset::query()->create([
            'preset_key' => $key.'.buying-guide.en',
            'display_name' => $name.' Buying Guide',
            'is_system' => false,
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

        return [$brand, $site];
    }
}
