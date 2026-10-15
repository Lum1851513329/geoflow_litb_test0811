<?php

namespace App\Jobs;

use App\Events\KeywordImportProgressed;
use App\Models\KeywordImportBatch;
use App\Services\GeoFlow\Keywords\KeywordImportService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        $message = mb_substr($exception?->getMessage() ?: 'Keyword workbook import failed.', 0, 2000);
        $batch = DB::transaction(function () use ($message): ?KeywordImportBatch {
            $batch = KeywordImportBatch::query()->lockForUpdate()->find($this->batchId);
            if (! $batch || in_array($batch->status, ['completed', 'reverted', 'reverting'], true)) {
                return null;
            }
            $batch->forceFill(['status' => 'failed', 'error_summary' => $message])->save();

            return $batch;
        });
        if (! $batch) {
            return;
        }

        $dispatch = static function () use ($batch, $message): void {
            try {
                KeywordImportProgressed::dispatch(
                    (int) $batch->id,
                    'failed',
                    (int) $batch->processed_count,
                    (int) $batch->total_count,
                    $message,
                );
            } catch (Throwable $eventException) {
                Log::warning('Keyword import failure progress event failed.', [
                    'batch_id' => $batch->id,
                    'exception' => $eventException,
                ]);
            }
        };
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);
        } else {
            $dispatch();
        }
    }
}
