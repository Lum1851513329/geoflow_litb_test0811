<?php

namespace App\Services\GeoFlow\Keywords;

final class KeywordColumnMapper
{
    /**
     * @var array<string,array<int,string>>
     */
    private const ALIASES = [
        'keyword' => ['keyword', 'query', 'search_query', 'search_keyword', 'search_term', 'term'],
        'clicks' => ['clicks', 'click'],
        'impressions' => ['impressions', 'impression', 'impr'],
        'ctr' => ['ctr', 'click_through_rate'],
        'position' => ['position', 'average_position', 'avg_position', 'ranking', 'rank'],
        'country' => ['country', 'market', 'region', '国家'],
        'language' => ['language', 'language_code', 'locale', '语言'],
        'date_range' => ['date_range', 'date', 'period'],
        'cost' => ['cost', 'spend', 'amount'],
        'conversions' => ['conversions', 'conversion'],
    ];

    /**
     * @param  array<int,string>  $headers
     * @return array<string,string>
     */
    public function suggest(array $headers, string $sourceType): array
    {
        $available = [];
        foreach ($headers as $header) {
            $available[$this->normalizeHeader($header)] = $header;
        }

        $mapping = [];
        foreach (self::ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                if (isset($available[$alias])) {
                    $mapping[$available[$alias]] = $field;
                    break;
                }
            }
        }

        return $mapping;
    }

    private function normalizeHeader(string $header): string
    {
        $header = mb_strtolower(trim($header), 'UTF-8');
        $header = preg_replace('/[^\p{L}\p{N}]+/u', '_', $header);

        return trim($header ?? '', '_');
    }
}
