<?php

namespace App\Services\GeoFlow\Keywords;

final class KeywordClassifier
{
    /**
     * @return array{keyword_type:string,search_intent:string,brand_relation:string}
     */
    public function classify(NormalizedKeyword $keyword): array
    {
        $value = $keyword->normalized;
        $isBrandKeyword = $this->containsAnyPhrase($value, config('keyword_intelligence.brand_variants', []));
        $isExactBrandKeyword = $this->isExactBrandVariant($value);

        $intentPhrases = config('keyword_intelligence.intent_phrases', []);
        $hasTransactionalIntent = $this->containsAnyPhrase($value, $intentPhrases['transactional'] ?? []);
        $hasTrustIntent = $this->containsAnyPhrase($value, $intentPhrases['trust'] ?? []);
        $hasSupportIntent = $this->containsAnyPhrase($value, $intentPhrases['support'] ?? []);
        $hasComparisonIntent = $this->containsAnyPhrase($value, $intentPhrases['comparison'] ?? []);
        $hasInformationalIntent = $this->containsAnyPhrase($value, $intentPhrases['informational'] ?? []);

        if ($hasTransactionalIntent) {
            $searchIntent = 'transactional';
        } elseif ($hasTrustIntent || $hasInformationalIntent) {
            $searchIntent = 'informational';
        } elseif ($hasSupportIntent) {
            $searchIntent = 'support';
        } elseif ($isExactBrandKeyword) {
            $searchIntent = 'navigational';
        } else {
            $searchIntent = 'commercial';
        }

        if ($hasTrustIntent) {
            $keywordType = 'trust';
        } elseif ($hasComparisonIntent) {
            $keywordType = 'comparison';
        } elseif ($hasSupportIntent) {
            $keywordType = 'navigation';
        } elseif ($hasInformationalIntent) {
            $keywordType = 'question';
        } elseif ($isExactBrandKeyword || ($isBrandKeyword && $hasTransactionalIntent)) {
            $keywordType = 'brand';
        } else {
            $keywordType = 'category';
        }

        if ($hasComparisonIntent && $isBrandKeyword) {
            $brandRelation = 'competitor_comparison';
        } elseif (! $isBrandKeyword) {
            $brandRelation = 'non_brand';
        } elseif ($keywordType === 'category') {
            $brandRelation = 'brand_category';
        } else {
            $brandRelation = 'branded';
        }

        return [
            'keyword_type' => $keywordType,
            'search_intent' => $searchIntent,
            'brand_relation' => $brandRelation,
        ];
    }

    /**
     * @param  array<int,string>  $phrases
     */
    private function containsAnyPhrase(string $value, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            $phrase = $this->normalizePhrase($phrase);
            if ($phrase === '') {
                continue;
            }

            $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($phrase, '/').'(?![\p{L}\p{N}])/u';
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isExactBrandVariant(string $value): bool
    {
        foreach (config('keyword_intelligence.brand_variants', []) as $brandVariant) {
            if ($value === $this->normalizePhrase($brandVariant)) {
                return true;
            }
        }

        return false;
    }

    private function normalizePhrase(string $phrase): string
    {
        $phrase = preg_replace('/\s+/u', ' ', trim($phrase));

        return $phrase === null ? '' : mb_strtolower($phrase, 'UTF-8');
    }
}
