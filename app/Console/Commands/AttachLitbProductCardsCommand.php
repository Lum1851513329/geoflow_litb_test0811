<?php

namespace App\Console\Commands;

use App\Domain\ContentGeneration\LitbProductCardRenderer;
use App\Domain\ContentGeneration\LitbProductEvidenceCollector;
use App\Models\Article;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Attach LightInTheBox product cards to shoppable articles.
 *
 * Scope is decided by the task's prompt preset, not by scanning the article text: buying
 * guides, product comparisons and new-arrivals pieces are shoppable; category authority
 * and brand explainers are not, and forcing cards onto them reads as promotion.
 *
 * Products come from a live search of lightinthebox.com. Candidates at or above
 * --min-relevance are auto-approved; anything below is written to the evidence file for
 * review but never rendered. That relaxes the manual-approval rule in
 * docs/superpowers/specs/2026-08-25-wordpress-product-card-design.md, so the threshold is
 * the safeguard - lower it deliberately, not by accident.
 *
 * Cards are appended to the article body without introducing a new top-level heading, so
 * the blueprint section count the deterministic quality gate checks stays unchanged.
 *
 * Evidence and the render payload are written to storage/app/private/litb-product-cards/
 * so every published card can be traced back to the page snapshot it came from.
 */
final class AttachLitbProductCardsCommand extends Command
{
    protected $signature = 'geoflow:litb:attach-product-cards
        {--preset=* : preset keys to treat as shoppable (defaults to the buying-guide, comparison and new-arrivals presets)}
        {--article=* : restrict to specific article ids}
        {--min-task=0 : only consider articles whose task id is at least this}
        {--cards=3 : maximum cards per article}
        {--min-relevance=0.8 : auto-approve candidates scoring at or above this}
        {--limit=25 : maximum articles to process in one run}
        {--apply : write the cards; without it the run is a dry run}';

    protected $description = 'Search lightinthebox.com and embed product cards into shoppable articles';

    private const DEFAULT_PRESETS = [
        'litb.geo_product_buying_guide.en',
        'litb.product_comparison.en',
        'litb.new_arrivals_monthly.en',
        'litb.new_arrivals_weekly.en',
    ];

    public function handle(LitbProductEvidenceCollector $collector, LitbProductCardRenderer $renderer): int
    {
        $presets = $this->option('preset') ?: self::DEFAULT_PRESETS;
        $cards = max(1, (int) $this->option('cards'));
        $minRelevance = (float) $this->option('min-relevance');
        $apply = (bool) $this->option('apply');

        $articles = $this->shoppableArticles($presets);
        if ($articles->isEmpty()) {
            $this->info('No shoppable articles without cards found.');

            return self::SUCCESS;
        }

        $this->line(sprintf('%s %d article(s) · presets: %s · min relevance %.2f',
            $apply ? 'Processing' : 'Dry run over', $articles->count(), implode(', ', $presets), $minRelevance));

        $attached = 0;
        foreach ($articles as $article) {
            try {
                $attached += $this->processArticle($article, $collector, $renderer, $cards, $minRelevance, $apply) ? 1 : 0;
            } catch (Throwable $exception) {
                $this->error(sprintf('article %d: %s', $article->id, $exception->getMessage()));
            }
        }

        $this->info(sprintf('%s %d article(s).', $apply ? 'Attached cards to' : 'Would attach cards to', $attached));
        if (! $apply) {
            $this->line('Pass --apply to write.');
        }

        return self::SUCCESS;
    }

    /** @param list<string> $presets */
    private function shoppableArticles(array $presets): Collection
    {
        // The search query comes from the brief, not the article: the quality-gated path
        // leaves articles.original_keyword empty, and the published title is model-written
        // ("Thoughtful Mother's Day Gifts That Go Beyond the Usual"), which derives a query
        // full of tokens no product listing contains. content_generation_runs.article_id
        // links each article back to the exact brief that produced it.
        $query = Article::query()
            ->join('tasks', 'tasks.id', '=', 'articles.task_id')
            ->join('prompt_presets', 'prompt_presets.id', '=', 'tasks.prompt_preset_id')
            ->leftJoin('content_generation_runs', 'content_generation_runs.article_id', '=', 'articles.id')
            ->leftJoin('content_briefs', 'content_briefs.id', '=', 'content_generation_runs.content_brief_id')
            ->whereIn('prompt_presets.preset_key', $presets)
            ->whereNull('articles.deleted_at')
            // Idempotency: never double-attach.
            ->where('articles.content', 'not like', '%litb-product-card%')
            ->where('tasks.id', '>=', (int) $this->option('min-task'))
            ->select([
                'articles.id', 'articles.title', 'articles.content',
                'articles.original_keyword', 'articles.keywords',
                'prompt_presets.preset_key',
                'content_briefs.topic as brief_topic',
                'content_briefs.asset_provenance as brief_provenance',
            ]);

        $ids = $this->option('article');
        if ($ids !== []) {
            $query->whereIn('articles.id', array_map('intval', $ids));
        }

        return $query->orderBy('articles.id')->limit(max(1, (int) $this->option('limit')))->get();
    }

    private function processArticle(
        object $article,
        LitbProductEvidenceCollector $collector,
        LitbProductCardRenderer $renderer,
        int $cards,
        float $minRelevance,
        bool $apply,
    ): bool {
        // Prefer the brief's retrieval keyword, then the brief topic, and only fall back to
        // the model-written article title.
        $provenance = json_decode((string) ($article->brief_provenance ?? ''), true);
        $keyword = trim((string) (
            (is_array($provenance) ? ($provenance['retrieval_keyword'] ?? '') : '')
            ?: $article->original_keyword
            ?: $article->keywords
            ?: ''
        ));
        $subject = trim((string) ($article->brief_topic ?: $article->title));
        $evidence = $collector->collect($subject, $keyword, $cards);

        $approved = [];
        foreach ($evidence['candidates'] as $candidate) {
            if (count($approved) >= $cards) {
                break;
            }
            if ((float) $candidate['relevance_score'] < $minRelevance) {
                continue;
            }
            $candidate['status'] = 'approved';
            // The spec requires a WordPress-hosted copy for test-site acceptance. Until the
            // media pipeline runs, image_url intentionally mirrors source_image_url so the
            // provenance of what is rendered stays explicit.
            $candidate['image_url'] = $candidate['source_image_url'];
            $candidate['image_alt'] = $candidate['title'];
            $candidate['captured_at'] = $evidence['captured_at'];
            $candidate['last_verified_at'] = $evidence['captured_at'];
            $approved[] = $candidate;
        }

        $this->line(sprintf('  article %-5d [%s] query "%s" · %d candidate(s) · %d approved',
            $article->id, $article->preset_key, $evidence['query'], $evidence['candidate_count'], count($approved)));

        if ($approved === []) {
            return false;
        }

        $payload = [
            'schema_version' => LitbProductCardRenderer::SCHEMA_VERSION,
            'article_id' => (int) $article->id,
            'products' => $approved,
        ];
        $html = $renderer->render($payload);
        if ($html === '') {
            return false;
        }

        if (! $apply) {
            return true;
        }

        Storage::disk('local')->put(
            'private/litb-product-cards/'.$article->id.'.json',
            json_encode(['evidence' => $evidence, 'payload' => $payload], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        DB::table('articles')->where('id', $article->id)->update([
            'content' => rtrim((string) $article->content)."\n\n".$html,
            'updated_at' => now(),
        ]);

        return true;
    }
}
