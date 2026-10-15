<?php

namespace App\Domain\ContentGeneration;

use App\Models\Article;
use Illuminate\Support\Collection;

/**
 * Renders a "Related reading" Markdown section linking to other already-live
 * litbstyle.com articles.
 *
 * Must be Markdown, not raw HTML: DistributionPayloadBuilder converts
 * Article.content via CommonMark with html_input=>strip before it reaches
 * WordPress, so a pasted <a href> would be silently removed. Markdown link
 * syntax survives that conversion into a real <a href>.
 *
 * Only links to articles with a resolvable wordpressCanonicalUrl() — never
 * reconstructs a URL from the local slug, since WordPress can alter it on
 * collision and the local slug is not authoritative.
 */
final class RelatedArticleLinksRenderer
{
    public const MARKER = '<!-- litb-internal-links -->';

    /**
     * @param  Collection<int, Article>  $relatedArticles
     */
    public function render(Collection $relatedArticles): string
    {
        $items = $relatedArticles
            ->map(function (Article $article): ?string {
                $url = $article->wordpressCanonicalUrl();
                if ($url === null) {
                    return null;
                }

                return '- ['.$this->escapeLinkText($article->title).']('.$url.')';
            })
            ->filter()
            ->values();

        if ($items->isEmpty()) {
            return '';
        }

        return self::MARKER."\n## Related reading\n\n".$items->implode("\n")."\n";
    }

    private function escapeLinkText(string $title): string
    {
        return str_replace(['[', ']'], ['\\[', '\\]'], trim($title));
    }
}
