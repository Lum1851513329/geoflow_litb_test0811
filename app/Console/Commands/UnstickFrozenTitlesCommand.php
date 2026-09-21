<?php

namespace App\Console\Commands;

use App\Models\ContentGenerationRun;
use App\Models\Title;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 解冻被"幂等键已用于不同设置"卡死的标题。
 *
 * 复现路径：某次预约成功提交（used_count 自增、run 落库），随后该 run 因
 * AI 侧原因（例如额度耗尽）失败。此后每次调度重试都会先建一条新 Brief，
 * 再用同一个 v2:task:{task}:title:{title}:use:{used_count} 去 reserve()——
 * 由于 used_count 没有变化，key 命中的还是那条失败的旧 run，而这次的
 * Brief id 必然不同，assertReplayMatches() 判定"配置不同"直接拒绝，
 * 整个事务（含新 Brief）回滚，used_count 也就永远不会推进——无限循环。
 *
 * 本命令只做 reserve() 自身在成功路径上会做的事：把 used_count 往前推一格，
 * 让下一次调度算出一个全新的、从未被占用过的 key。不触碰
 * content_generation_runs（其不可变触发器也不允许），不删除、不改写任何
 * 历史记录，纯粹是把"卡住的指针"移到下一格。
 */
final class UnstickFrozenTitlesCommand extends Command
{
    protected $signature = 'geoflow:content-strategy:unstick-frozen-titles
        {--dry-run : 只列出会被解冻的标题，不写入}
        {--hours=6 : 检测窗口（小时），在此窗口内出现幂等冲突才算命中}';

    protected $description = 'Advance used_count for titles frozen by the idempotency-configuration-conflict loop';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $dryRun = (bool) $this->option('dry-run');

        $stuckTaskIds = DB::table('task_runs')
            ->where('status', 'failed')
            ->where('error_message', 'like', '%该生成请求键已用于不同的生成设置%')
            ->where('created_at', '>', now()->subHours($hours))
            ->distinct()
            ->pluck('task_id');

        if ($stuckTaskIds->isEmpty()) {
            $this->info('No tasks currently hitting the idempotency-configuration-conflict loop.');

            return self::SUCCESS;
        }

        $rows = DB::table('content_generation_runs as r')
            ->join('content_briefs as b', 'b.id', '=', 'r.content_brief_id')
            ->whereIn('b.task_id', $stuckTaskIds)
            ->select('b.task_id', 'r.id', 'r.status', 'b.topic', 'r.created_at')
            ->orderBy('b.task_id')
            ->orderByDesc('r.id')
            ->get()
            ->unique('task_id'); // keep the highest r.id per task_id (already ordered desc)

        $fixed = 0;
        $ambiguous = 0;
        $notFailed = 0;

        foreach ($rows as $row) {
            if ($row->status !== 'failed') {
                // 最后一条 run 不是 failed（例如 adopted/needs_evidence）：
                // 说明卡住的不是这条 run 对应的标题，靠标题文本反查风险太大，跳过。
                $notFailed++;

                continue;
            }

            $task = DB::table('tasks')->where('id', $row->task_id)->first();
            if (! $task) {
                continue;
            }

            $matches = Title::query()
                ->where('library_id', $task->title_library_id)
                ->where('title', $row->topic)
                ->get(['id', 'used_count']);

            if ($matches->count() !== 1) {
                // 标题文本在同一个库里不唯一，反查不可靠，跳过而不是猜。
                $ambiguous++;
                $this->warn(sprintf('  task %d: %d title matches for "%s" — skipped.', $row->task_id, $matches->count(), $row->topic));

                continue;
            }

            $title = $matches->first();
            $this->line(sprintf('  task %-4d title %-5d used_count %d -> %d  (%s)', $row->task_id, $title->id, $title->used_count, $title->used_count + 1, $row->topic));

            if (! $dryRun) {
                Title::whereKey($title->id)->increment('used_count');
                Title::whereKey($title->id)->increment('usage_count');
            }
            $fixed++;
        }

        $this->info(sprintf('Unstuck %d title(s). Skipped %d (last run not failed), %d (ambiguous title text).', $fixed, $notFailed, $ambiguous));

        return self::SUCCESS;
    }
}
