<?php

namespace App\Jobs;

use App\Domain\ContentGeneration\ContentGenerationCoordinator;
use App\Models\ContentGenerationRun;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class GenerateStrategyContent implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    private const FRESH_CLAIM_RELEASE_SECONDS = 1205;

    public int $tries = 3;

    public int $timeout = 900;

    public int $uniqueFor = 1800;

    public function __construct(public readonly int $runId) {}

    public function uniqueId(): string
    {
        return (string) (ContentGenerationRun::query()
            ->whereKey($this->runId)
            ->value('idempotency_key') ?: $this->runId);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['content-generation', 'content-generation:'.$this->runId];
    }

    public function handle(ContentGenerationCoordinator $coordinator): void
    {
        $run = ContentGenerationRun::query()->find($this->runId);
        if ($run === null) {
            return;
        }

        $result = $coordinator->generate($run);
        if (in_array($result->status, [
            ContentGenerationRun::STATUS_COMPILING,
            ContentGenerationRun::STATUS_GENERATING,
            ContentGenerationRun::STATUS_RUNNING,
        ], true)) {
            $this->release(self::FRESH_CLAIM_RELEASE_SECONDS);

            return;
        }
        if ($result->status === ContentGenerationRun::STATUS_FAILED) {
            throw new \RuntimeException('Content generation failed and is eligible for a queue retry.');
        }
    }
}
