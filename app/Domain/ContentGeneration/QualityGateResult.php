<?php

namespace App\Domain\ContentGeneration;

final readonly class QualityGateResult
{
    /** @param list<QualityIssue> $issues */
    public function __construct(
        public bool $passed,
        public int $score,
        public array $issues,
    ) {}

    /** @return list<array{code: string, severity: string, section_key: string|null, message: string, repair_instruction: string}> */
    public function issuesAsArray(): array
    {
        return array_map(static fn (QualityIssue $issue): array => $issue->toArray(), $this->issues);
    }
}
