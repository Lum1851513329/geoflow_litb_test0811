<?php

namespace App\Domain\ContentGeneration;

use App\Jobs\GenerateStrategyContent;
use App\Models\ContentGenerationRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;

final class ContentGenerationDispatcher
{
    public function dispatch(ContentGenerationRun $run): bool
    {
        $queueConnection = (string) config('geoflow.content_generation_queue_connection', 'database');
        $queueConfig = config("queue.connections.{$queueConnection}");
        $queueDatabase = is_array($queueConfig) ? ($queueConfig['connection'] ?? config('database.default')) : null;
        if (! is_array($queueConfig)
            || ($queueConfig['driver'] ?? null) !== 'database'
            || $queueDatabase !== $run->getConnection()->getName()) {
            throw new LogicException('Content generation requires a database queue on the run database connection.');
        }

        return DB::transaction(function () use ($run): bool {
            $locked = ContentGenerationRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($locked->dispatch_enqueued_at !== null
                || ! in_array((string) $locked->status, [
                    ContentGenerationRun::STATUS_PENDING,
                    ContentGenerationRun::STATUS_QUEUED,
                ], true)) {
                return false;
            }

            Queue::connection((string) config('geoflow.content_generation_queue_connection', 'database'))
                ->push(
                    new GenerateStrategyContent((int) $locked->id),
                    '',
                    (string) config('geoflow.content_generation_queue', 'geoflow'),
                );
            $locked->forceFill(['dispatch_enqueued_at' => now()])->save();

            return true;
        });
    }

    public function recoverStale(int $staleSeconds = 300, int $limit = 100): int
    {
        $ids = ContentGenerationRun::query()
            ->whereNull('dispatch_enqueued_at')
            ->whereIn('status', [ContentGenerationRun::STATUS_PENDING, ContentGenerationRun::STATUS_QUEUED])
            ->where('updated_at', '<=', now()->subSeconds(max(1, $staleSeconds)))
            ->orderBy('id')
            ->limit(max(1, min(1000, $limit)))
            ->pluck('id');
        $recovered = 0;
        foreach ($ids as $id) {
            $run = ContentGenerationRun::query()->find($id);
            if ($run !== null && $this->dispatch($run)) {
                $recovered++;
            }
        }

        return $recovered;
    }
}
