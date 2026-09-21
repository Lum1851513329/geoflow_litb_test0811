<?php

/**
 * Re-run the bulk content import programmatically.
 *
 * Used to recover from tasks whose generation idempotency key was frozen by a preset
 * republish (see pause_bricked_tasks.php). The key embeds the task id, so importing the
 * same rows under new task ids produces fresh keys and generation can proceed.
 *
 * This calls the same service the admin upload uses; nothing is bypassed.
 *
 *   docker exec geoflow-app php artisan tinker /var/www/html/scripts/reimport_sample.php
 */

use App\Domain\ContentGeneration\BulkContentImportService;
use App\Domain\ContentGeneration\BulkContentImportValidationException;
use App\Models\Admin;

$path = '/tmp/geoflow_tasks_sample10.xlsx';
$aiModelId = 1;      // DeepSeek Chat (the only active chat model)
$needReview = false; // draft -> review adds a manual gate we do not want for a smoke test

// Must be on_insufficient, not off.
//
// WorkerExecutionService::resolveKnowledgeSourceMode() classifies a topic as
// 'brand_required' only when the topic or preset key carries a brand signal
// (lightinthebox, shipping, payment, return, buying_guide, ...). Everything else is
// 'web_first', and for those the brief deliberately drops the brand knowledge tree
// ("general education topics should not be polluted by the brand-only knowledge tree")
// and expects external evidence to supply the facts instead.
//
// With the policy off, a web_first topic therefore has NO evidence source at all: the
// model invents specifics and the semantic reviewer parks the run as needs_evidence.
$externalEvidencePolicy = 'on_insufficient';

if (! is_readable($path)) {
    echo "cannot read {$path}\n";

    return;
}

$actor = Admin::query()->where('username', 'admin')->first();
if (! $actor) {
    echo "no admin actor found\n";

    return;
}

try {
    $result = app(BulkContentImportService::class)->import(
        $path,
        $aiModelId,
        $needReview,
        $actor,
        $externalEvidencePolicy,
        [],
    );
} catch (BulkContentImportValidationException $exception) {
    foreach ($exception->errors() as $error) {
        echo "row {$error['row']}: {$error['message']}\n";
    }

    return;
}

echo 'tasks created: '.($result['tasks_created'] ?? '?')."\n";
echo 'rows imported: '.($result['rows_imported'] ?? '?')."\n";
