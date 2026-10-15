<?php

namespace Tests\Unit\KnowledgeGovernance;

use App\Models\KnowledgeBrand;
use App\Models\KnowledgeSite;
use App\Services\GeoFlow\KnowledgeGovernance\GenerationKnowledgeScopeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class GenerationKnowledgeScopeResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_allows_general_mode_without_a_brand_scope(): void
    {
        $normalized = $this->app->make(GenerationKnowledgeScopeResolver::class)->normalize([
            'knowledge_mode' => 'general',
        ]);

        $this->assertSame('general', $normalized['knowledge_mode']);
        $this->assertNull($normalized['knowledge_brand_id']);
        $this->assertNull($normalized['knowledge_site_id']);
    }

    public function test_requires_a_brand_scope_for_mixed_mode(): void
    {
        $this->expectException(ValidationException::class);

        $this->app->make(GenerationKnowledgeScopeResolver::class)->normalize([
            'knowledge_mode' => 'mixed',
        ]);
    }

    public function test_normalizes_mixed_mode_inside_the_selected_brand(): void
    {
        $brand = KnowledgeBrand::query()->create(['key' => 'acegolf', 'name' => 'AceGolf']);
        $site = KnowledgeSite::query()->create([
            'brand_id' => $brand->id,
            'key' => 'acegolf-main',
            'canonical_host' => 'www.acegolfs.com',
            'role' => 'storefront',
        ]);

        $normalized = $this->app->make(GenerationKnowledgeScopeResolver::class)->normalize([
            'knowledge_mode' => 'mixed',
            'knowledge_brand_id' => $brand->id,
            'knowledge_site_id' => $site->id,
        ]);

        $this->assertSame('mixed', $normalized['knowledge_mode']);
        $this->assertSame($brand->id, $normalized['knowledge_brand_id']);
        $this->assertSame($site->id, $normalized['knowledge_site_id']);
    }
}
