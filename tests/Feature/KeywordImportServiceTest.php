<?php

namespace Tests\Feature;

use App\Events\KeywordImportProgressed;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use App\Models\KeywordLibrary;
use App\Models\KeywordMetric;
use App\Services\GeoFlow\Keywords\KeywordImportRevertService;
use App\Services\GeoFlow\Keywords\KeywordImportService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;

class KeywordImportServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int,string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_create_batch_copies_to_a_generated_private_path_and_rejects_the_same_file_and_config(): void
    {
        Storage::fake('local');
        $library = $this->library();
        $source = $this->workbook([
            ['Keyword', 'Clicks'],
            ['prom dresses', 10],
        ]);
        $metadata = $this->metadata($library, $source);

        $batch = (new KeywordImportService)->createBatch($metadata);

        $this->assertNotSame($source, $batch->stored_path);
        $this->assertStringStartsWith('keyword-imports/', $batch->stored_path);
        Storage::disk('local')->assertExists($batch->stored_path);
        $this->assertSame(hash_file('sha256', $source), $batch->file_sha256);
        $this->assertSame('uploaded', $batch->status);
        $this->assertSame(['keyword' => 'keyword', 'clicks' => 'clicks'], $batch->mapping_json);
        $this->assertSame('2026-07-01', $batch->period_start?->toDateString());

        $this->expectException(InvalidArgumentException::class);
        (new KeywordImportService)->createBatch($metadata + ['original_filename' => 'renamed.xlsx']);
    }

    public function test_import_is_idempotent_reuses_legacy_keywords_and_preserves_nullable_metrics_and_periods(): void
    {
        Storage::fake('local');
        $library = $this->library();
        $legacy = Keyword::query()->create([
            'library_id' => $library->id,
            'keyword' => 'legacy dresses',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $source = $this->workbook([
            ['Keyword', 'Clicks', 'Impressions', 'CTR', 'Position', 'Country', 'Language', 'Date Range'],
            ['New Dresses', 10, 100, 0.1, 2.5, 'US', 'en', '2026-07-01 - 2026-07-31'],
            ['legacy dresses', null, null, null, null, 'US', 'en', '2026-07-01 - 2026-07-31'],
            [' NEW  DRESSES ', 12, 120, 0.1, 2.1, 'US', 'en', '2026-08-01 - 2026-08-31'],
            [' ', 99, 999, 0.1, 1.0, 'US', 'en', '2026-07-01 - 2026-07-31'],
        ]);
        $service = new KeywordImportService;
        $batch = $service->createBatch($this->metadata($library, $source, [
            'impressions' => 'impressions',
            'ctr' => 'ctr',
            'position' => 'position',
            'country' => 'country',
            'language' => 'language',
            'date_range' => 'date_range',
        ]));

        $service->import($batch);
        $service->import($batch->fresh());

        $this->assertDatabaseCount('keywords', 2);
        $this->assertDatabaseCount('keyword_import_rows', 4);
        $this->assertDatabaseCount('keyword_metrics', 3);
        $this->assertSame($legacy->id, Keyword::query()->where('keyword', 'legacy dresses')->value('id'));
        $this->assertSame('legacy dresses', $legacy->fresh()->normalized_keyword);
        $this->assertDatabaseHas('keyword_metrics', [
            'keyword_id' => $legacy->id,
            'clicks' => null,
            'impressions' => null,
            'ctr' => null,
            'average_position' => null,
        ]);
        $newKeyword = Keyword::query()->where('normalized_keyword', 'new dresses')->sole();
        $this->assertSame(
            ['2026-07-01', '2026-08-01'],
            $newKeyword->metrics()->orderBy('period_start')->get()->map(fn (KeywordMetric $metric) => $metric->period_start?->toDateString())->all()
        );
        $this->assertSame(2, $library->fresh()->keyword_count);
        $this->assertSame('completed', $batch->fresh()->status);
        $this->assertSame(4, $batch->fresh()->processed_count);
        $this->assertSame(1, $batch->fresh()->imported_count);
        $this->assertSame(2, $batch->fresh()->duplicate_count);
        $this->assertSame(1, $batch->fresh()->rejected_count);
    }

    public function test_same_raw_keyword_can_be_imported_for_distinct_markets(): void
    {
        Storage::fake('local');
        $library = $this->library();
        $source = $this->workbook([
            ['Keyword', 'Country', 'Language', 'Clicks'],
            ['dresses for women', 'US', 'en', 10],
            ['dresses for women', 'GB', 'en', 12],
        ]);
        $service = new KeywordImportService;
        $batch = $service->createBatch($this->metadata($library, $source, [
            'country' => 'country',
            'language' => 'language',
        ]));

        $service->import($batch);

        $this->assertSame(
            ['GB', 'US'],
            Keyword::query()
                ->where('library_id', $library->id)
                ->where('normalized_keyword', 'dresses for women')
                ->orderBy('market_scope')
                ->pluck('market_scope')
                ->all(),
        );
        $this->assertDatabaseCount('keywords', 2);
        $this->assertDatabaseCount('keyword_metrics', 2);
        $this->assertSame(2, $batch->fresh()->imported_count);
    }

    public function test_a_failed_chunk_rolls_back_and_a_retry_does_not_duplicate_rows_or_metrics(): void
    {
        Storage::fake('local');
        $library = $this->library();
        $source = $this->workbook([
            ['Keyword', 'Clicks'],
            ['first dresses', 10],
            ['second dresses', 20],
        ]);
        $batch = (new KeywordImportService)->createBatch($this->metadata($library, $source));
        $metricInsertCount = 0;
        Event::listen(QueryExecuted::class, function (QueryExecuted $event) use (&$metricInsertCount): void {
            if (str_starts_with(strtolower(ltrim($event->sql)), 'insert into "keyword_metrics"')) {
                $metricInsertCount++;
                if ($metricInsertCount === 2) {
                    throw new RuntimeException('synthetic chunk failure');
                }
            }
        });

        try {
            (new KeywordImportService(chunkSize: 1))->import($batch);
            $this->fail('The synthetic chunk failure should escape the import.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic chunk failure', $exception->getMessage());
        } finally {
            Event::forget(QueryExecuted::class);
        }

        $this->assertDatabaseCount('keywords', 1);
        $this->assertDatabaseCount('keyword_import_rows', 1);
        $this->assertDatabaseCount('keyword_metrics', 1);
        $firstMetricId = KeywordMetric::query()->sole()->id;
        $this->assertSame('failed', $batch->fresh()->status);

        (new KeywordImportService(chunkSize: 1))->import($batch->fresh());

        $this->assertDatabaseCount('keywords', 2);
        $this->assertDatabaseCount('keyword_import_rows', 2);
        $this->assertDatabaseCount('keyword_metrics', 2);
        $this->assertDatabaseHas('keyword_metrics', ['id' => $firstMetricId]);
        $this->assertSame('completed', $batch->fresh()->status);
    }

    public function test_processing_import_is_not_reentered_and_processing_revert_is_rejected(): void
    {
        Storage::fake('local');
        $library = $this->library();
        $source = $this->workbook([['Keyword'], ['state guarded dresses']]);
        $batch = (new KeywordImportService)->createBatch($this->metadata($library, $source));
        $batch->forceFill(['status' => 'processing'])->save();

        $returned = (new KeywordImportService)->import($batch->fresh());

        $this->assertSame('processing', $returned->status);
        $this->assertDatabaseCount('keyword_import_rows', 0);
        $this->assertDatabaseCount('keyword_metrics', 0);

        try {
            (new KeywordImportRevertService)->revert($batch->fresh());
            $this->fail('A processing batch cannot be reverted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('processing', $exception->getMessage());
        }
        $this->assertSame('processing', $batch->fresh()->status);
    }

    public function test_invalid_lengths_and_calendar_periods_are_rejected_before_keyword_writes(): void
    {
        Storage::fake('local');
        $library = $this->library();
        $source = $this->workbook([
            ['Keyword', 'Language', 'Date Range'],
            [str_repeat('k', 201), 'en', '2026-07-01 - 2026-07-31'],
            ['invalid language dresses', 'zh-Hans-CN-extra', '2026-07-01 - 2026-07-31'],
            ['invalid calendar dresses', 'en', '2026-02-30 - 2026-03-01'],
            ['reverse period dresses', 'en', '2026-08-31 - 2026-08-01'],
        ]);
        $batch = (new KeywordImportService)->createBatch($this->metadata($library, $source, [
            'language' => 'language',
            'date_range' => 'date_range',
        ]));

        (new KeywordImportService)->import($batch);

        $this->assertDatabaseCount('keywords', 0);
        $this->assertDatabaseCount('keyword_metrics', 0);
        $this->assertSame(4, $batch->fresh()->rejected_count);
        $errors = $batch->rows()->pluck('error_message')->implode(' ');
        $this->assertStringContainsString('200', $errors);
        $this->assertStringContainsString('10', $errors);
        $this->assertStringContainsString('calendar', $errors);
        $this->assertStringContainsString('start', $errors);
    }

    public function test_overlong_batch_and_metric_dimensions_fail_before_invalid_database_writes(): void
    {
        Storage::fake('local');
        $library = $this->library();
        $source = $this->workbook([['Keyword'], ['scope guarded dresses']]);
        try {
            (new KeywordImportService)->createBatch($this->metadata($library, $source, [], [
                'source_type' => str_repeat('s', 51),
            ]));
            $this->fail('An overlong source type must be rejected before creating a batch.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('50', $exception->getMessage());
        }
        $this->assertDatabaseCount('keyword_import_batches', 0);

        $batch = (new KeywordImportService)->createBatch($this->metadata($library, $source, [], [
            'metadata_json' => ['market_scope' => str_repeat('M', 11), 'device' => str_repeat('d', 31)],
        ]));
        (new KeywordImportService)->import($batch);

        $this->assertDatabaseCount('keywords', 0);
        $this->assertSame(1, $batch->fresh()->rejected_count);
    }

    public function test_progress_listener_failures_do_not_change_completed_or_reverted_states(): void
    {
        Storage::fake('local');
        $library = $this->library();
        $source = $this->workbook([['Keyword'], ['event safe dresses']]);
        $batch = (new KeywordImportService)->createBatch($this->metadata($library, $source));
        Event::listen(KeywordImportProgressed::class, static function (): never {
            throw new RuntimeException('synthetic broadcast failure');
        });

        (new KeywordImportService)->import($batch);
        $this->assertSame('completed', $batch->fresh()->status);

        (new KeywordImportRevertService)->revert($batch->fresh());
        $this->assertSame('reverted', $batch->fresh()->status);
        Event::forget(KeywordImportProgressed::class);
    }

    public function test_after_commit_listener_failure_is_contained_when_import_runs_in_an_outer_transaction(): void
    {
        Storage::fake('local');
        $library = $this->library();
        $source = $this->workbook([['Keyword'], ['outer transaction dresses']]);
        $batch = (new KeywordImportService)->createBatch($this->metadata($library, $source));
        Event::listen(KeywordImportProgressed::class, static function (): never {
            throw new RuntimeException('outer transaction broadcast failure');
        });

        DB::beginTransaction();
        try {
            (new KeywordImportService)->import($batch);
            DB::commit();
        } catch (RuntimeException $exception) {
            DB::rollBack();
            $this->fail('The after-commit event escaped: '.$exception->getMessage());
        } finally {
            Event::forget(KeywordImportProgressed::class);
        }

        $this->assertSame('completed', $batch->fresh()->status);
    }

    public function test_import_rejects_a_replaced_stored_workbook_before_processing_rows(): void
    {
        Storage::fake('local');
        $library = $this->library();
        $source = $this->workbook([['Keyword'], ['tamper guarded dresses']]);
        $batch = (new KeywordImportService)->createBatch($this->metadata($library, $source));
        Storage::disk('local')->put($batch->stored_path, 'replaced workbook bytes');
        Event::listen(KeywordImportProgressed::class, static function (): never {
            throw new RuntimeException('failed-state broadcast failure');
        });

        try {
            (new KeywordImportService)->import($batch);
            $this->fail('A workbook whose hash changed must not be imported.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('SHA-256', $exception->getMessage());
        } finally {
            Event::forget(KeywordImportProgressed::class);
        }

        $this->assertSame('failed', $batch->fresh()->status);
        $this->assertDatabaseCount('keyword_import_rows', 0);
        $this->assertDatabaseCount('keyword_metrics', 0);
    }

    public function test_a_rejected_metric_row_does_not_leave_an_orphan_keyword(): void
    {
        Storage::fake('local');
        $library = $this->library();
        $source = $this->workbook([
            ['Keyword', 'Clicks', 'Date Range'],
            ['invalid metric dresses', -1, 'not-a-period'],
        ]);
        $batch = (new KeywordImportService)->createBatch($this->metadata($library, $source, ['date_range' => 'date_range']));

        (new KeywordImportService)->import($batch);

        $this->assertDatabaseCount('keywords', 0);
        $this->assertDatabaseCount('keyword_metrics', 0);
        $this->assertDatabaseHas('keyword_import_rows', ['batch_id' => $batch->id, 'status' => 'rejected']);
        $this->assertSame(0, $library->fresh()->keyword_count);
    }

    public function test_numeric_overflow_and_decimal_precision_are_rejected_without_rolling_back_valid_rows(): void
    {
        Storage::fake('local');
        $library = $this->library();
        $source = $this->workbook([
            ['Keyword', 'Clicks', 'Impressions', 'CTR', 'Position', 'Cost', 'Conversions'],
            ['valid numeric dresses', '9223372036854775807', 100, '0.123456', '123456.1234', '1234567890.1234', '1234567890.1234'],
            ['bigint overflow dresses', '9223372036854775808', 100, '0.1', '1', '1', '1'],
            ['exponent overflow dresses', 10, 100, '0.1', '1', '1e24', '1'],
            ['ctr precision dresses', 10, 100, '12345.123456', '1', '1', '1'],
            ['position scale dresses', 10, 100, '0.1', '1.12345', '1', '1'],
            ['cost precision dresses', 10, 100, '0.1', '1', '12345678901.0000', '1'],
            ['conversion scale dresses', 10, 100, '0.1', '1', '1', '1.12345'],
        ]);
        $batch = (new KeywordImportService)->createBatch($this->metadata($library, $source, [
            'impressions' => 'impressions',
            'ctr' => 'ctr',
            'position' => 'position',
            'cost' => 'cost',
            'conversions' => 'conversions',
        ]));

        (new KeywordImportService(chunkSize: 20))->import($batch);

        $this->assertSame('completed', $batch->fresh()->status);
        $this->assertSame(1, $batch->fresh()->imported_count);
        $this->assertSame(6, $batch->fresh()->rejected_count);
        $this->assertDatabaseCount('keywords', 1);
        $this->assertDatabaseCount('keyword_metrics', 1);
        $this->assertDatabaseHas('keyword_metrics', [
            'clicks' => 9223372036854775807,
            'ctr' => 0.123456,
            'average_position' => 123456.1234,
        ]);
    }

    public function test_google_ads_no_data_placeholders_are_treated_as_null_metrics_not_row_rejections(): void
    {
        Storage::fake('local');
        $library = $this->library();
        $source = $this->workbook([
            ['Keyword', 'Clicks', 'CTR', 'Position', 'Cost'],
            ['light in the box shoes', 5, '--', '--', '--'],
        ]);
        $batch = (new KeywordImportService)->createBatch($this->metadata($library, $source, [
            'ctr' => 'ctr',
            'position' => 'position',
            'cost' => 'cost',
        ]));

        (new KeywordImportService)->import($batch);

        $this->assertSame('completed', $batch->fresh()->status);
        $this->assertSame(1, $batch->fresh()->imported_count);
        $this->assertSame(0, $batch->fresh()->rejected_count);
        $this->assertDatabaseHas('keyword_metrics', [
            'clicks' => 5,
            'ctr' => null,
            'average_position' => null,
            'cost' => null,
        ]);
    }

    public function test_metrics_are_unique_per_physical_import_row_but_other_batches_and_periods_can_append(): void
    {
        Storage::fake('local');
        $library = $this->library();
        $source = $this->workbook([
            ['Keyword', 'Clicks', 'Date Range'],
            ['prom dresses', 10, '2026-07-01 - 2026-07-31'],
            ['prom dresses', 11, '2026-08-01 - 2026-08-31'],
        ]);
        $service = new KeywordImportService;
        $first = $service->createBatch($this->metadata($library, $source, ['date_range' => 'date_range']));
        $service->import($first);
        $second = $service->createBatch($this->metadata($library, $source, ['date_range' => 'date_range'], [
            'source_type' => 'paid_search',
        ]));
        $service->import($second);

        $this->assertDatabaseCount('keywords', 1);
        $this->assertDatabaseCount('keyword_metrics', 4);
        $this->assertSame(4, KeywordMetric::query()->distinct()->count('import_row_id'));
    }

    public function test_revert_deletes_only_batch_created_unshared_keywords_and_records_retention_reasons(): void
    {
        Storage::fake('local');
        $library = $this->library();
        $source = $this->workbook([
            ['Keyword'],
            ['delete me'],
            ['metric shared'],
            ['cluster shared'],
            ['title shared'],
            ['article shared'],
        ]);
        $service = new KeywordImportService;
        $batch = $service->createBatch($this->metadata($library, $source));
        $service->import($batch);
        $keywords = Keyword::query()->get()->keyBy('normalized_keyword');

        $otherBatch = $service->createBatch($this->metadata($library, $source, [], ['source_type' => 'other_source']));
        KeywordMetric::query()->create([
            'keyword_id' => $keywords['metric shared']->id,
            'import_batch_id' => $otherBatch->id,
            'source_type' => 'other_source',
        ]);
        $cluster = KeywordCluster::query()->create([
            'name' => 'Shared cluster',
            'slug' => 'shared-cluster',
            'primary_keyword_id' => $keywords['cluster shared']->id,
            'language_code' => 'en',
            'search_intent' => 'commercial',
        ]);
        $cluster->keywords()->attach($keywords['cluster shared']->id, ['role' => 'primary']);
        $titleLibraryId = DB::table('title_libraries')->insertGetId([
            'name' => 'Shared titles',
            'title_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('titles')->insert([
            'library_id' => $titleLibraryId,
            'title' => 'A retained title',
            'keyword' => 'title shared',
            'created_at' => now(),
        ]);
        $categoryId = DB::table('categories')->insertGetId(['name' => 'Test', 'slug' => 'test', 'created_at' => now()]);
        $authorId = DB::table('authors')->insertGetId(['name' => 'Author', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('articles')->insert([
            'title' => 'A retained article',
            'slug' => 'a-retained-article',
            'content' => 'Content',
            'category_id' => $categoryId,
            'author_id' => $authorId,
            'original_keyword' => 'article shared',
            'keywords' => 'article shared, dresses',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reverted = (new KeywordImportRevertService)->revert($batch->fresh());

        $this->assertSame('reverted', $reverted->status);
        $this->assertSame(4, $reverted->retained_shared_keyword_count);
        $this->assertDatabaseMissing('keywords', ['normalized_keyword' => 'delete me']);
        foreach (['metric shared', 'cluster shared', 'title shared', 'article shared'] as $value) {
            $this->assertDatabaseHas('keywords', ['normalized_keyword' => $value]);
        }
        $this->assertSame(1, KeywordMetric::query()->where('import_batch_id', $otherBatch->id)->count());
        $this->assertSame(0, KeywordMetric::query()->where('import_batch_id', $batch->id)->count());
        $reasons = $batch->rows()->where('status', 'retained_shared')->pluck('error_message')->implode(' ');
        foreach (['metric', 'cluster', 'title', 'article'] as $reference) {
            $this->assertStringContainsString($reference, $reasons);
        }
        $this->assertSame(4, $library->fresh()->keyword_count);
        $this->assertDatabaseCount('keyword_import_rows', 5);
        $this->assertSame(5, $batch->rows()->whereIn('status', ['reverted', 'retained_shared'])->count());
    }

    private function library(): KeywordLibrary
    {
        return KeywordLibrary::query()->create(['name' => 'Import library', 'description' => '', 'keyword_count' => 0]);
    }

    /**
     * @param  array<string,string>  $extraMapping
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function metadata(KeywordLibrary $library, string $source, array $extraMapping = [], array $overrides = []): array
    {
        return array_replace([
            'library_id' => $library->id,
            'source_path' => $source,
            'original_filename' => basename($source),
            'source_type' => 'search_console',
            'mapping_json' => ['keyword' => 'keyword', 'clicks' => 'clicks'] + $extraMapping,
            'metadata_json' => ['device' => 'desktop'],
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'created_by' => null,
        ], $overrides);
    }

    /** @param array<int,array<int,mixed>> $rows */
    private function workbook(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'keyword-import-').'.xlsx';
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows);
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
