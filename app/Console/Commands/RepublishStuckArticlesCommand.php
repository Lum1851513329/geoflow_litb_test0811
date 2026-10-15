<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\DistributionChannel;
use App\Services\GeoFlow\DistributionOrchestrator;
use Illuminate\Console\Command;

/**
 * 把只同步到已废弃测试站（channel 2）、从未到达 litbstyle.com（channel 3）
 * 的文章重新入队。用 DistributionOrchestrator 现成的
 * enqueueForArticleToChannel()——它自己处理已存在的 distribution 行、
 * 幂等键和事务，不需要在这里重新实现。
 */
final class RepublishStuckArticlesCommand extends Command
{
    protected $signature = 'geoflow:content-strategy:republish-stuck-articles {ids*}';

    protected $description = 'Re-queue specific published articles to litbstyle.com (channel 3)';

    public function handle(DistributionOrchestrator $orchestrator): int
    {
        $channel = DistributionChannel::find(3);
        if (! $channel) {
            $this->error('Channel 3 not found.');

            return self::FAILURE;
        }

        foreach ($this->argument('ids') as $id) {
            $article = Article::find((int) $id);
            if (! $article) {
                $this->warn("  article {$id}: not found");

                continue;
            }

            $orchestrator->enqueueForArticleToChannel($article, $channel);
            $this->line("  article {$article->id} ({$article->title}): queued to channel 3");
        }

        return self::SUCCESS;
    }
}
