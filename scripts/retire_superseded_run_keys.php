<?php

/**
 * Retire the idempotency keys of runs superseded by a preset republish.
 *
 * ContentGenerationRequestService::reserve() looks up an existing run by
 * idempotency_key and then calls assertReplayMatches(), which compares a fingerprint
 * that includes preset_version_id. Publishing a new preset version therefore makes
 * every prior run for the same task+title collide:
 *   "该生成请求键已用于不同的生成设置" (idempotency_configuration_conflict)
 *
 * The task's idempotency key is task+title+used_count, which is stable across retries
 * by design, so the conflict repeats forever and the task can never generate again.
 *
 * idempotency_key is NOT NULL, so the key is suffixed rather than cleared. The run row,
 * including raw_output, is left intact and still readable at
 * /geo_admin/content-generations/{id}.
 *
 * Run with no argument for a dry run; pass "apply" to write.
 * Scope defaults to tasks with id >= 34; override with min-task=<id>.
 */

use App\Models\ContentGenerationRun;
use Illuminate\Support\Facades\DB;

$apply = in_array('apply', $_SERVER['argv'] ?? [], true);

$minTaskId = 34;
foreach ($_SERVER['argv'] ?? [] as $argument) {
    if (str_starts_with((string) $argument, 'min-task=')) {
        $minTaskId = (int) substr((string) $argument, 9);
    }
}

// Only terminal runs. An in-flight run still needs its key.
$terminal = [
    ContentGenerationRun::STATUS_FAILED,
    ContentGenerationRun::STATUS_NEEDS_EVIDENCE,
    ContentGenerationRun::STATUS_MODEL_QUALITY_FAILED,
    ContentGenerationRun::STATUS_MANUAL_REVIEW_REQUIRED,
];

$rows = DB::table('content_generation_runs as r')
    ->join('content_briefs as b', 'b.id', '=', 'r.content_brief_id')
    ->whereIn('r.status', $terminal)
    ->where('b.task_id', '>=', $minTaskId)
    ->where('r.idempotency_key', 'not like', '%::superseded%')
    ->select('r.id', 'b.task_id', 'r.status', 'r.idempotency_key')
    ->orderBy('r.id')
    ->get();

echo "scope: tasks with id >= {$minTaskId}\n";
foreach ($rows as $row) {
    echo "run {$row->id} (task {$row->task_id}) {$row->status}\n";
}
echo 'runs to retire: '.$rows->count()."\n";

if (! $apply) {
    echo "dry run - pass \"apply\" to retire these keys\n";

    return;
}

$retired = 0;
foreach ($rows as $row) {
    DB::table('content_generation_runs')->where('id', $row->id)->update([
        'idempotency_key' => $row->idempotency_key.'::superseded-'.$row->id,
        'updated_at' => now(),
    ]);
    $retired++;
}

echo "retired: {$retired}\n";
