<?php

/**
 * Release generation runs parked by the old strict quality gate.
 *
 * WorkerExecutionService blocks a task once its runs parked in
 * needs_evidence / model_quality_failed / manual_review_required reach draft_limit.
 * The bulk import sets draft_limit = 1 on most tasks, so a single parked run freezes
 * the task permanently - every later scheduler tick completes as a no-op with
 * "质量门禁失败过多，等待人工处理".
 *
 * Those runs were judged by the pre-relaxation gate. Marking them failed releases the
 * breaker so the task can generate again under the current rules. raw_output is left
 * intact, so the original text stays readable at /geo_admin/content-generations/{id}.
 *
 * Run with no argument for a dry run; pass "apply" to write.
 *
 *   docker exec geoflow-app php artisan tinker /var/www/html/scripts/release_parked_runs.php
 *   docker exec geoflow-app php artisan tinker /var/www/html/scripts/release_parked_runs.php apply
 */

use App\Models\ContentGenerationRun;
use Illuminate\Support\Facades\DB;

$parked = [
    ContentGenerationRun::STATUS_NEEDS_EVIDENCE,
    ContentGenerationRun::STATUS_MODEL_QUALITY_FAILED,
    ContentGenerationRun::STATUS_MANUAL_REVIEW_REQUIRED,
];

$apply = in_array('apply', $_SERVER['argv'] ?? [], true);

// Scope to a task-id floor. The system carries parked runs from earlier manual work
// that have nothing to do with this batch; releasing those would rewrite history the
// operator may still be relying on. Pass min-task=<id> to change the floor.
$minTaskId = 34;
foreach ($_SERVER['argv'] ?? [] as $argument) {
    if (str_starts_with((string) $argument, 'min-task=')) {
        $minTaskId = (int) substr((string) $argument, 9);
    }
}

$rows = DB::table('content_generation_runs as r')
    ->join('content_briefs as b', 'b.id', '=', 'r.content_brief_id')
    ->whereIn('r.status', $parked)
    ->where('b.task_id', '>=', $minTaskId)
    ->select('r.id', 'b.task_id', 'r.status')
    ->orderBy('r.id')
    ->get();

echo "scope: tasks with id >= {$minTaskId}\n";

foreach ($rows as $row) {
    echo "run {$row->id} (task {$row->task_id}) {$row->status}\n";
}
echo 'parked runs found: '.$rows->count()."\n";

if (! $apply) {
    echo "dry run - pass \"apply\" to release them\n";

    return;
}

$released = DB::table('content_generation_runs')
    ->whereIn('id', $rows->pluck('id')->all())
    ->update([
        'status' => ContentGenerationRun::STATUS_FAILED,
        'error_message' => 'Released: judged by the pre-relaxation quality gate.',
        'updated_at' => now(),
    ]);

echo "released: {$released}\n";
