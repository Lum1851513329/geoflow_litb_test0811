<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Services\GeoFlow\DistributionOrchestrator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Retroactive cleanup for a long-standing EvidenceCitationRenderer bug (fixed
 * 2026-09-23, see that class): every [[evidence:id]] marker in generated
 * prose was independently expanded into a full "[source title](url)" link,
 * so a fact cited on N bullets produced the identical link repeated N times
 * inline, on top of the same source already listed once in the "## Sources"
 * footer. The renderer fix only affects future generations — this command
 * cleans up articles that already have the old, already-published output
 * baked into their content.
 *
 * Only strips an inline link if its URL also appears in that same article's
 * "## Sources" list — that is the precise signature of a leftover citation
 * duplicate. A link whose URL is NOT in the Sources list (e.g. a branded
 * "Explore LightInTheBox" call-to-action elsewhere in the body) is left
 * alone; not every inline link in these articles is a citation artifact.
 *
 * Mirrors AttachLitbProductCardsCommand/AttachLitbInternalLinksCommand's
 * precedent: a raw DB update, bypassing ArticleGeoFlowService/risk-scanning
 * (this only removes redundant text, never adds or alters a claim), dry run
 * by default, --apply to write.
 */
final class StripInlineCitationLinksCommand extends Command
{
    protected $signature = 'geoflow:litb:strip-inline-citation-links
        {--article=* : restrict to specific article ids}
        {--limit=200 : maximum articles to process in one run}
        {--apply : write the cleanup; without it the run is a dry run}';

    protected $description = 'Remove inline evidence-citation links from article bodies that duplicate a link already listed in the Sources footer';

    public function handle(DistributionOrchestrator $orchestrator): int
    {
        $apply = (bool) $this->option('apply');
        $articles = $this->candidateArticles();

        if ($articles->isEmpty()) {
            $this->info('No published articles with a Sources footer found.');

            return self::SUCCESS;
        }

        $this->line(sprintf('%s %d article(s)', $apply ? 'Processing' : 'Dry run over', $articles->count()));

        $cleaned = 0;
        foreach ($articles as $article) {
            try {
                $cleaned += $this->processArticle($article, $apply, $orchestrator) ? 1 : 0;
            } catch (Throwable $exception) {
                $this->error(sprintf('article %d: %s', $article->id, $exception->getMessage()));
            }
        }

        $this->info(sprintf('%s %d article(s).', $apply ? 'Cleaned' : 'Would clean', $cleaned));
        if (! $apply) {
            $this->line('Pass --apply to write.');
        }

        return self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int, Article> */
    private function candidateArticles(): \Illuminate\Support\Collection
    {
        $ids = array_map('intval', (array) $this->option('article'));

        return Article::query()
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->where('content', 'like', '%## Sources%')
            ->when($ids !== [], fn ($query) => $query->whereIn('id', $ids))
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get(['id', 'title', 'content']);
    }

    private function processArticle(Article $article, bool $apply, DistributionOrchestrator $orchestrator): bool
    {
        [$head, $tail] = $this->splitAtFooter((string) $article->content);
        if ($tail === '') {
            return false;
        }

        $sourceUrls = $this->sourceUrls($tail);
        if ($sourceUrls === []) {
            return false;
        }

        $newHead = preg_replace_callback(
            '/\[[^\]\n]{3,150}\]\((https?:\/\/[^\s)]+)\)/',
            function (array $match) use ($sourceUrls): string {
                return in_array($match[1], $sourceUrls, true) ? '' : $match[0];
            },
            $head,
        );
        if ($newHead === null || $newHead === $head) {
            return false;
        }
        $newHead = preg_replace('/[ \t]+([.,;:!?])/', '$1', $newHead) ?? $newHead;
        $newHead = preg_replace('/[ \t]{2,}/', ' ', $newHead) ?? $newHead;

        $linksRemoved = substr_count($head, '](') - substr_count($newHead, '](');
        $this->line(sprintf('  article %-5d "%s" · removing %d inline citation link(s)', $article->id, $article->title, $linksRemoved));

        if (! $apply) {
            return true;
        }

        DB::table('articles')->where('id', $article->id)->update([
            'content' => rtrim($newHead)."\n\n".$tail,
            'updated_at' => now(),
        ]);

        $article->refresh();
        foreach ($article->syncedRemoteDistributions as $distribution) {
            if ($distribution->channel !== null) {
                $orchestrator->enqueueForArticleToChannel($article, $distribution->channel, 'update');
            }
        }

        return true;
    }

    /**
     * Splits at the first "## Sources" (or "## Related reading", whichever
     * comes first) heading, so the Sources list and any internal-links
     * section stay untouched — only the main body is a candidate for
     * stripping.
     *
     * @return array{0:string,1:string} [$head, $tail] — $tail includes the heading itself
     */
    private function splitAtFooter(string $content): array
    {
        if (preg_match('/\n(##\s+(?:Sources|Related reading)\b.*)$/is', $content, $match, PREG_OFFSET_CAPTURE)) {
            $offset = $match[0][1];

            return [substr($content, 0, $offset), ltrim(substr($content, $offset))];
        }

        return [$content, ''];
    }

    /** @return list<string> */
    private function sourceUrls(string $tail): array
    {
        preg_match_all('/\]\((https?:\/\/[^\s)]+)\)/', $tail, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }
}
