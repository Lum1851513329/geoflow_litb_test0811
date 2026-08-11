<?php

namespace App\Services\GeoFlow\LitbAssets;

use InvalidArgumentException;

final readonly class LitbAssetConfig
{
    public function __construct(
        private string $knowledgeRoot,
        private string $keywordRoot,
        private string $quarterKey,
        private string $archiveRoot,
    ) {}

    public static function fromConfig(): self
    {
        $knowledgeRoot = realpath((string) config('litb_content_assets.knowledge_root'));
        $keywordRoot = realpath((string) config('litb_content_assets.keyword_root'));
        $quarterKey = trim((string) config('litb_content_assets.quarter_key'));
        $archiveRoot = trim((string) config('litb_content_assets.archive_root'));

        if ($knowledgeRoot === false || ! is_dir($knowledgeRoot)) {
            throw new InvalidArgumentException('litb_assets.knowledge_root_missing');
        }
        if ($keywordRoot === false || ! is_dir($keywordRoot)) {
            throw new InvalidArgumentException('litb_assets.keyword_root_missing');
        }
        if (preg_match('/^[a-z0-9][a-z0-9._-]{2,189}$/D', $quarterKey) !== 1) {
            throw new InvalidArgumentException('litb_assets.quarter_key_invalid');
        }
        if ($archiveRoot === '' || str_contains($archiveRoot, "\0")) {
            throw new InvalidArgumentException('litb_assets.archive_root_invalid');
        }

        return new self(
            knowledgeRoot: $knowledgeRoot,
            keywordRoot: $keywordRoot,
            quarterKey: $quarterKey,
            archiveRoot: str_starts_with($archiveRoot, DIRECTORY_SEPARATOR)
                ? $archiveRoot
                : base_path($archiveRoot),
        );
    }

    public function knowledgeRoot(): string
    {
        return $this->knowledgeRoot;
    }

    public function keywordRoot(): string
    {
        return $this->keywordRoot;
    }

    public function quarterKey(): string
    {
        return $this->quarterKey;
    }

    public function archiveRoot(): string
    {
        return $this->archiveRoot;
    }
}
