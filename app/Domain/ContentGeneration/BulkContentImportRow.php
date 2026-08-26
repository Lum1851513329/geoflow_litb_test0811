<?php

namespace App\Domain\ContentGeneration;

final readonly class BulkContentImportRow
{
    /**
     * @param  list<string>  $referenceUrls
     */
    public function __construct(
        public int $rowNumber,
        public int $distributionChannelId,
        public int $promptPresetId,
        public int $categoryId,
        public ?int $knowledgeBaseId,
        public string $topic,
        public ?string $coreQuestion,
        public ?string $primaryKeyword,
        public array $referenceUrls,
        public string $knowledgeMode = 'legacy_manual',
        public ?int $knowledgeBrandId = null,
        public ?int $knowledgeSiteId = null,
        public ?int $knowledgeDirectoryId = null,
    ) {}

    public function groupKey(): string
    {
        return implode(':', [$this->distributionChannelId, $this->promptPresetId, $this->categoryId,
            $this->knowledgeMode, $this->knowledgeBrandId, $this->knowledgeSiteId,
            $this->knowledgeDirectoryId, $this->knowledgeBaseId]);
    }
}
