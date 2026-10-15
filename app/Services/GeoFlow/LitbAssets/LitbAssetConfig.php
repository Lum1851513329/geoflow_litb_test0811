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
        $quarterKey = trim((string) config('litb_content_assets.quarter_key'));
        $archiveRoot = trim((string) config('litb_content_assets.archive_root'));

        if (preg_match('/^[a-z0-9][a-z0-9._-]{2,189}$/D', $quarterKey) !== 1) {
            throw new InvalidArgumentException('litb_assets.quarter_key_invalid');
        }
        if ($archiveRoot === '' || str_contains($archiveRoot, "\0")) {
            throw new InvalidArgumentException('litb_assets.archive_root_invalid');
        }

        return new self(
            knowledgeRoot: (string) config('litb_content_assets.knowledge_root'),
            keywordRoot: (string) config('litb_content_assets.keyword_root'),
            quarterKey: $quarterKey,
            archiveRoot: str_starts_with($archiveRoot, DIRECTORY_SEPARATOR)
                ? $archiveRoot
                : base_path($archiveRoot),
        );
    }

    /**
     * Validated lazily: knowledge scanning needs this directory to exist, but
     * archiving and generation (quarterKey/archiveRoot only) do not.
     */
    public function knowledgeRoot(): string
    {
        $resolved = realpath($this->knowledgeRoot);
        if ($resolved === false || ! is_dir($resolved)) {
            throw new InvalidArgumentException('litb_assets.knowledge_root_missing');
        }

        return $resolved;
    }

    /**
     * Validated lazily: keyword import needs this directory to exist, but
     * archiving and generation (quarterKey/archiveRoot only) do not.
     */
    public function keywordRoot(): string
    {
        $resolved = realpath($this->keywordRoot);
        if ($resolved === false || ! is_dir($resolved)) {
            throw new InvalidArgumentException('litb_assets.keyword_root_missing');
        }

        return $resolved;
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
