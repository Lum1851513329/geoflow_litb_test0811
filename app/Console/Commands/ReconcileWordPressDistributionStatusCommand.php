<?php

namespace App\Console\Commands;

use App\Models\ArticleDistribution;
use App\Services\GeoFlow\WordPressRestRequestFactory;
use Illuminate\Console\Command;

/**
 * 核对本地 status='synced' 的 WordPress 分发记录与远端实际文章状态是否一致。
 *
 * GeoFlow 只在推送成功时把 status 写成 synced，此后不会再回查——如果有人直接在
 * WordPress 后台把文章移入回收站或删除，本地记录会一直显示 synced 而不自知。
 * 本命令逐条用已保存的渠道凭证向 WordPress REST API 发起 GET 请求，按远端真实
 * 状态把本地 status 改写为 trashed / deleted，publish 状态的记录保持不变。
 */
final class ReconcileWordPressDistributionStatusCommand extends Command
{
    protected $signature = 'geoflow:distribution:reconcile-status {--dry-run : 只报告不一致的记录，不写库}';

    protected $description = 'Reconcile locally-synced WordPress distributions against their live remote status';

    public function handle(WordPressRestRequestFactory $requestFactory): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $distributions = ArticleDistribution::query()
            ->where('status', 'synced')
            ->whereNotNull('remote_id')
            ->with('channel')
            ->get()
            ->filter(fn (ArticleDistribution $d) => $d->channel?->isWordPressRest() === true);

        $this->info("Checking {$distributions->count()} synced WordPress distribution(s)...");

        $mismatched = [];
        $checked = 0;
        $errored = 0;

        foreach ($distributions as $distribution) {
            $postId = $distribution->wordpressPostId();
            if (! $postId) {
                continue;
            }

            $channel = $distribution->channel;

            try {
                $response = $requestFactory->request($channel, 15)
                    ->get($channel->wordpressRestBaseUrl().'/wp/v2/posts/'.$postId, ['context' => 'edit']);
            } catch (\Throwable $e) {
                $errored++;
                $this->warn("  ! article {$distribution->article_id} (post {$postId}): request failed — {$e->getMessage()}");

                continue;
            }

            $checked++;

            $remoteStatus = null;
            if ($response->status() === 404) {
                $remoteStatus = 'deleted';
            } elseif ($response->successful()) {
                $json = $response->json();
                $remoteStatus = is_array($json) ? (string) ($json['status'] ?? '') : '';
            } else {
                $errored++;
                $this->warn("  ! article {$distribution->article_id} (post {$postId}): HTTP {$response->status()}");

                continue;
            }

            if ($remoteStatus === 'publish' || $remoteStatus === '') {
                continue;
            }

            $newStatus = $remoteStatus === 'trash' ? 'trashed' : $remoteStatus;
            $mismatched[] = [
                'article_id' => $distribution->article_id,
                'distribution_id' => $distribution->id,
                'remote_id' => $postId,
                'remote_url' => $distribution->remote_url,
                'remote_status' => $remoteStatus,
                'new_local_status' => $newStatus,
            ];

            $this->line("  MISMATCH article {$distribution->article_id} (post {$postId}): local=synced, remote={$remoteStatus} -> {$newStatus}");

            if (! $dryRun) {
                $distribution->forceFill(['status' => $newStatus])->save();
            }
        }

        $this->newLine();
        $this->info("Checked: {$checked}, mismatched: ".count($mismatched).", errors: {$errored}");
        if ($dryRun && $mismatched !== []) {
            $this->comment('Dry run: no rows were updated.');
        }

        return self::SUCCESS;
    }
}
