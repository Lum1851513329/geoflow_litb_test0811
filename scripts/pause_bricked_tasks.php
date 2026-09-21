<?php

/**
 * Pause tasks that can never generate again after a preset republish.
 *
 * The generation idempotency key is v2:task:<task>:title:<title>:use:<used_count>.
 * used_count only advances when a run is ADOPTED, so a task whose runs all failed keeps
 * a frozen key. Publishing a new preset version changes preset_version_id, which is part
 * of the replay fingerprint, so every later attempt dies with
 * idempotency_configuration_conflict. content_generation_runs is immutable (both update
 * and delete are trigger-guarded), so the key cannot be retired either.
 *
 * Such a task is stuck: it will no-op or conflict on every scheduler tick forever.
 * Pausing stops the churn and preserves the audit trail. Re-import the same rows to get
 * fresh task ids, which produce fresh keys.
 *
 * Run with no argument for a dry run; pass "apply" to write.
 * Scope defaults to tasks with id >= 34; override with min-task=<id> / max-task=<id>.
 */

use Illuminate\Support\Facades\DB;

$apply = in_array('apply', $_SERVER['argv'] ?? [], true);

$minTaskId = 34;
$maxTaskId = PHP_INT_MAX;
foreach ($_SERVER['argv'] ?? [] as $argument) {
    if (str_starts_with((string) $argument, 'min-task=')) {
        $minTaskId = (int) substr((string) $argument, 9);
    }
    if (str_starts_with((string) $argument, 'max-task=')) {
        $maxTaskId = (int) substr((string) $argument, 9);
    }
}

$tasks = DB::table('tasks')
    ->where('id', '>=', $minTaskId)
    ->where('id', '<=', $maxTaskId)
    ->where('status', 'active')
    ->orderBy('id')
    ->get(['id', 'name', 'status']);

echo "scope: tasks {$minTaskId}..".($maxTaskId === PHP_INT_MAX ? 'end' : $maxTaskId)."\n";
foreach ($tasks as $task) {
    echo "task {$task->id}  {$task->name}\n";
}
echo 'active tasks to pause: '.$tasks->count()."\n";

if (! $apply) {
    echo "dry run - pass \"apply\" to pause them\n";

    return;
}

$paused = DB::table('tasks')
    ->whereIn('id', $tasks->pluck('id')->all())
    ->update([
        'status' => 'paused',
        'schedule_enabled' => 0,
        'last_error_message' => 'Paused: generation key frozen by a preset republish; re-import to get a fresh task id.',
        'updated_at' => now(),
    ]);

echo "paused: {$paused}\n";
