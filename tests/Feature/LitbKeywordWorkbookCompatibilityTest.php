<?php

namespace Tests\Feature;

use App\Services\GeoFlow\Keywords\KeywordColumnMapper;
use App\Services\GeoFlow\Keywords\KeywordImportPreview;
use App\Services\GeoFlow\Keywords\KeywordWorkbookReader;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
    private const ALLOWED_REJECTION_REASON_KEYS = ['blank_keyword', 'invalid_keyword_context'];

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
        Bus::fake();
        Queue::fake();

        $reader = new KeywordWorkbookReader;
        $mapper = new KeywordColumnMapper;
        $inspection = $reader->inspect($path);
        $recognizedFields = [];
        $firstDataRowsBySheet = [];
        $rowEstimate = 0;
        foreach ($inspection['sheets'] as $sheet) {
            $recognizedFields = array_merge(
                $recognizedFields,
                array_values($mapper->suggest($sheet['headers'], 'google_search_console')),
            );
            $rowEstimate += $sheet['row_estimate'];
            if ($sheet['rows'] !== []) {
                $firstDataRowsBySheet[$sheet['name']] = min(array_column($sheet['rows'], 'row_number'));
            }
        }
        $recognizedFields = array_values(array_unique($recognizedFields));

        $this->assertContains('keyword', $recognizedFields, 'A keyword column must be recognized.');
        $recognizedMetrics = array_values(array_intersect(self::METRIC_FIELDS, $recognizedFields));
        $this->assertNotEmpty($recognizedMetrics, 'At least one supported metric column must be recognized.');

        $queriesDuringPreview = [];
        $captureQueries = false;
        Event::listen(QueryExecuted::class, static function (QueryExecuted $event) use (&$captureQueries, &$queriesDuringPreview): void {
            if ($captureQueries) {
                $queriesDuringPreview[] = $event->sql;
            }
        });

        try {
            $captureQueries = true;
            $preview = (new KeywordImportPreview(
                reader: $reader,
                mapper: $mapper,
                maxSamples: (int) ceil($rowEstimate * 0.25) + 1,
            ))->build($path, 'google_search_console', []);
        } finally {
            $captureQueries = false;
            Event::forget(QueryExecuted::class);
        }

        $this->assertGreaterThan(0, $preview['counts']['total_rows']);
        $this->assertGreaterThan(0, $preview['counts']['valid_rows']);
        $this->assertNotEmpty($preview['valid_samples']);
        foreach (['total_rows', 'valid_rows', 'rejected_rows'] as $countKey) {
            $this->assertIsInt($preview['counts'][$countKey]);
            $this->assertGreaterThanOrEqual(0, $preview['counts'][$countKey]);
            $this->assertLessThanOrEqual($preview['counts']['total_rows'], $preview['counts'][$countKey]);
        }
        $this->assertSame(
            $preview['counts']['total_rows'],
            $preview['counts']['valid_rows'] + $preview['counts']['rejected_rows'],
            'Every non-empty data row must be either valid or rejected.',
        );
        $this->assertLessThanOrEqual(
            (int) ceil($preview['counts']['total_rows'] * 0.25),
            $preview['counts']['rejected_rows'],
            'Rejected rows must not exceed 25% of non-empty data rows.',
        );
        $this->assertSame(
            $preview['counts']['rejected_rows'],
            count($preview['rejected_samples']),
            'All rejected rows must be available for reason and physical-row validation.',
        );
        $this->assertRejectionsAreExpectedAndKeepPhysicalRowOrder($preview, $firstDataRowsBySheet);

        $this->assertSame($fileBefore, $this->fileFingerprint($path));
        $this->assertSame($countsBefore, $this->businessTableCounts());
        $this->assertSame([], $queriesDuringPreview, 'Workbook compatibility preview must execute no SQL on any connection.');
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

    /**
     * @param  array{valid_samples:array<int,array<string,mixed>>,rejected_samples:array<int,array<string,mixed>>,counts:array{total_rows:int,valid_rows:int,rejected_rows:int}}  $preview
     * @param  array<string,int>  $firstDataRowsBySheet
     */
    private function assertRejectionsAreExpectedAndKeepPhysicalRowOrder(array $preview, array $firstDataRowsBySheet): void
    {
        $rejectedRowsBySheet = [];

        foreach ($preview['rejected_samples'] as $rejection) {
            $this->assertContains(
                $rejection['reason_key'] ?? null,
                self::ALLOWED_REJECTION_REASON_KEYS,
                'Every rejected row must have an explicitly allowed stable reason key.',
            );
            $this->assertIsString($rejection['sheet'] ?? null);
            $this->assertIsInt($rejection['row_number'] ?? null);
            $sheet = $rejection['sheet'];
            $this->assertArrayHasKey($sheet, $firstDataRowsBySheet);
            $this->assertGreaterThanOrEqual(
                $firstDataRowsBySheet[$sheet],
                $rejection['row_number'],
                'Rejected rows must retain physical row numbers after the detected header.',
            );
            $rejectedRowsBySheet[$sheet][] = $rejection['row_number'];
        }

        foreach ($rejectedRowsBySheet as $rejectedRows) {
            foreach (array_slice($rejectedRows, 1) as $index => $rowNumber) {
                $this->assertGreaterThan(
                    $rejectedRows[$index],
                    $rowNumber,
                    'Rejected physical rows must be strictly ordered and unique within each sheet.',
                );
            }
        }
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
