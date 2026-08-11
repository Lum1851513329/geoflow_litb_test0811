<?php

namespace Tests\Unit;

use App\Services\GeoFlow\LitbAssets\LitbQuarterlyBriefCatalog;
use Tests\TestCase;

final class LitbQuarterlyBriefCatalogTest extends TestCase
{
    public function test_catalog_contains_ninety_complete_briefs_in_confirmed_pillar_quotas(): void
    {
        $definitions = (new LitbQuarterlyBriefCatalog())->definitions();

        $this->assertCount(90, $definitions);
        $this->assertSame([
            'brand_company' => 7,
            'category_authority' => 18,
            'customer_education' => 9,
            'faq' => 8,
            'new_arrivals' => 9,
            'official_news' => 6,
            'product_comparison' => 12,
            'product_education' => 9,
            'style_occasion' => 12,
        ], collect($definitions)->countBy('pillar')->sortKeys()->all());

        foreach ($definitions as $definition) {
            foreach (['asset_key', 'topic', 'content_type', 'primary_keyword', 'geo_direct_answer', 'sections', 'evidence_group_keys', 'prompt_preset_key', 'seo_title', 'meta_description'] as $field) {
                $this->assertNotEmpty($definition[$field] ?? null, $definition['asset_key'].' '.$field);
            }
        }
        $this->assertCount(90, array_unique(array_column($definitions, 'asset_key')));
    }
}
