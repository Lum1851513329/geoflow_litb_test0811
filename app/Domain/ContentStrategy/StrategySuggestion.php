<?php

namespace App\Domain\ContentStrategy;

final readonly class StrategySuggestion
{
    /**
     * @param  array<string, mixed>  $strategy
     * @param  list<array{field:string,value:mixed}>  $evidence
     * @param  array<string, mixed>|null  $modelExtension
     */
    public function __construct(
        public array $strategy,
        public float $confidence,
        public array $evidence,
        public ?array $modelExtension = null,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return array_filter([
            'system_suggestion' => $this->strategy,
            'confidence' => $this->confidence,
            'evidence' => $this->evidence,
            'model_extension' => $this->modelExtension,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
