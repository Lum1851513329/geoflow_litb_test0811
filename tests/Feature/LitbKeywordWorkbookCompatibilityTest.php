<?php

namespace Tests\Feature;

use App\Services\GeoFlow\Keywords\KeywordColumnMapper;
use App\Services\GeoFlow\Keywords\KeywordImportPreview;
use App\Services\GeoFlow\Keywords\KeywordWorkbookReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LitbKeywordWorkbookCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int,string> */
    private const BUSINESS_TABLES = [
        'keyword_libraries',
        'keywords',
        'keyword_taxonomies',
        'keyword_nodes',
        'keyword_node_keyword',
        'keyword_import_batches',
        'keyword_import_rows',
        'keyword_metrics',
        'keyword_relations',
        'keyword_clusters',
        'keyword_cluster_keywords',
        'keyword_opportunity_scores',
    ];

    /** @var array<int,string> */
    private const METRIC_FIELDS = ['clicks', 'impressions', 'ctr', 'position', 'cost', 'conversions'];

    /** @var array<int,string> */
    private const ALLOWED_REJECTION_REASONS = [
        'The keyword cannot be blank.',
        'The language code must be an ISO language code or BCP 47 tag.',
        'The market code must be an ISO 3166-1 alpha-2 code.',
    ];

    public function test_category_workbook_is_compatible_with_read_only_preview(): void
    {
        $this->assertReadOnlyCompatibility(
            'LITB_CATEGORY_KEYWORDS_XLSX',
            'category',
        );
    }

    public function test_brand_workbook_is_compatible_with_read_only_preview(): void
    {
        $this->assertReadOnlyCompatibility(
            'LITB_BRAND_KEYWORDS_XLSX',
            'brand',
        );
    }

    private function assertReadOnlyCompatibility(string $environmentVariable, string $workbookKind): void
    {
        $path = (string) env($environmentVariable, '');
        if (! is_file($path)) {
            $this->markTestSkipped("LITB {$workbookKind} workbook path is not configured.");
        }

        $fileBefore = $this->fileFingerprint($path);
        $countsBefore = $this->businessTableCounts();
        $writes = [];
        Bus::fake();
        Queue::fake();
        DB::listen(static function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql) === 1) {
                $writes[] = $query->sql;
            }
        });

        $reader = new KeywordWorkbookReader;
        $mapper = new KeywordColumnMapper;
        $inspection = $reader->inspect($path);
        $recognizedFields = [];
        foreach ($inspection['sheets'] as $sheet) {
            $recognizedFields = array_merge(
                $recognizedFields,
                array_values($mapper->suggest($sheet['headers'], 'google_search_console')),
            );
        }
        $recognizedFields = array_values(array_unique($recognizedFields));

        $this->assertContains('keyword', $recognizedFields, 'A keyword column must be recognized.');
        $recognizedMetrics = array_values(array_intersect(self::METRIC_FIELDS, $recognizedFields));
        $this->assertNotEmpty($recognizedMetrics, 'At least one supported metric column must be recognized.');

        $preview = (new KeywordImportPreview(reader: $reader, mapper: $mapper))->build(
            $path,
            'google_search_console',
            [],
        );

        $this->assertGreaterThan(0, $preview['counts']['total_rows']);
        $this->assertGreaterThan(0, $preview['counts']['valid_rows']);
        $this->assertNotEmpty($preview['valid_samples']);
        $this->assertLessThanOrEqual(
            (int) ceil($preview['counts']['total_rows'] * 0.25),
            $preview['counts']['rejected_rows'],
            'Rejected rows must not exceed 25% of non-empty data rows.',
        );
        $this->assertRejectionsAreExpectedAndKeepPhysicalRowOrder($preview);

        $this->assertSame($fileBefore, $this->fileFingerprint($path));
        $this->assertSame($countsBefore, $this->businessTableCounts());
        $this->assertSame([], $writes, 'Workbook compatibility preview must not write to the database.');
        Bus::assertNothingDispatched();
        Queue::assertNothingPushed();

        fwrite(STDERR, sprintf(
            "LITB workbook compatibility: kind=%s sheets=%d recognized_metric_categories=%d total_rows=%d valid_rows=%d rejected_rows=%d\n",
            $workbookKind,
            count($inspection['sheets']),
            count($recognizedMetrics),
            $preview['counts']['total_rows'],
            $preview['counts']['valid_rows'],
            $preview['counts']['rejected_rows'],
        ));
    }

    /** @param array{valid_samples:array<int,array<string,mixed>>,rejected_samples:array<int,array<string,mixed>>,counts:array{total_rows:int,valid_rows:int,rejected_rows:int}} $preview */
    private function assertRejectionsAreExpectedAndKeepPhysicalRowOrder(array $preview): void
    {
        $firstDataRow = (int) $preview['valid_samples'][0]['row_number'];
        $rejectedRows = [];

        foreach ($preview['rejected_samples'] as $rejection) {
            $this->assertContains(
                $rejection['reason'] ?? null,
                self::ALLOWED_REJECTION_REASONS,
                'Every rejected row must have an explicitly allowed validation reason.',
            );
            $this->assertIsInt($rejection['row_number'] ?? null);
            $this->assertGreaterThanOrEqual(
                $firstDataRow,
                $rejection['row_number'],
                'Rejected rows must retain physical row numbers after the detected header.',
            );
            $rejectedRows[] = $rejection['row_number'];
        }

        $sortedRows = $rejectedRows;
        sort($sortedRows, SORT_NUMERIC);
        $this->assertSame($sortedRows, $rejectedRows, 'Rejected physical rows must remain ordered.');
        $this->assertSame(array_values(array_unique($rejectedRows)), $rejectedRows, 'Rejected physical rows must be unique.');
    }

    /** @return array{sha256:string,size:int,mtime:int} */
    private function fileFingerprint(string $path): array
    {
        clearstatcache(true, $path);
        $sha256 = hash_file('sha256', $path);
        $size = filesize($path);
        $mtime = filemtime($path);

        $this->assertIsString($sha256);
        $this->assertIsInt($size);
        $this->assertIsInt($mtime);

        return ['sha256' => $sha256, 'size' => $size, 'mtime' => $mtime];
    }

    /** @return array<string,int> */
    private function businessTableCounts(): array
    {
        $counts = [];
        foreach (self::BUSINESS_TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
