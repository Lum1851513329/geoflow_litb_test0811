<?php

namespace App\Domain\ContentGeneration;

use Illuminate\Support\Collection;

/**
 * Scores how topically related two articles are, so
 * AttachLitbInternalLinksCommand can pick genuine "related reading" targets
 * instead of forcing links between unrelated pieces.
 *
 * category_id alone is a weak signal here: 62 of the 91 live litbstyle.com
 * articles (68%) share one category ("LightInTheBox Guides"), so it's used
 * only as a secondary bonus on top of keyword-token overlap, never as the
 * sole basis for a match — see the two-part gate in match().
 */
final class RelatedArticleMatcher
{
    private const CATEGORY_BONUS = 0.15;

    private const MIN_JACCARD = 0.08;

    private const MIN_TOKENS = 3;

    /** Same connector words ArticleController::keywordTags() splits keywords on, plus common English stopwords. */
    private const STOPWORDS = [
        'the', 'a', 'an', 'to', 'for', 'of', 'and', 'or', 'in', 'on', 'with',
        'your', 'you', 'is', 'are', 'what', 'how', 'before', 'buy', 'buying',
        'check', 'know', 'guide', 'guides', 'best', 'right', 'before you',
        'shop', 'shopping', 'choose', 'choosing', 'tips', 'tip', 'article',
        'this', 'that', 'it', 'do', 'does', 'can', 'should', 'need', 'about',
    ];

    /**
     * @param  Collection<int, object{id:int,title:string,keywords:?string,category_id:?int}>  $pool
     * @return Collection<int, object{id:int,title:string,keywords:?string,category_id:?int}>
     */
    public function match(Collection $pool, object $target, int $limit, float $minScore): Collection
    {
        $targetTokens = $this->tokens($target);
        if (count($targetTokens) < self::MIN_TOKENS) {
            return collect();
        }

        return $pool
            ->reject(fn (object $candidate): bool => (int) $candidate->id === (int) $target->id)
            ->map(function (object $candidate) use ($target, $targetTokens): array {
                $candidateTokens = $this->tokens($candidate);
                $jaccard = $this->jaccard($targetTokens, $candidateTokens);
                $categoryMatch = $target->category_id !== null
                    && $candidate->category_id !== null
                    && (int) $target->category_id === (int) $candidate->category_id;
                $score = min(1.0, $jaccard + ($categoryMatch ? self::CATEGORY_BONUS : 0.0));

                return ['article' => $candidate, 'jaccard' => $jaccard, 'score' => $score];
            })
            // Two-part gate: raw token overlap must clear its own floor so the
            // category bonus can only tip a borderline match over the line,
            // never manufacture one out of zero real overlap.
            ->filter(fn (array $row): bool => $row['jaccard'] >= self::MIN_JACCARD && $row['score'] >= $minScore)
            ->sortBy([
                fn (array $a, array $b): int => $b['score'] <=> $a['score'],
                fn (array $a, array $b): int => $a['article']->id <=> $b['article']->id,
            ])
            ->take(max(1, $limit))
            ->map(fn (array $row): object => $row['article'])
            ->values();
    }

    /** @return array<string,bool> */
    private function tokens(object $article): array
    {
        $keywordText = (string) ($article->keywords ?? '');
        $keywordParts = $keywordText === '' ? [] : (preg_split('/[,，、\n]+/u', $keywordText) ?: []);
        $titleWords = preg_split('/[^\p{L}\p{N}]+/u', (string) $article->title) ?: [];

        $tokens = [];
        foreach (array_merge($keywordParts, $titleWords) as $raw) {
            $token = mb_strtolower(trim((string) $raw));
            if ($token === '' || mb_strlen($token) < 2 || in_array($token, self::STOPWORDS, true)) {
                continue;
            }
            $tokens[$token] = true;
        }

        return $tokens;
    }

    /**
     * @param  array<string,bool>  $a
     * @param  array<string,bool>  $b
     */
    private function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $intersection = count(array_intersect_key($a, $b));
        $union = count($a) + count($b) - $intersection;

        return $union > 0 ? $intersection / $union : 0.0;
    }
}
