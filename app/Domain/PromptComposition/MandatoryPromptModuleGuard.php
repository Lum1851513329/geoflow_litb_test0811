<?php

namespace App\Domain\PromptComposition;

use App\Models\PromptModule;
use App\Models\PromptModuleVersion;
use App\Models\PromptPresetVersion;
use InvalidArgumentException;

final class MandatoryPromptModuleGuard
{
    /** @var array<string, string> */
    private const CANONICAL_ALIASES = [
        'foundation_truth' => 'litb.foundation.truth_evidence',
        'geo_citability' => 'litb.geo.citable_answers',
        'seo_structure' => 'litb.seo.structure',
        'language_market' => 'litb.language.market_en_global',
        'output_format' => 'litb.output.markdown_article',
        'quality_checks' => 'litb.quality.preflight',
    ];

    /** @param array<string, mixed>|null $context */
    public function assertComplete(PromptPresetVersion $presetVersion, ?array $context = null): void
    {
        $bound = $presetVersion->moduleVersions()
            ->with('promptModuleVersion.module')
            ->get()
            ->groupBy(fn ($binding): string => self::CANONICAL_ALIASES[$binding->promptModuleVersion?->module?->module_key] ?? (string) $binding->promptModuleVersion?->module?->module_key)
            ->map(fn ($bindings) => $bindings->pluck('promptModuleVersion'));

        $missing = PromptModule::query()
            ->where('is_system', true)
            ->where('is_mandatory', true)
            ->where('is_enabled', true)
            ->whereNotNull('current_version_id')
            ->with('currentVersion:id,prompt_module_id,lifecycle_status')
            ->orderBy('id')
            ->get()
            ->filter(fn (PromptModule $module): bool => $this->applies($module, $presetVersion, $context))
            ->filter(function (PromptModule $module) use ($bound): bool {
                $canonicalKey = self::CANONICAL_ALIASES[$module->module_key] ?? $module->module_key;
                $version = $bound->get($canonicalKey, collect())->first(
                    fn (?PromptModuleVersion $candidate): bool => $candidate instanceof PromptModuleVersion
                        && $candidate->lifecycle_status === PromptModuleVersion::STATUS_PRODUCTION
                        && (int) $candidate->id === (int) ($candidate->module?->current_version_id ?? 0),
                );

                return ! $version instanceof PromptModuleVersion;
            })
            ->pluck('module_key')
            ->all();

        if ($missing !== []) {
            throw new InvalidArgumentException(__('admin.prompt_presets.validation.mandatory_missing', [
                'modules' => implode(', ', $missing),
            ]));
        }
    }

    /** @param array<string, mixed>|null $context */
    private function applies(PromptModule $module, PromptPresetVersion $presetVersion, ?array $context): bool
    {
        $applicability = is_array($module->applicability) ? $module->applicability : [];
        if ($context !== null) {
            foreach ($applicability as $field => $allowed) {
                if (! array_key_exists($field, $context) || ! $this->allows($allowed, $context[$field])) {
                    return false;
                }
            }

            return true;
        }

        $matchingRules = is_array($presetVersion->matching_rules) ? $presetVersion->matching_rules : [];
        foreach ($applicability as $field => $allowed) {
            if (! array_key_exists($field, $matchingRules)) {
                continue;
            }
            $moduleValues = $this->values($allowed);
            $presetValues = $this->values($matchingRules[$field]);
            if (! in_array('*', $moduleValues, true)
                && ! in_array('*', $presetValues, true)
                && array_intersect($moduleValues, $presetValues) === []) {
                return false;
            }
        }

        return true;
    }

    private function allows(mixed $allowed, mixed $value): bool
    {
        $values = $this->values($allowed);

        return in_array('*', $values, true) || in_array($value, $values, true);
    }

    /** @return list<mixed> */
    private function values(mixed $value): array
    {
        return array_values(is_array($value) ? $value : [$value]);
    }
}
