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
        public int $knowledgeBaseId,
        public string $topic,
        public ?string $coreQuestion,
        public ?string $primaryKeyword,
        public array $referenceUrls,
    ) {}

    public function groupKey(): string
    {
        return "{$this->distributionChannelId}:{$this->promptPresetId}:{$this->categoryId}";
    }
}
