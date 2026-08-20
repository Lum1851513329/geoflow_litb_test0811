<?php

namespace App\Services\GeoFlow\KnowledgeGovernance;

use InvalidArgumentException;

final readonly class KnowledgeScope
{
    public function __construct(
        public int $brandId,
        public int $siteId,
        public string $languageCode,
        public ?int $directoryId = null,
        public ?int $keywordClusterId = null,
    ) {
        if ($brandId <= 0 || $siteId <= 0) {
            throw new InvalidArgumentException('Knowledge scope requires positive brand and site IDs.');
        }

        if (trim($languageCode) === '') {
            throw new InvalidArgumentException('Knowledge scope requires a language code.');
        }

        if ($directoryId !== null && $directoryId <= 0) {
            throw new InvalidArgumentException('Knowledge scope directory ID must be positive.');
        }

        if ($keywordClusterId !== null && $keywordClusterId <= 0) {
            throw new InvalidArgumentException('Knowledge scope keyword cluster ID must be positive.');
        }
    }
}
