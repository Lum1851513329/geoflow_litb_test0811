<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\ArticleWorkflow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * 把历史文章的随机 slug（generateUniqueSlug() 曾经忽略标题参数留下的产物）
 * 换成基于标题的 slug。
 *
 * 只处理已经真正同步到 litbstyle.com（channel 3）且 slug 仍是旧随机格式
 * 的文章。改 WordPress 端的 slug 会触发它自带的 `_wp_old_slug` 机制——
 * 旧网址会自动 301 到新网址，不需要额外配置重定向。
 */
final class RestoreTitleSlugsCommand extends Command
{
    protected $signature = 'geoflow:content-strategy:restore-title-slugs
        {--dry-run}
        {--limit=200}';

    protected $description = 'Rename historical random-slug articles on litbstyle.com to title-based slugs';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, min(500, (int) $this->option('limit')));

        $channel = DistributionChannel::with('activeSecret')->find(3);
        if (! $channel || ! $channel->activeSecret) {
            $this->error('Channel 3 (litbstyle.com) or its secret is missing.');

            return self::FAILURE;
        }

        $crypto = app(ApiKeyCrypto::class);
        $password = $crypto->decrypt((string) $channel->activeSecret->getRawOriginal('secret_ciphertext'));
        $username = $channel->channel_config['wordpress_username'] ?? '';
        $base = rtrim($channel->endpoint_url, '/');

        $articles = Article::query()
            ->where('status', 'published')
            ->whereRaw("slug ~ '^[a-z0-9]{8}\$'")
            ->whereHas('distributions', function ($q) {
                $q->where('distribution_channel_id', 3)->where('status', 'synced');
            })
            ->with(['distributions' => function ($q) {
                $q->where('distribution_channel_id', 3);
            }])
            ->limit($limit)
            ->get();

        $renamed = 0;
        $skipped = 0;

        foreach ($articles as $article) {
            $distribution = $article->distributions->first();
            $wpPostId = (int) ($distribution->remote_id ?? 0);
            if ($wpPostId <= 0) {
                $this->warn("  article {$article->id}: no WordPress post id on record, skipped.");
                $skipped++;

                continue;
            }

            $newSlug = ArticleWorkflow::generateUniqueSlug($article->title, (int) $article->id);
            if ($newSlug === $article->slug || preg_match('/^[a-z0-9]{8}$/', $newSlug) === 1) {
                // Title produced nothing usable (e.g. non-Latin title) —
                // leave the opaque slug alone rather than swap one for another.
                $this->line("  article {$article->id} ({$article->slug}): no usable title-based slug, left as-is.");
                $skipped++;

                continue;
            }

            $this->line("  article {$article->id}: {$article->slug} -> {$newSlug}  ({$article->title})");

            if ($dryRun) {
                $renamed++;

                continue;
            }

            $response = Http::withBasicAuth($username, $password)->timeout(30)
                ->post("{$base}/wp-json/wp/v2/posts/{$wpPostId}", ['slug' => $newSlug]);

            if (! $response->successful()) {
                $this->warn("    WordPress update failed (HTTP {$response->status()}), skipped.");
                $skipped++;

                continue;
            }

            $newLink = (string) ($response->json('link') ?? '');

            $article->forceFill(['slug' => $newSlug])->save();
            if ($newLink !== '') {
                $distribution->forceFill(['remote_url' => $newLink])->save();
            }

            $renamed++;
        }

        $this->info(($dryRun ? '[dry-run] would rename ' : 'Renamed ').$renamed.", skipped {$skipped}.");

        return self::SUCCESS;
    }
}
