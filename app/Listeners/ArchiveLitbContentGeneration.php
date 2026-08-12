<?php

namespace App\Listeners;

use App\Events\ContentGenerationCompleted;
use App\Events\ContentGenerationFailed;
use App\Models\ContentGenerationRun;
use App\Services\GeoFlow\LitbAssets\LitbArticleArchiveWriter;
use App\Services\GeoFlow\LitbAssets\LitbAssetConfig;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class ArchiveLitbContentGeneration implements ShouldQueue
{
    use InteractsWithQueue;

    /** @var array<int,string> */
    private const CONTENT_BEARING_STATUSES = [
        ContentGenerationRun::STATUS_READY_FOR_REVIEW,
        ContentGenerationRun::STATUS_COMPLETED,
        ContentGenerationRun::STATUS_ADOPTED,
    ];

    public function __construct(
        private readonly LitbArticleArchiveWriter $writer,
        private readonly LitbAssetConfig $config,
    ) {}

    public function uniqueId(ContentGenerationCompleted|ContentGenerationFailed $event): string
    {
        return 'archive-litb-content-generation:'.$event->runId;
    }

    public function handleCompleted(ContentGenerationCompleted $event): void
    {
        $this->archive($event->runId);
    }

    public function handleFailed(ContentGenerationFailed $event): void
    {
        $this->archive($event->runId);
    }

    private function archive(int $runId): void
    {
        $run = ContentGenerationRun::query()->with('contentBrief')->find($runId);
        if ($run === null) {
            return;
        }
        $assetKey = (string) ($run->contentBrief?->asset_key ?? '');
        if (! Str::startsWith($assetKey, $this->config->quarterKey().'.')) {
            return;
        }

        try {
            if (in_array((string) $run->status, self::CONTENT_BEARING_STATUSES, true)) {
                $this->writer->write($run);
            } else {
                $this->writer->writeDiagnostics($run);
            }
        } catch (InvalidArgumentException $exception) {
            Log::warning('LITB content archive skipped for an ineligible run.', [
                'content_generation_run_id' => $runId,
                'reason' => $exception->getMessage(),
            ]);
        } catch (Throwable $exception) {
            Log::error('LITB content archive failed.', [
                'content_generation_run_id' => $runId,
                'exception' => $exception,
            ]);
        }
    }
}
