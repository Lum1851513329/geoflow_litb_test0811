<?php

namespace App\Services\GeoFlow\Semrush;

use App\Models\Article;
use Illuminate\Support\Collection;

/**
 * Flags Semrush topic-gap rows that likely duplicate an article GEOFlow has
 * already written. Semrush re-exports periodically and the same underlying
 * topic often comes back worded differently each time ("How to Convert
 * Centimeters to Inches" vs "Cm to Inches Conversion: The Exact Formula"),
 * so this can't be an exact-title lookup — it tokenizes the topic + primary
 * keyword and scores Jaccard overlap against every existing article's
 * title + keywords + original_keyword, same approach as
 * RelatedArticleMatcher uses for internal linking.
 *
 * This only flags for the admin to review in the preview step
 * (SemrushImportController::preview()) — it never excludes a row on its
 * own; a heuristic text-overlap score can false-positive on a genuinely
 * distinct topic that just shares vocabulary.
 */
final class SemrushDuplicateTopicDetector
{
    private const MIN_JACCARD = 0.34;

    private const MAX_MATCHES = 3;

    /** Same list RelatedArticleMatcher uses, so a row and an article tokenize the same way. */
    private const STOPWORDS = [
        'the', 'a', 'an', 'to', 'for', 'of', 'and', 'or', 'in', 'on', 'with',
        'your', 'you', 'is', 'are', 'what', 'how', 'before', 'buy', 'buying',
        'check', 'know', 'guide', 'guides', 'best', 'right', 'before you',
        'shop', 'shopping', 'choose', 'choosing', 'tips', 'tip', 'article',
        'this', 'that', 'it', 'do', 'does', 'can', 'should', 'need', 'about',
    ];

    /**
     * @param  list<array{topic:string,primary_keyword:string,category:string,preset_key:string,score:float,volume:int,difficulty:int,core_question:string}>  $rows
     * @return list<array{topic:string,primary_keyword:string,category:string,preset_key:string,score:float,volume:int,difficulty:int,core_question:string,possible_duplicate:bool,duplicate_matches:list<array{id:int,title:string,score:float}>}>
     */
    public function annotate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $articles = $this->articleTokenIndex();

        return array_map(function (array $row) use ($articles): array {
            $rowTokens = $this->tokens($row['topic'].' '.$row['primary_keyword']);

            $matches = $articles
                ->map(fn (array $article): array => [
                    'id' => $article['id'],
                    'title' => $article['title'],
                    'score' => $this->jaccard($rowTokens, $article['tokens']),
                ])
                ->filter(fn (array $match): bool => $match['score'] >= self::MIN_JACCARD)
                ->sortByDesc('score')
                ->take(self::MAX_MATCHES)
                ->values()
                ->all();

            $row['possible_duplicate'] = $matches !== [];
            $row['duplicate_matches'] = $matches;

            return $row;
        }, $rows);
    }

    /** @return Collection<int, array{id:int,title:string,tokens:array<string,bool>}> */
    private function articleTokenIndex(): Collection
    {
        return Article::query()
            ->select(['id', 'title', 'keywords', 'original_keyword'])
            ->get()
            ->map(fn (Article $article): array => [
                'id' => $article->id,
                'title' => $article->title,
                'tokens' => $this->tokens(implode(' ', array_filter([
                    $article->title,
                    $article->keywords,
                    $article->original_keyword,
                ]))),
            ]);
    }

    /** @return array<string,bool> */
    private function tokens(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [];

        $tokens = [];
        foreach ($words as $word) {
            if ($word === '' || mb_strlen($word) < 2 || in_array($word, self::STOPWORDS, true)) {
                continue;
            }
            $tokens[$word] = true;
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
