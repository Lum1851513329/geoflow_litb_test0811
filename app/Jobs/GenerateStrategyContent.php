<?php

namespace App\Jobs;

use App\Domain\ContentGeneration\ContentGenerationCoordinator;
use App\Models\ContentGenerationRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class GenerateStrategyContent implements ShouldQueue
{
    use Queueable;

    private const FRESH_CLAIM_RELEASE_SECONDS = 1205;

    private const PIPELINE_DISABLED_RELEASE_SECONDS = 300;

    public int $tries = 0;

    public int $maxExceptions = 3;

    public int $timeout = 900;

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
        if (! $this->isPipelineEnabled()) {
            $this->release(self::PIPELINE_DISABLED_RELEASE_SECONDS);

            return;
        }

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

    private function isPipelineEnabled(): bool
    {
        return (bool) config('geoflow.content_strategy_enabled', false)
            && (bool) config('geoflow.modular_prompts_enabled', false)
            && (bool) config('geoflow.generation_quality_gate_enabled', false);
    }
}
