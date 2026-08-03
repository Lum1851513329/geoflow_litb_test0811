<?php

namespace App\Jobs;

use App\Events\KeywordImportProgressed;
use App\Models\KeywordImportBatch;
use App\Services\GeoFlow\Keywords\KeywordImportService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ImportKeywordWorkbookJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $batchId) {}

    public function uniqueId(): string
    {
        return (string) $this->batchId;
    }

    /** @return array<int,string> */
    public function tags(): array
    {
        return ['keyword-import', 'keyword-import:'.$this->batchId];
    }

    public function handle(KeywordImportService $service): void
    {
        $batch = KeywordImportBatch::query()->find($this->batchId);
        if (! $batch) {
            return;
        }

        $service->import($batch);
    }

    public function failed(?Throwable $exception = null): void
    {
        $batch = KeywordImportBatch::query()->find($this->batchId);
        if (! $batch || $batch->status === 'completed' || $batch->status === 'reverted') {
            return;
        }

        $message = mb_substr($exception?->getMessage() ?: 'Keyword workbook import failed.', 0, 2000);
        $batch->forceFill(['status' => 'failed', 'error_summary' => $message])->save();
        KeywordImportProgressed::dispatch(
            (int) $batch->id,
            'failed',
            (int) $batch->processed_count,
            (int) $batch->total_count,
            $message,
        );
    }
}
