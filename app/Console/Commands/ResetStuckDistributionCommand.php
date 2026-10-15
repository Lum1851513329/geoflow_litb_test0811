<?php

namespace App\Console\Commands;

use App\Models\ArticleDistribution;
use Illuminate\Console\Command;

/**
 * 把卡在 'sending' 的分发记录重置为 'queued'，交给下一次调度重试。
 * 只用于确认没有真正在跑的 worker 还持有它的场合（例如上一个 job
 * 异常退出、状态没能落到 failed/synced）。
 */
final class ResetStuckDistributionCommand extends Command
{
    protected $signature = 'geoflow:distribution:reset-stuck {article_id} {channel_id}';

    protected $description = 'Reset a distribution stuck in sending back to queued';

    public function handle(): int
    {
        $distribution = ArticleDistribution::query()
            ->where('article_id', (int) $this->argument('article_id'))
            ->where('distribution_channel_id', (int) $this->argument('channel_id'))
            ->first();

        if (! $distribution) {
            $this->error('No matching distribution row.');

            return self::FAILURE;
        }

        $this->line("current status: {$distribution->status}, last_attempt_at: {$distribution->last_attempt_at}");
        $distribution->forceFill(['status' => 'queued', 'next_retry_at' => now()])->save();
        $this->info('Reset to queued.');

        return self::SUCCESS;
    }
}
