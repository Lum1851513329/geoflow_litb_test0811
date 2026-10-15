<?php

namespace App\Console\Commands;

use App\Domain\ContentGeneration\RelatedArticleLinksRenderer;
use App\Domain\ContentGeneration\RelatedArticleMatcher;
use App\Models\Article;
use App\Models\DistributionChannel;
use App\Services\GeoFlow\DistributionOrchestrator;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Attach a "Related reading" internal-links section to already-published,
 * already-synced litbstyle.com articles.
 *
 * There is currently no internal linking on litbstyle.com at all — the
 * litb.seo.links_cta prompt module tells the model to "recommend only
 * supplied internal links," but nothing has ever supplied any. This command
 * fills that gap retroactively over already-live content, matching articles
 * by keyword-token overlap (category_id alone is too weak a signal: most
 * live articles share one category) via RelatedArticleMatcher, then appends
 * a Markdown "Related reading" list via RelatedArticleLinksRenderer.
 *
 * Content must stay Markdown, not raw HTML: DistributionPayloadBuilder
 * converts Article.content via CommonMark with html_input=>strip before it
 * reaches WordPress, which would silently strip a pasted <a href>.
 *
 * Mirrors AttachLitbProductCardsCommand's precedent deliberately: the
 * content edit goes through a raw DB update, bypassing
 * ArticleGeoFlowService/risk-scanning/workflow-state, because this is
 * additive-only (a link list is appended, existing prose is never touched)
 * — the same narrow condition that command's bypass was built for.
 *
 * Idempotent via a marker string; safe to re-run. Dry run by default —
 * pass --apply to write, which also re-enqueues just the touched article to
 * the channel (DistributionOrchestrator::enqueueForArticleToChannel(), not
 * the channel-wide enqueueChannelContentRefresh()) so the links actually
 * reach the live site rather than sitting inert locally.
 */
final class AttachLitbInternalLinksCommand extends Command
{
    protected $signature = 'geoflow:litb:attach-internal-links
        {--channel=3 : distribution channel id to link within}
        {--article=* : restrict to specific article ids}
        {--links=3 : maximum related links per article}
        {--min-score=0.12 : minimum match score required to link two articles}
        {--limit=100 : maximum articles to process in one run}
        {--apply : write the links; without it the run is a dry run}';

    protected $description = 'Insert a Related reading section linking already-published litbstyle.com articles to each other';

    public function handle(RelatedArticleMatcher $matcher, RelatedArticleLinksRenderer $renderer, DistributionOrchestrator $orchestrator): int
    {
        $channelId = (int) $this->option('channel');
        $channel = DistributionChannel::query()->find($channelId);
        if (! $channel) {
            $this->error("Distribution channel {$channelId} not found.");

            return self::FAILURE;
        }

        $linksPerArticle = max(1, (int) $this->option('links'));
        $minScore = (float) $this->option('min-score');
        $apply = (bool) $this->option('apply');

        $pool = $this->eligiblePool($channelId);
        if ($pool->isEmpty()) {
            $this->info('No eligible synced articles without existing internal links found.');

            return self::SUCCESS;
        }

        $targets = $this->targetArticles($pool);
        $this->line(sprintf('%s %d article(s) against a pool of %d · min score %.2f',
            $apply ? 'Processing' : 'Dry run over', $targets->count(), $pool->count(), $minScore));

        $attached = 0;
        foreach ($targets as $article) {
            try {
                $attached += $this->processArticle($article, $pool, $matcher, $renderer, $linksPerArticle, $minScore, $apply, $channel, $orchestrator) ? 1 : 0;
            } catch (Throwable $exception) {
                $this->error(sprintf('article %d: %s', $article->id, $exception->getMessage()));
            }
        }

        $this->info(sprintf('%s %d article(s).', $apply ? 'Attached links to' : 'Would attach links to', $attached));
        if (! $apply) {
            $this->line('Pass --apply to write.');
        }

        return self::SUCCESS;
    }

    /**
     * Every article eligible to be a link TARGET: published, synced to this
     * channel (so wordpressCanonicalUrl() resolves), not soft-deleted.
     * Deliberately does not exclude marker-tagged articles here — they can
     * still be linked TO by others even if they've already been processed
     * as a linker themselves.
     *
     * @return Collection<int, Article>
     */
    private function eligiblePool(int $channelId): Collection
    {
        return Article::query()
            ->where('status', 'published')
            ->whereHas('syncedRemoteDistributions', fn ($query) => $query->where('distribution_channel_id', $channelId))
            ->get(['id', 'title', 'keywords', 'category_id']);
    }

    /**
     * The subset of the pool this run will actually process as linkers:
     * same eligibility, minus the idempotency guard, optionally narrowed by
     * --article, capped by --limit.
     *
     * @param  Collection<int, Article>  $pool
     * @return Collection<int, Article>
     */
    private function targetArticles(Collection $pool): Collection
    {
        $ids = array_map('intval', (array) $this->option('article'));
        $markerLess = Article::query()
            ->whereIn('id', $pool->pluck('id'))
            ->where('content', 'not like', '%'.RelatedArticleLinksRenderer::MARKER.'%')
            ->when($ids !== [], fn ($query) => $query->whereIn('id', $ids))
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->pluck('id')
            ->all();

        return $pool->whereIn('id', $markerLess)->values();
    }

    /**
     * @param  Collection<int, Article>  $pool
     */
    private function processArticle(
        Article $article,
        Collection $pool,
        RelatedArticleMatcher $matcher,
        RelatedArticleLinksRenderer $renderer,
        int $linksPerArticle,
        float $minScore,
        bool $apply,
        DistributionChannel $channel,
        DistributionOrchestrator $orchestrator,
    ): bool {
        $related = $matcher->match($pool, $article, $linksPerArticle, $minScore);
        if ($related->isEmpty()) {
            $this->line(sprintf('  article %-5d "%s" · no match above threshold', $article->id, $article->title));

            return false;
        }

        $markdown = $renderer->render($related);
        if ($markdown === '') {
            $this->line(sprintf('  article %-5d "%s" · matched but none have a live URL yet', $article->id, $article->title));

            return false;
        }

        $this->line(sprintf('  article %-5d "%s" -> %s', $article->id, $article->title, $related->pluck('title')->implode(' | ')));

        if (! $apply) {
            return true;
        }

        DB::table('articles')->where('id', $article->id)->update([
            'content' => rtrim((string) $article->content)."\n\n".$markdown,
            'updated_at' => now(),
        ]);

        // Scoped to this one article — NOT enqueueChannelContentRefresh(),
        // which re-queues every distribution on the channel and would
        // temporarily pull the whole pool out of syncedRemoteDistributions()
        // (status flips away from 'synced' while queued) for no reason.
        $orchestrator->enqueueForArticleToChannel($article, $channel, 'update');

        return true;
    }
}
