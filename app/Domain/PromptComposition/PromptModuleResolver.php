<?php

namespace App\Domain\PromptComposition;

use App\Models\PromptModule;
use App\Models\PromptModuleVersion;
use App\Models\PromptPresetModuleVersion;
use App\Models\PromptPresetVersion;
use InvalidArgumentException;

final class PromptModuleResolver
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

    /**
     * @param array<string, mixed> $context
     * @param array<string, int|null> $overrides Module key => exact module version ID, or null to omit an optional module.
     * @return list<ResolvedPromptModule>
     */
    public function resolve(PromptPresetVersion $preset, array $context, array $overrides = []): array
    {
        $resolved = $preset->moduleVersions()
            ->with('promptModuleVersion.module')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function (PromptPresetModuleVersion $binding) use ($context, $overrides): ?array {
                $boundVersion = $binding->promptModuleVersion;
                $module = $boundVersion->module;
                $required = $binding->is_required || $module->is_mandatory;

                if (! $this->matchesConditions($binding->configuration, $context)) {
                    return null;
                }

                if (! $module->is_enabled || ! $this->matchesContext($module, $context)) {
                    return $this->handleUnavailableBinding($required, $module->module_key);
                }

                if ($boundVersion->lifecycle_status === PromptModuleVersion::STATUS_ARCHIVED) {
                    return $this->handleUnavailableBinding($required, $module->module_key);
                }

                $version = $this->resolveVersion($boundVersion, $module, $required, $overrides);
                if ($version === null) {
                    return null;
                }

                return [
                    'binding' => $binding,
                    'module' => new ResolvedPromptModule(
                        moduleId: (int) $module->id,
                        versionId: (int) $version->id,
                        moduleKey: $module->module_key,
                        moduleType: $module->module_type,
                        priority: (int) $module->default_priority,
                        required: $required,
                        content: $version->content,
                        variableContract: $version->variable_contract,
                    ),
                ];
            })
            ->filter()
            ->sort(function (array $left, array $right): int {
                $priority = $right['module']->priority <=> $left['module']->priority;
                if ($priority !== 0) {
                    return $priority;
                }

                $position = $left['binding']->sort_order <=> $right['binding']->sort_order;
                if ($position !== 0) {
                    return $position;
                }

                return $left['binding']->id <=> $right['binding']->id;
            })
            ->values()
            ->all();

        $deduplicated = [];
        foreach ($resolved as $entry) {
            $module = $entry['module'];
            $canonicalKey = self::CANONICAL_ALIASES[$module->moduleKey] ?? $module->moduleKey;
            if (! isset($deduplicated[$canonicalKey]) || $this->shouldReplaceAlias($deduplicated[$canonicalKey], $entry, $canonicalKey)) {
                $deduplicated[$canonicalKey] = $entry;
            }
        }

        return array_values(array_map(
            static fn (array $entry): ResolvedPromptModule => $entry['module'],
            $deduplicated,
        ));
    }

    /** @param array<string, mixed> $context */
    private function matchesContext(PromptModule $module, array $context): bool
    {
        foreach ($module->applicability ?? [] as $field => $allowedValues) {
            if (! array_key_exists($field, $context)) {
                return false;
            }

            $allowedValues = is_array($allowedValues) ? $allowedValues : [$allowedValues];
            if (! in_array('*', $allowedValues, true) && ! in_array($context[$field], $allowedValues, true)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed>|null $configuration @param array<string, mixed> $context */
    private function matchesConditions(?array $configuration, array $context): bool
    {
        $conditions = is_array($configuration) ? ($configuration['conditions'] ?? []) : [];
        if (! is_array($conditions) || $conditions === []) {
            return true;
        }

        foreach ($conditions as $condition) {
            if (! is_array($condition)) {
                continue;
            }
            $field = (string) ($condition['field'] ?? '');
            if ($field === '') {
                continue;
            }
            $exists = array_key_exists($field, $context);
            $actual = $context[$field] ?? null;
            $operator = strtolower((string) ($condition['operator'] ?? 'truthy'));
            $expected = $condition['value'] ?? null;

            $matches = match ($operator) {
                'exists' => $exists,
                'not_exists' => ! $exists,
                'empty' => ! $this->hasValue($actual),
                'not_empty' => $this->hasValue($actual),
                'equals', 'eq' => $exists && $actual === $expected,
                'not_equals', 'neq' => ! $exists || $actual !== $expected,
                'in' => $exists && in_array($actual, is_array($expected) ? $expected : [$expected], true),
                'not_in' => ! $exists || ! in_array($actual, is_array($expected) ? $expected : [$expected], true),
                'contains' => is_array($actual)
                    ? in_array($expected, $actual, true)
                    : (is_string($actual) && str_contains($actual, (string) $expected)),
                default => $this->hasValue($actual),
            };

            if (! $matches) {
                return false;
            }
        }

        return true;
    }

    private function hasValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    /** @param array{binding: PromptPresetModuleVersion, module: ResolvedPromptModule} $current @param array{binding: PromptPresetModuleVersion, module: ResolvedPromptModule} $candidate */
    private function shouldReplaceAlias(array $current, array $candidate, string $canonicalKey): bool
    {
        $currentModule = $current['module'];
        $candidateModule = $candidate['module'];
        if ($candidateModule->required !== $currentModule->required) {
            return $candidateModule->required;
        }
        if ($candidateModule->priority !== $currentModule->priority) {
            return $candidateModule->priority > $currentModule->priority;
        }

        $candidateIsCanonical = $candidateModule->moduleKey === $canonicalKey;
        $currentIsCanonical = $currentModule->moduleKey === $canonicalKey;
        if ($candidateIsCanonical !== $currentIsCanonical) {
            return $candidateIsCanonical;
        }

        return $candidate['binding']->sort_order < $current['binding']->sort_order;
    }

    /** @param array<string, int|null> $overrides */
    private function resolveVersion(
        PromptModuleVersion $boundVersion,
        PromptModule $module,
        bool $required,
        array $overrides,
    ): ?PromptModuleVersion {
        if ($required || ! array_key_exists($module->module_key, $overrides)) {
            return $boundVersion;
        }

        $override = $overrides[$module->module_key];
        if ($override === null) {
            return null;
        }

        $version = PromptModuleVersion::query()->whereKey($override)->first();
        if ($version === null || (int) $version->prompt_module_id !== (int) $module->id) {
            throw new InvalidArgumentException("Override for {$module->module_key} must use a version from the bound module.");
        }

        if (! $this->isEligibleExpertOverride($version)) {
            throw new InvalidArgumentException("Override version {$version->id} is not eligible for expert selection.");
        }

        return $version;
    }

    private function isEligibleExpertOverride(PromptModuleVersion $version): bool
    {
        return in_array($version->lifecycle_status, [
            PromptModuleVersion::STATUS_TEST,
            PromptModuleVersion::STATUS_CANARY,
            PromptModuleVersion::STATUS_PRODUCTION,
            PromptModuleVersion::STATUS_DEPRECATED,
        ], true);
    }

    private function handleUnavailableBinding(bool $required, string $moduleKey): ?array
    {
        if ($required) {
            throw new InvalidArgumentException("Required module {$moduleKey} is not available for this preset context.");
        }

        return null;
    }
}
