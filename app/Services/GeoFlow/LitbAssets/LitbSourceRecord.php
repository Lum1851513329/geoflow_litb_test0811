<?php

namespace App\Services\GeoFlow\LitbAssets;

final readonly class LitbSourceRecord
{
    public function __construct(
        public string $sourceKey,
        public string $absolutePath,
        public string $relativePath,
        public string $sha256,
        public string $title,
        public string $languageCode,
        public string $groupKey,
        public string $sourceType,
        public string $riskLevel,
        public string $reviewStatus,
        public bool $timeSensitive,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source_key' => $this->sourceKey,
            'relative_path' => $this->relativePath,
            'sha256' => $this->sha256,
            'title' => $this->title,
            'language_code' => $this->languageCode,
            'group_key' => $this->groupKey,
            'source_type' => $this->sourceType,
            'risk_level' => $this->riskLevel,
            'review_status' => $this->reviewStatus,
            'time_sensitive' => $this->timeSensitive,
        ];
    }
}
