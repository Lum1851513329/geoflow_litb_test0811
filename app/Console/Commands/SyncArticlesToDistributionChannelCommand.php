<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\DistributionChannel;
use App\Services\GeoFlow\DistributionOrchestrator;
use Illuminate\Console\Command;

class SyncArticlesToDistributionChannelCommand extends Command
{
    protected $signature = 'geoflow:distribution:sync-channel {channel? : Specific channel ID; omit to sync all active WordPress REST channels}';

    protected $description = 'Enqueue published, task-less articles (e.g. LITB content) to active WordPress REST distribution channels.';

    public function handle(DistributionOrchestrator $orchestrator): int
    {
        $channels = $this->resolveChannels();

        if ($channels->isEmpty()) {
            $this->info('No active WordPress REST channels to sync.');

            return self::SUCCESS;
        }

        $articles = Article::query()->published()->whereNull('task_id')->get();
        $enqueued = 0;

        foreach ($channels as $channel) {
            foreach ($articles as $article) {
                $orchestrator->enqueueForArticleToChannel($article, $channel);
                $enqueued++;
            }
        }

        $this->info(sprintf(
            'Channels: %d, articles considered: %d, enqueue calls made: %d',
            $channels->count(),
            $articles->count(),
            $enqueued,
        ));

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, DistributionChannel>
     */
    private function resolveChannels()
    {
        $channelId = $this->argument('channel');

        if ($channelId !== null) {
            return DistributionChannel::query()
                ->whereKey((int) $channelId)
                ->where('status', DistributionChannel::STATUS_ACTIVE)
                ->get();
        }

        return DistributionChannel::query()
            ->where('status', DistributionChannel::STATUS_ACTIVE)
            ->where('channel_type', 'wordpress_rest')
            ->get();
    }
}
