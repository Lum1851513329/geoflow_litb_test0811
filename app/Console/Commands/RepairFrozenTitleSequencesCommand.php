<?php

namespace App\Console\Commands;

use App\Models\ContentGenerationRun;
use App\Models\Task;
use App\Models\Title;
use Illuminate\Console\Command;

/**
 * 一次性数据修复：把"看起来是处女标题"但实际已经撞过 use:0 键的标题
 * 纠正到下一个从未使用过的序号。
 *
 * 修复前的 pickTitle() fallback 会无条件把重试标题的 used_count 强制
 * 回退到 0（详见 WorkerExecutionService::pickTitle() 里现在的注释），
 * 这个 bug 已经跑了数天：受影响的标题当前 used_count<=0，view 起来和
 * 从未使用过的标题没有区别，于是 pickTitle() 的主查询会直接把它们当
 * "处女标题"选中——根本不会走到已经修好的 fallback 分支。代码修好之后
 * 仍需要这一次性数据修复，才能清掉已经产生的坏状态。
 */
final class RepairFrozenTitleSequencesCommand extends Command
{
    protected $signature = 'geoflow:content-strategy:repair-title-sequences {--dry-run}';

    protected $description = 'Fix titles left at used_count<=0 that already collide with an existing use:0 reservation';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $fixed = 0;

        Task::query()
            ->where('is_loop', '!=', 1)
            ->whereNotNull('title_library_id')
            ->get(['id', 'title_library_id'])
            ->each(function (Task $task) use ($dryRun, &$fixed): void {
                $suspects = Title::query()
                    ->where('library_id', $task->title_library_id)
                    ->where(function ($q) {
                        $q->whereNull('used_count')->orWhere('used_count', '<=', 0);
                    })
                    ->get(['id', 'used_count']);

                foreach ($suspects as $title) {
                    $key = 'v2:task:'.(int) $task->id.':title:'.(int) $title->id.':use:0';
                    $collides = ContentGenerationRun::query()->where('idempotency_key', $key)->exists();
                    if (! $collides) {
                        continue;
                    }

                    $prefix = 'v2:task:'.(int) $task->id.':title:'.(int) $title->id.':use:';
                    $maxUsed = ContentGenerationRun::query()
                        ->where('idempotency_key', 'like', $prefix.'%')
                        ->get(['idempotency_key'])
                        ->map(static fn (ContentGenerationRun $r): int => (int) substr((string) $r->idempotency_key, strlen($prefix)))
                        ->max();
                    $nextUse = $maxUsed === null ? 1 : $maxUsed + 1;

                    $this->line(sprintf('  task %-4d title %-5d used_count 0 -> %d', $task->id, $title->id, $nextUse));
                    if (! $dryRun) {
                        Title::whereKey($title->id)->update(['used_count' => $nextUse, 'usage_count' => $nextUse]);
                    }
                    $fixed++;
                }
            });

        $this->info(($dryRun ? '[dry-run] would fix ' : 'Fixed ').$fixed.' title(s).');

        return self::SUCCESS;
    }
}
