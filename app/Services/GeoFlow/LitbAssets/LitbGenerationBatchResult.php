<?php

namespace App\Services\GeoFlow\LitbAssets;

final class LitbGenerationBatchResult
{
    /**
     * @param  list<int>  $runIds
     * @param  list<int>  $existingRunIds
     * @param  list<array{asset_key: string, reason: string}>  $skipped
     */
    public function __construct(
        public readonly array $runIds,
        public readonly array $existingRunIds,
        public readonly array $skipped,
    ) {}
}
