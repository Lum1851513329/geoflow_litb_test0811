<?php

namespace App\Services\GeoFlow\Keywords;

use App\Models\Keyword;
use App\Models\KeywordNode;
use App\Models\KeywordRelation;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class KeywordClusterSuggestionService
{
    /**
     * Build transient, rule-first cluster candidates. This method never writes to the database.
     *
     * @return list<array<string,mixed>>
     */
    public function suggest(iterable $keywords, array $blockedPrimaryIds = []): array
    {
        $blockedPrimaryIds = array_fill_keys(array_map('intval', $blockedPrimaryIds), true);
        $candidates = [];
        $knownValuesByBoundary = [];

        foreach ($keywords as $keyword) {
            if (! $keyword instanceof Keyword) {
                throw new InvalidArgumentException('Cluster suggestions require Keyword models.');
            }

            $keyword = Keyword::query()->with('taxonomyNodes')->find($keyword->getKey());
            if ($keyword === null || $keyword->status !== 'active') {
                continue;
            }

            $node = $this->taxonomyNode($keyword);
            if ($node === null) {
                continue;
            }

            $boundary = [
                'taxonomy_node_id' => (int) $node->id,
                'language_code' => (string) $keyword->language_code,
                'market_code' => $keyword->market_code,
                'market_scope' => (string) $keyword->market_scope,
                'brand_scope' => $this->brandScope($keyword),
                'intent_group' => $this->intentGroup((string) $keyword->search_intent),
            ];
            $boundaryKey = json_encode($boundary, JSON_THROW_ON_ERROR);
            $value = $this->clusterValue($keyword);
            if ($value === '') {
                continue;
            }
            $candidates[] = [
                'keyword' => $keyword,
                'node' => $node,
                'boundary' => $boundary,
                'boundary_key' => $boundaryKey,
                'value' => $value,
            ];
            $knownValuesByBoundary[$boundaryKey][$value] = true;
        }

        $groups = [];
        foreach ($candidates as $candidate) {
            $stem = $this->conservativeStem(
                $candidate['value'],
                $knownValuesByBoundary[$candidate['boundary_key']],
            );
            $groupKey = json_encode([
                ...$candidate['boundary'],
                'stem' => $stem,
            ], JSON_THROW_ON_ERROR);
            $groups[$groupKey][] = [
                'keyword' => $candidate['keyword'],
                'node' => $candidate['node'],
            ];
        }

        return array_values(array_filter(array_map(
            fn (array $group): ?array => $this->toSuggestion($group, $blockedPrimaryIds),
            $groups,
        )));
    }

    private function taxonomyNode(Keyword $keyword): ?KeywordNode
    {
        $primary = $keyword->taxonomyNodes->first(
            static fn (KeywordNode $node): bool => (bool) $node->pivot->is_primary,
        );
        if ($primary !== null) {
            return $primary;
        }

        return $keyword->taxonomyNodes->count() === 1 ? $keyword->taxonomyNodes->first() : null;
    }

    /**
     * @param  list<array{keyword:Keyword,node:KeywordNode}>  $group
     * @param  array<int,bool>  $blockedPrimaryIds
     * @return array<string,mixed>|null
     */
    private function toSuggestion(array $group, array $blockedPrimaryIds): ?array
    {
        usort($group, static function (array $left, array $right): int {
            $leftPrimary = (bool) $left['node']->pivot->is_primary;
            $rightPrimary = (bool) $right['node']->pivot->is_primary;

            return $leftPrimary === $rightPrimary
                ? $left['keyword']->id <=> $right['keyword']->id
                : ($leftPrimary ? -1 : 1);
        });

        $requiresNonBrandPrimary = collect($group)->contains(
            static fn (array $item): bool => $item['keyword']->brand_relation === 'non_brand'
                && $item['keyword']->keyword_type === 'category',
        ) && collect($group)->contains(
            static fn (array $item): bool => $item['keyword']->brand_relation === 'brand_category'
                && $item['keyword']->keyword_type === 'category',
        );
        $primaryIndex = null;
        foreach ($group as $index => $item) {
            $keyword = $item['keyword'];
            $eligibleForBrandSupportingGroup = ! $requiresNonBrandPrimary
                || ($keyword->brand_relation === 'non_brand' && $keyword->keyword_type === 'category');
            if ($eligibleForBrandSupportingGroup && ! isset($blockedPrimaryIds[(int) $keyword->id])) {
                $primaryIndex = $index;
                break;
            }
        }
        if ($primaryIndex === null) {
            return null;
        }
        $primaryItem = $group[$primaryIndex];
        unset($group[$primaryIndex]);
        array_unshift($group, $primaryItem);
        $group = array_values($group);
        $primary = $group[0]['keyword'];
        $memberships = [];
        foreach ($group as $index => $item) {
            $keyword = $item['keyword'];
            $memberships[] = [
                'keyword_id' => (int) $keyword->id,
                'role' => $this->roleFor($keyword, $primary),
                'weight' => $keyword->is($primary) ? 1.0 : null,
                'sort_order' => $index,
            ];
        }

        usort($memberships, static fn (array $left, array $right): int => $left['sort_order'] <=> $right['sort_order']);

        return [
            'name' => Str::headline((string) $primary->keyword),
            'slug' => Str::slug((string) ($primary->normalized_keyword ?: $primary->keyword)),
            'taxonomy_node_id' => (int) $group[0]['node']->id,
            'primary_keyword_id' => (int) $primary->id,
            'language_code' => (string) $primary->language_code,
            'market_code' => $primary->market_code,
            'market_scope' => (string) $primary->market_scope,
            'search_intent' => (string) $primary->search_intent,
            'brand_relation' => (string) $primary->brand_relation,
            'content_type' => null,
            'keywords' => $memberships,
        ];
    }

    public function roleFor(Keyword $keyword, Keyword $primary): string
    {
        if ($keyword->is($primary)) {
            return 'primary';
        }
        if ((int) $keyword->canonical_keyword_id === (int) $primary->id || $this->hasVariantRelation($keyword, $primary)) {
            return 'variant';
        }
        if ($keyword->keyword_type === 'question') {
            return 'question';
        }
        if ($this->isBrandSupporting($keyword, $primary)) {
            return 'brand_supporting';
        }

        return 'secondary';
    }

    public function isBrandSupporting(Keyword $keyword, Keyword $primary): bool
    {
        return $primary->brand_relation === 'non_brand'
            && $primary->keyword_type === 'category'
            && $keyword->brand_relation === 'brand_category'
            && $keyword->keyword_type === 'category';
    }

    private function brandScope(Keyword $keyword): string
    {
        return ($keyword->brand_relation === 'non_brand'
            || ($keyword->brand_relation === 'brand_category' && $keyword->keyword_type === 'category'))
                ? 'category_demand'
                : (string) $keyword->brand_relation;
    }

    private function clusterValue(Keyword $keyword): string
    {
        $value = $this->normalizeValue((string) ($keyword->normalized_keyword ?: $keyword->keyword));
        if ($keyword->brand_relation !== 'brand_category' || $keyword->keyword_type !== 'category') {
            return $value;
        }

        foreach (config('keyword_intelligence.brand_variants', []) as $variant) {
            $variant = $this->normalizeValue((string) $variant);
            if ($variant === '') {
                continue;
            }
            $value = preg_replace(
                '/(?<![\p{L}\p{N}])'.preg_quote($variant, '/').'(?![\p{L}\p{N}])/u',
                ' ',
                $value,
            ) ?? $value;
        }

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function hasVariantRelation(Keyword $keyword, Keyword $primary): bool
    {
        return KeywordRelation::query()
            ->whereIn('relation_type', ['spelling_variant', 'synonym'])
            ->where(function ($query) use ($keyword, $primary): void {
                $query->where(function ($forward) use ($keyword, $primary): void {
                    $forward->where('source_keyword_id', $keyword->id)
                        ->where('target_keyword_id', $primary->id);
                })->orWhere(function ($reverse) use ($keyword, $primary): void {
                    $reverse->where('source_keyword_id', $primary->id)
                        ->where('target_keyword_id', $keyword->id);
                });
            })
            ->exists();
    }

    private function intentGroup(string $intent): string
    {
        $compatible = config("keyword_intelligence.topic_clustering.compatible_intents.{$intent}", [$intent]);
        $compatible = array_values(array_unique(array_map('strval', is_array($compatible) ? $compatible : [$intent])));
        sort($compatible);

        return implode('|', $compatible);
    }

    /** @param array<string,bool> $knownValues */
    private function conservativeStem(string $value, array $knownValues): string
    {
        $tokens = preg_split('/\s+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === false || $tokens === []) {
            return '';
        }

        $last = array_pop($tokens);
        if (preg_match('/^[a-z]+$/', $last) === 1 && strlen($last) > 3) {
            if (str_ends_with($last, 'ies') && strlen($last) > 4) {
                $ieSingular = substr($last, 0, -1);
                $ieCandidate = implode(' ', [...$tokens, $ieSingular]);
                $yCandidate = implode(' ', [...$tokens, substr($last, 0, -3).'y']);
                $hasIeCandidate = isset($knownValues[$ieCandidate]);
                $hasYCandidate = isset($knownValues[$yCandidate]);
                if ($this->isKnownIeSingular($ieSingular) || ($hasIeCandidate && ! $hasYCandidate)) {
                    $last = $ieSingular;
                } elseif ($hasYCandidate && ! $hasIeCandidate) {
                    $last = substr($last, 0, -3).'y';
                }
            } elseif (preg_match('/(?:sses|xes|zes|ches|shes)$/', $last) === 1) {
                $last = substr($last, 0, -2);
            } elseif (str_ends_with($last, 's') && ! str_ends_with($last, 'ss')) {
                $last = substr($last, 0, -1);
            }
        }
        $tokens[] = $last;

        return implode(' ', $tokens);
    }

    private function isKnownIeSingular(string $value): bool
    {
        foreach (config('keyword_intelligence.topic_clustering.ie_singular_lexicon', []) as $singular) {
            if (is_string($singular) && mb_convert_case(trim($singular), MB_CASE_FOLD, 'UTF-8') === $value) {
                return true;
            }
        }

        return false;
    }

    private function normalizeValue(string $value): string
    {
        $value = mb_convert_case(trim($value), MB_CASE_FOLD, 'UTF-8');
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);

        return $value === null ? '' : trim($value);
    }
}
