<?php

namespace App\Services\GeoFlow\KnowledgeGovernance;

use InvalidArgumentException;

final readonly class KnowledgeScope
{
    public string $languageCode;

    public function __construct(
        public int $brandId,
        public int $siteId,
        string $languageCode,
        public ?int $directoryId = null,
        public ?int $keywordClusterId = null,
    ) {
        if ($brandId <= 0 || $siteId <= 0) {
            throw new InvalidArgumentException('Knowledge scope requires positive brand and site IDs.');
        }

        $languageCode = $this->canonicalLanguageTag($languageCode);
        if (preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{1,8})*$/', $languageCode) !== 1) {
            throw new InvalidArgumentException('Knowledge scope requires a valid BCP 47-compatible language code.');
        }

        if ($directoryId !== null && $directoryId <= 0) {
            throw new InvalidArgumentException('Knowledge scope directory ID must be positive.');
        }

        if ($keywordClusterId !== null && $keywordClusterId <= 0) {
            throw new InvalidArgumentException('Knowledge scope keyword cluster ID must be positive.');
        }

        $this->languageCode = $languageCode;
    }

    private function canonicalLanguageTag(string $value): string
    {
        $parts = explode('-', str_replace('_', '-', trim($value)));
        foreach ($parts as $index => &$part) {
            if ($index === 0) {
                $part = strtolower($part);
            } elseif (strlen($part) === 4 && ctype_alpha($part)) {
                $part = ucfirst(strtolower($part));
            } elseif ((strlen($part) === 2 && ctype_alpha($part)) || (strlen($part) === 3 && ctype_digit($part))) {
                $part = strtoupper($part);
            } else {
                $part = strtolower($part);
            }
        }
        unset($part);

        return implode('-', $parts);
    }
}
