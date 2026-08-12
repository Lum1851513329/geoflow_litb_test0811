<?php

namespace App\Services\GeoFlow\LitbAssets;

final class LitbGenerationBatchPreview
{
    /**
     * @param  list<array{asset_key: string, brief_id: int}>  $eligible
     * @param  list<array{asset_key: string, reason: string}>  $skipped
     */
    public function __construct(
        public readonly array $eligible,
        public readonly array $skipped,
    ) {}
}
