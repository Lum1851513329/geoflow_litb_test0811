<?php

/**
 * Restore section questions that were wrongly emptied, by republishing from the last
 * version that still had them.
 *
 * Emptying questions_to_answer to silence missing_question_coverage was a mistake:
 * blueprint validation requires every substantive section to carry at least one question
 * (questions_required / "实质性章节至少需要一个问题"), so the emptied blueprints failed
 * validation outright with invalid_blueprint - a harder failure than the one being fixed.
 *
 * Coverage is instead relaxed in DeterministicQualityGate, where missing_question_coverage
 * is now a warning. The blueprint keeps its questions and stays valid.
 *
 * prompt_preset_versions is immutable, so this publishes a NEW version carrying the
 * questions copied from the newest earlier version that had them.
 *
 * Run with no argument for a dry run; pass "apply" to publish.
 */

use App\Domain\PromptComposition\PromptPresetService;
use App\Models\Admin;
use App\Models\PromptPreset;
use App\Models\PromptPresetModuleVersion;
use App\Models\PromptPresetVersion;

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

foreach ($targets as $preset) {
    $current = $preset->currentVersion;
    $blueprint = is_array($current->blueprint_template)
        ? $current->blueprint_template
        : json_decode((string) $current->blueprint_template, true);
    if (! is_array($blueprint) || ! is_array($blueprint['sections'] ?? null)) {
        continue;
    }

    $emptySections = array_filter(
        $blueprint['sections'],
        static fn (array $section): bool => ($section['questions_to_answer'] ?? []) === []
    );
    if ($emptySections === []) {
        echo "skip    {$preset->preset_key} (no empty sections)\n";

        continue;
    }

    // Newest earlier version whose blueprint still carried questions.
    $donor = PromptPresetVersion::query()
        ->where('prompt_preset_id', $preset->id)
        ->where('id', '!=', $current->id)
        ->whereNotNull('blueprint_template')
        ->orderByDesc('version')
        ->get()
        ->first(function (PromptPresetVersion $version): bool {
            $bp = is_array($version->blueprint_template)
                ? $version->blueprint_template
                : json_decode((string) $version->blueprint_template, true);
            if (! is_array($bp) || ! is_array($bp['sections'] ?? null)) {
                return false;
            }
            foreach ($bp['sections'] as $section) {
                if (($section['questions_to_answer'] ?? []) !== []) {
                    return true;
                }
            }

            return false;
        });

    if ($donor === null) {
        echo "SKIP    {$preset->preset_key} - no earlier version with questions\n";

        continue;
    }

    $donorBlueprint = is_array($donor->blueprint_template)
        ? $donor->blueprint_template
        : json_decode((string) $donor->blueprint_template, true);
    $donorBySection = [];
    foreach ($donorBlueprint['sections'] as $section) {
        $donorBySection[$section['key'] ?? ''] = $section['questions_to_answer'] ?? [];
    }

    $restored = 0;
    foreach ($blueprint['sections'] as $index => $section) {
        if (($section['questions_to_answer'] ?? []) !== []) {
            continue;
        }
        $questions = $donorBySection[$section['key'] ?? ''] ?? [];
        if ($questions !== []) {
            $blueprint['sections'][$index]['questions_to_answer'] = $questions;
            $restored++;
        }
    }

    if ($restored === 0) {
        echo "SKIP    {$preset->preset_key} - donor v{$donor->version} had nothing to restore\n";

        continue;
    }

    $changed++;
    echo ($apply ? 'publish ' : 'would   ')."{$preset->preset_key} (+{$restored} sections from v{$donor->version})\n";

    if (! $apply) {
        continue;
    }

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

    $version = $presets->createVersion($preset, [
        'matching_rules' => $current->matching_rules,
        'generation_policy' => $current->generation_policy,
        'conflict_policy' => $current->conflict_policy,
        'blueprint_template' => $blueprint,
        'default_brief_fields' => $current->default_brief_fields,
        'change_note' => 'Restore section questions; coverage relaxed in the gate instead.',
    ], $bindings, $actor);
    $presets->publish($version, $actor, 'Restore blueprint validity after an over-aggressive relaxation.');
    echo "        -> version {$version->version}\n";
}

echo ($apply ? 'published: ' : 'would publish: ')."{$changed} preset(s)\n";
if (! $apply) {
    echo "dry run - pass \"apply\" to publish\n";
}
