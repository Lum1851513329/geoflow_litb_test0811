<?php

namespace Tests\Feature;

use App\Events\KeywordImportProgressed;
use App\Jobs\ImportKeywordWorkbookJob;
use App\Models\KeywordImportBatch;
use App\Models\KeywordLibrary;
use App\Services\GeoFlow\Keywords\KeywordImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

class KeywordImportJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_accepts_only_a_batch_id_and_emits_processing_progress_and_completion(): void
    {
        Storage::fake('local');
        Event::fake([KeywordImportProgressed::class]);
        $batch = $this->batch('success');
        $reflection = new ReflectionClass(ImportKeywordWorkbookJob::class);

        $this->assertSame(['batchId'], array_map(
            static fn ($parameter) => $parameter->getName(),
            $reflection->getConstructor()?->getParameters() ?? []
        ));

        (new ImportKeywordWorkbookJob($batch->id))->handle(new KeywordImportService);

        $this->assertSame('completed', $batch->fresh()->status);
        $this->assertSame(5, $batch->fresh()->processed_count);
        Event::assertDispatched(KeywordImportProgressed::class, fn (KeywordImportProgressed $event) => $event->batchId === $batch->id && $event->status === 'processing');
        Event::assertDispatched(KeywordImportProgressed::class, fn (KeywordImportProgressed $event) => $event->batchId === $batch->id && $event->status === 'completed' && $event->processedCount === 5);
    }

    public function test_job_failure_is_retryable_from_the_database_stored_path_and_terminal_failure_is_recorded(): void
    {
        Storage::fake('local');
        Event::fake([KeywordImportProgressed::class]);
        $batch = $this->batch('retry');
        $contents = Storage::disk('local')->get($batch->stored_path);
        Storage::disk('local')->delete($batch->stored_path);
        $job = new ImportKeywordWorkbookJob($batch->id);

        try {
            $job->handle(new KeywordImportService);
            $this->fail('A missing database-controlled workbook should fail.');
        } catch (RuntimeException) {
            $this->assertSame('failed', $batch->fresh()->status);
        }

        Storage::disk('local')->put($batch->stored_path, $contents);
        $job->handle(new KeywordImportService);
        $this->assertSame('completed', $batch->fresh()->status);

        $terminal = $this->batch('terminal', 'terminal_source');
        $terminalJob = new ImportKeywordWorkbookJob($terminal->id);
        $terminalJob->failed(new RuntimeException('terminal import failure'));
        $this->assertSame('failed', $terminal->fresh()->status);
        $this->assertSame('terminal import failure', $terminal->fresh()->error_summary);
        Event::assertDispatched(KeywordImportProgressed::class, fn (KeywordImportProgressed $event) => $event->batchId === $terminal->id && $event->status === 'failed');
    }

    private function batch(string $suffix, string $sourceType = 'search_console'): KeywordImportBatch
    {
        $library = KeywordLibrary::query()->create(['name' => 'Library '.$suffix, 'description' => '', 'keyword_count' => 0]);
        $source = base_path('tests/Fixtures/keywords/category-search-sample.xlsx');

        return (new KeywordImportService)->createBatch([
            'library_id' => $library->id,
            'source_path' => $source,
            'original_filename' => $suffix.'.xlsx',
            'source_type' => $sourceType,
            'mapping_json' => [
                'query' => 'keyword',
                'clicks' => 'clicks',
                'impressions' => 'impressions',
                'ctr' => 'ctr',
                'position' => 'position',
                'country' => 'country',
                'language' => 'language',
                'date_range' => 'date_range',
            ],
            'metadata_json' => [],
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
        ]);
    }
}
