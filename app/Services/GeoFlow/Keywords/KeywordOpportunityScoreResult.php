<?php

namespace App\Services\GeoFlow\Keywords;

use DateTimeImmutable;

final readonly class KeywordOpportunityScoreResult
{
    /**
     * @param  array<string,float>  $components
     * @param  array<string,float>  $penalties
     */
    public function __construct(
        public float $total,
        public array $components,
        public array $penalties,
        public string $version,
        public DateTimeImmutable $periodStart,
        public DateTimeImmutable $periodEnd,
    ) {}
}
