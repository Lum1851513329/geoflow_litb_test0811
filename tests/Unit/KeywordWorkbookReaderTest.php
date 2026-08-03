<?php

namespace Tests\Unit;

use App\Services\GeoFlow\Keywords\KeywordImportPreview;
use App\Services\GeoFlow\Keywords\KeywordWorkbookReader;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class KeywordWorkbookReaderTest extends TestCase
{
    public function test_it_reads_the_synthetic_category_search_fixture(): void
    {
        $inspection = (new KeywordWorkbookReader)->inspect(base_path('tests/Fixtures/keywords/category-search-sample.xlsx'));

        $this->assertSame(['query', 'clicks', 'impressions', 'ctr', 'position', 'country', 'language', 'date_range'], $inspection['sheets'][0]['headers']);
        $this->assertSame(5, $inspection['sheets'][0]['row_estimate']);
        $this->assertSame('mother of the bride dresses', $inspection['sheets'][0]['sample_rows'][0]['query']);
    }

    public function test_it_inspects_xlsx_headers_samples_and_row_estimates_without_calculating_formulas(): void
    {
        $path = $this->makeWorkbook([
            [' Query ', 'Average Position', 'Formula'],
            ['mother of the bride dresses', 4.2, '=2+2'],
            ['prom dresses', 5.4, '=3+3'],
        ]);

        $inspection = (new KeywordWorkbookReader)->inspect($path);

        $this->assertSame(['Keywords'], array_column($inspection['sheets'], 'name'));
        $this->assertSame(['query', 'average_position', 'formula'], $inspection['sheets'][0]['headers']);
        $this->assertSame(2, $inspection['sheets'][0]['row_estimate']);
        $this->assertSame('=2+2', $inspection['sheets'][0]['sample_rows'][0]['formula']);
    }

    public function test_it_rejects_non_xlsx_non_regular_and_oversized_inputs(): void
    {
        $reader = new KeywordWorkbookReader(maxFileSizeBytes: 100);
        $textFile = tempnam(sys_get_temp_dir(), 'keyword-workbook-');
        file_put_contents($textFile, 'not an XLSX workbook');

        try {
            $reader->inspect($textFile.'.csv');
            $this->fail('Expected a non-XLSX file to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('.xlsx', $exception->getMessage());
        }

        $directory = sys_get_temp_dir().'/keyword-directory-'.uniqid('', true).'.xlsx';
        mkdir($directory);

        try {
            $reader->inspect($directory);
            $this->fail('Expected a directory to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('regular readable file', $exception->getMessage());
        }

        $workbook = $this->makeWorkbook([['Query'], ['prom dresses']]);

        $this->expectException(InvalidArgumentException::class);
        $reader->inspect($workbook);
    }

    public function test_it_rejects_workbooks_that_exceed_sheet_row_or_column_limits(): void
    {
        $tooManySheets = $this->makeWorkbook([['Query'], ['prom dresses']], sheetCount: 2);
        $reader = new KeywordWorkbookReader(maxSheets: 1, maxRowsPerSheet: 10, maxColumnsPerSheet: 10);

        try {
            $reader->inspect($tooManySheets);
            $this->fail('Expected a workbook with too many sheets to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('sheet limit', $exception->getMessage());
        }

        $tooManyRows = $this->makeWorkbook([['Query'], ['a'], ['b']]);
        try {
            (new KeywordWorkbookReader(maxRowsPerSheet: 1))->inspect($tooManyRows);
            $this->fail('Expected a workbook with too many rows to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('row limit', $exception->getMessage());
        }

        $tooManyColumns = $this->makeWorkbook([['Query', 'Clicks'], ['prom dresses', 12]]);
        $this->expectException(InvalidArgumentException::class);
        (new KeywordWorkbookReader(maxColumnsPerSheet: 1))->inspect($tooManyColumns);
    }

    public function test_preview_builds_valid_and_rejected_samples_without_database_writes(): void
    {
        $path = $this->makeWorkbook([
            ['Query', 'Clicks', 'Country', 'Language', 'Unmapped Notes'],
            ['lightinthebox coupon', 265, 'us', 'en', 'paid campaign'],
            ['   ', 0, 'US', 'en', 'blank query'],
        ]);
        $writes = [];
        DB::listen(static function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        $preview = (new KeywordImportPreview)->build($path, 'google_search_console', [
            'query' => 'keyword',
            'clicks' => 'clicks',
            'country' => 'country',
            'language' => 'language',
        ]);

        $this->assertSame(['total_rows' => 2, 'valid_rows' => 1, 'rejected_rows' => 1], $preview['counts']);
        $this->assertSame('lightinthebox coupon', $preview['valid_samples'][0]['keyword']['normalized']);
        $this->assertSame('brand', $preview['valid_samples'][0]['classification']['keyword_type']);
        $this->assertSame('paid campaign', $preview['valid_samples'][0]['source_payload']['unmapped_notes']);
        $this->assertSame('The keyword cannot be blank.', $preview['rejected_samples'][0]['reason']);
        $this->assertSame([], $writes);
    }

    public function test_php_images_install_and_enable_gd_for_phpspreadsheet(): void
    {
        foreach (['docker/Dockerfile', 'docker/Dockerfile.prod'] as $path) {
            $dockerfile = (string) file_get_contents(base_path($path));

            foreach (['libfreetype6-dev', 'libjpeg62-turbo-dev', 'libpng-dev'] as $package) {
                $this->assertStringContainsString($package, $dockerfile, $path);
            }

            $this->assertStringContainsString('docker-php-ext-configure gd --with-freetype --with-jpeg', $dockerfile, $path);
            $this->assertMatchesRegularExpression('/docker-php-ext-install\s+\\\\\s*gd\b/', $dockerfile, $path);
        }

        $productionDockerfile = (string) file_get_contents(base_path('docker/Dockerfile.prod'));
        $this->assertStringContainsString('--ignore-platform-req=ext-gd', $productionDockerfile);
    }

    /** @param array<int,array<int,string|int|float>> $rows */
    private function makeWorkbook(array $rows, int $sheetCount = 1): string
    {
        $path = tempnam(sys_get_temp_dir(), 'keyword-workbook-').'.xlsx';
        $spreadsheet = new Spreadsheet;

        for ($sheetNumber = 0; $sheetNumber < $sheetCount; $sheetNumber++) {
            $sheet = $sheetNumber === 0 ? $spreadsheet->getActiveSheet() : $spreadsheet->createSheet();
            $sheet->setTitle($sheetNumber === 0 ? 'Keywords' : 'Keywords '.$sheetNumber);
            $sheet->fromArray($rows, null, 'A1');
        }

        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
