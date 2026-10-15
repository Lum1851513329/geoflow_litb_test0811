<?php

namespace App\Domain\PromptComposition;

final readonly class CompiledPrompt
{
    /**
     * @param  list<array<string, mixed>>  $moduleSnapshots
     * @param  array<string, mixed>  $resolvedVariables
     * @param  list<array{code: string, winner: string, loser: string, reason: string, affected_module_version_ids: list<int>}>  $conflictDecisions
     */
    public function __construct(
        public string $text,
        public string $modelPayload,
        public array $sensitiveValues,
        public array $moduleSnapshots,
        public array $resolvedVariables,
        public array $conflictDecisions,
        public int $estimatedTokens,
        public string $contextDigest,
    ) {}
}
