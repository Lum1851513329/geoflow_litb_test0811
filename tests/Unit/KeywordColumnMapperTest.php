<?php

namespace Tests\Unit;

use App\Services\GeoFlow\Keywords\KeywordColumnMapper;
use App\Services\GeoFlow\Keywords\KeywordImportPreview;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class KeywordColumnMapperTest extends TestCase
{
    public function test_it_suggests_only_known_aliases_for_search_and_ad_columns(): void
    {
        $mapping = (new KeywordColumnMapper)->suggest([
            'query',
            'clicks',
            'impressions',
            'ctr',
            'average_position',
            'country',
            'language',
            'date_range',
            'cost',
            'conversions',
            'campaign_note',
        ], 'google_ads');

        $this->assertSame([
            'query' => 'keyword',
            'clicks' => 'clicks',
            'impressions' => 'impressions',
            'ctr' => 'ctr',
            'average_position' => 'position',
            'country' => 'country',
            'language' => 'language',
            'date_range' => 'date_range',
            'cost' => 'cost',
            'conversions' => 'conversions',
        ], $mapping);
    }

    public function test_it_leaves_unknown_columns_unmapped_and_prefers_a_keyword_alias(): void
    {
        $mapping = (new KeywordColumnMapper)->suggest(['keyword', 'search_term', 'notes'], 'google_search_console');

        $this->assertSame(['keyword' => 'keyword'], $mapping);
        $this->assertArrayNotHasKey('notes', $mapping);
        $this->assertArrayNotHasKey('search_term', $mapping);
    }

    public function test_explicit_mapping_replaces_an_automatic_field_assignment(): void
    {
        $path = $this->makeWorkbook([
            ['Keyword', 'Search Term'],
            ['wrong keyword', 'Lightinthebox coupon'],
        ]);

        $preview = (new KeywordImportPreview)->build($path, 'google_search_console', ['search_term' => 'keyword']);

        $this->assertSame('Lightinthebox coupon', $preview['valid_samples'][0]['mapped_values']['keyword']);
        $this->assertSame($preview['valid_samples'][0]['mapped_values']['keyword'], $preview['valid_samples'][0]['keyword']['original']);
        $this->assertSame('lightinthebox coupon', $preview['valid_samples'][0]['keyword']['normalized']);
    }

    public function test_explicit_mapping_rejects_duplicate_canonical_assignments(): void
    {
        $path = $this->makeWorkbook([
            ['Keyword', 'Search Term'],
            ['one', 'two'],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('multiple columns');

        (new KeywordImportPreview)->build($path, 'google_search_console', [
            'keyword' => 'keyword',
            'search_term' => 'keyword',
        ]);
    }

    /** @param array<int,array<int,string>> $rows */
    private function makeWorkbook(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'keyword-workbook-').'.xlsx';
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
