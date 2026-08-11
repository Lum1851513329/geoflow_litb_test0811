<?php

namespace Tests\Unit;

use App\Services\GeoFlow\LitbAssets\LitbKeywordSourceReader;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class LitbKeywordSourceReaderTest extends TestCase
{
    public function test_it_normalizes_text_keywords_and_preserves_source_lines(): void
    {
        $path = storage_path('framework/testing/litb-keywords-'.uniqid().'.txt');
        File::put($path, "Dresses for Women\n  dresses   for women  \nLightInTheBox reviews\n");

        try {
            $rows = iterator_to_array($this->app->make(LitbKeywordSourceReader::class)->read($path, [
                'library_asset_key' => 'litb.keywords.product.en',
                'library_name' => 'LITB Product & Category Keywords — EN',
                'language_code' => 'en',
                'market_code' => 'US',
                'source_type' => 'owned_keyword_research',
            ]));
        } finally {
            File::delete($path);
        }

        $this->assertCount(3, $rows);
        $this->assertSame('dresses for women', $rows[0]['normalized_keyword']);
        $this->assertSame(1, $rows[0]['source_line']);
        $this->assertSame('category', $rows[0]['keyword_type']);
        $this->assertSame('trust', $rows[2]['keyword_type']);
    }

    public function test_it_reads_search_performance_workbooks_with_metrics(): void
    {
        $path = base_path('tests/Fixtures/keywords/category-search-sample.xlsx');
        $rows = iterator_to_array($this->app->make(LitbKeywordSourceReader::class)->read($path, [
            'library_asset_key' => 'litb.search.category.en',
            'library_name' => 'Google Search Performance — Category',
            'language_code' => 'en',
            'market_code' => 'US',
            'source_type' => 'gsc',
        ]));

        $this->assertNotEmpty($rows);
        $this->assertNotEmpty($rows[0]['normalized_keyword']);
        $this->assertIsInt($rows[0]['source_line']);
        $this->assertArrayHasKey('clicks', $rows[0]);
        $this->assertArrayHasKey('impressions', $rows[0]);
    }
}
