<?php

namespace App\Domain\PromptComposition;

final readonly class ResolvedPromptModule
{
    /** @param array<string, mixed> $variableContract */
    public function __construct(
        public int $moduleId,
        public int $versionId,
        public string $moduleKey,
        public string $moduleType,
        public int $priority,
        public bool $required,
        public string $content,
        public array $variableContract,
    ) {}
}
