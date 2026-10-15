<?php

namespace App\Services\GeoFlow\LitbAssets;

final class LitbArchiveResult
{
    public function __construct(
        public readonly string $assetKey,
        public readonly ?string $articlePath,
        public readonly string $evidencePath,
        public readonly string $qualityPath,
    ) {}
}
