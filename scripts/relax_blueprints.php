<?php

/**
 * Publish a new preset version whose blueprint drops the seeder's placeholder questions.
 *
 * geoflow:content-strategy:seed-preset-blueprint-templates fills each non-lead section
 * with topic-agnostic questions ("What options and criteria matter most for this
 * decision?"). DeterministicQualityGate then requires the article to answer them
 * verbatim, so every generated article failed missing_question_coverage and parked.
 *
 * Only the brief-driven {{core_question}} is kept - that one is the GEO mechanism worth
 * enforcing. Sections left with an empty questions_to_answer contribute no coverage
 * checks at all.
 *
 * prompt_preset_versions rows are immutable (enforced by the
 * guard_prompt_preset_version_immutable() trigger), so this publishes a NEW version per
 * preset via PromptPresetService, exactly as the seeder does. History is preserved.
 *
 * Run with no argument for a dry run; pass "apply" to publish.
 *
 *   docker exec geoflow-app php artisan tinker /var/www/html/scripts/relax_blueprints.php
 *   docker exec geoflow-app php artisan tinker /var/www/html/scripts/relax_blueprints.php apply
 */

use App\Domain\PromptComposition\PromptPresetService;
use App\Models\Admin;
use App\Models\PromptPreset;
use App\Models\PromptPresetModuleVersion;

$apply = in_array('apply', $_SERVER['argv'] ?? [], true);

$actor = Admin::query()->where('username', 'geoflow-system')->first();
if (! $actor) {
    echo "no geoflow-system admin found\n";

    return;
}

/** @var PromptPresetService $presets */
$presets = app(PromptPresetService::class);

$targets = PromptPreset::query()
    ->whereHas('currentVersion', fn ($q) => $q->whereNotNull('blueprint_template'))
    ->with('currentVersion')
    ->orderBy('preset_key')
    ->get();

$changed = 0;
$cleared = 0;

foreach ($targets as $preset) {
    $current = $preset->currentVersion;
    $blueprint = is_array($current->blueprint_template)
        ? $current->blueprint_template
        : json_decode((string) $current->blueprint_template, true);
    if (! is_array($blueprint) || ! is_array($blueprint['sections'] ?? null)) {
        continue;
    }

    $removedHere = 0;
    foreach ($blueprint['sections'] as $index => $section) {
        $questions = $section['questions_to_answer'] ?? [];
        if (! is_array($questions) || $questions === []) {
            continue;
        }
        $keep = array_values(array_filter(
            $questions,
            static fn ($question): bool => str_contains((string) $question, '{{core_question}}')
        ));
        if (count($keep) !== count($questions)) {
            $blueprint['sections'][$index]['questions_to_answer'] = $keep;
            $removedHere += count($questions) - count($keep);
        }
    }

    if ($removedHere === 0) {
        echo "skip    {$preset->preset_key} (no placeholder questions)\n";

        continue;
    }

    $cleared += $removedHere;
    $changed++;
    echo ($apply ? 'publish ' : 'would   ')."{$preset->preset_key} (-{$removedHere} questions)\n";

    if (! $apply) {
        continue;
    }

    // Carry the current version's module bindings forward unchanged.
    $bindings = PromptPresetModuleVersion::query()
        ->where('prompt_preset_version_id', $current->id)
        ->orderBy('sort_order')
        ->get()
        ->map(static fn (PromptPresetModuleVersion $binding): array => [
            'prompt_module_version_id' => (int) $binding->prompt_module_version_id,
            'sort_order' => (int) $binding->sort_order,
            'is_required' => (bool) $binding->is_required,
            'selection_source' => $binding->selection_source,
            'configuration' => $binding->configuration,
        ])->all();

    $data = [
        'matching_rules' => $current->matching_rules,
        'generation_policy' => $current->generation_policy,
        'conflict_policy' => $current->conflict_policy,
        'blueprint_template' => $blueprint,
        'default_brief_fields' => $current->default_brief_fields,
        'change_note' => 'Drop generic placeholder questions; keep {{core_question}} only.',
    ];

    $version = $presets->createVersion($preset, $data, $bindings, $actor);
    $presets->publish($version, $actor, 'Relax question coverage to the brief core question.');
    echo "        -> version {$version->version}\n";
}

echo ($apply ? 'published: ' : 'would publish: ')."{$changed} preset(s), {$cleared} placeholder questions removed\n";
if (! $apply) {
    echo "dry run - pass \"apply\" to publish\n";
}
