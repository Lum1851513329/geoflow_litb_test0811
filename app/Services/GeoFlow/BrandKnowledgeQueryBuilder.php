<?php

namespace App\Services\GeoFlow;

use App\Models\ContentBrief;
use App\Models\Title;

final class BrandKnowledgeQueryBuilder
{
    public function forBrief(ContentBrief $brief): string
    {
        $retrievalKeyword = trim((string) ($brief->asset_provenance['retrieval_keyword'] ?? ''));
        if ($retrievalKeyword !== '') {
            return $this->compose([$retrievalKeyword]);
        }

        return $this->compose([
            $brief->primaryKeyword?->keyword,
            $brief->topic,
            $brief->core_question,
            ...($brief->keywordCluster?->keywords()->orderBy('keywords.id')->limit(8)->pluck('keyword')->all() ?? []),
        ]);
    }

    public function forTitle(Title $title): string
    {
        return $this->compose([$title->keyword, $title->title, $title->core_question]);
    }

    private function compose(array $parts): string
    {
        return implode(' ', array_filter(array_map(static fn ($part) => mb_substr(trim((string) $part), 0, 300), $parts)));
    }
}
