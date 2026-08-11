<?php

namespace Tests\Feature;

use App\Models\Keyword;
use App\Models\KeywordImportRow;
use App\Models\KeywordLibrary;
use App\Models\KeywordMetric;
use App\Services\GeoFlow\LitbAssets\LitbKeywordImporter;
use App\Services\GeoFlow\LitbAssets\LitbKeywordSourceReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class LitbKeywordImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_normalized_rows_create_one_keyword_and_audited_duplicate(): void
    {
        $path = storage_path('framework/testing/litb-keyword-import-'.uniqid().'.txt');
        File::put($path, "dresses for women\nDresses   for Women\n");
        $config = [
            'library_asset_key' => 'litb.keywords.product.en',
            'library_name' => 'LITB Product & Category Keywords — EN',
            'language_code' => 'en',
            'market_code' => 'US',
            'source_type' => 'owned_keyword_research',
        ];

        try {
            $rows = $this->app->make(LitbKeywordSourceReader::class)->read($path, $config);
            $manifest = $this->app->make(LitbKeywordImporter::class)->import($rows);
        } finally {
            File::delete($path);
        }

        $library = KeywordLibrary::query()->where('asset_key', $config['library_asset_key'])->sole();
        $this->assertSame(1, Keyword::query()->where('library_id', $library->id)->count());
        $this->assertSame(2, KeywordImportRow::query()->count());
        $this->assertSame(1, KeywordImportRow::query()->where('status', 'duplicate')->count());
        $this->assertSame(1, $manifest->createdCount());
        $this->assertSame(1, $manifest->skippedCount());
    }

    public function test_search_workbook_metrics_are_linked_to_the_exact_import_row(): void
    {
        $rows = $this->app->make(LitbKeywordSourceReader::class)->read(
            base_path('tests/Fixtures/keywords/category-search-sample.xlsx'),
            [
                'library_asset_key' => 'litb.search.category.en',
                'library_name' => 'Google Search Performance — Category',
                'language_code' => 'en',
                'market_code' => 'US',
                'source_type' => 'gsc',
            ],
        );

        $this->app->make(LitbKeywordImporter::class)->import($rows);

        $metric = KeywordMetric::query()->whereNotNull('import_row_id')->firstOrFail();
        $this->assertNotNull($metric->keyword_id);
        $this->assertSame('gsc', $metric->source_type);
        $this->assertNotNull($metric->importRow ?? KeywordImportRow::query()->find($metric->import_row_id));
    }
}
