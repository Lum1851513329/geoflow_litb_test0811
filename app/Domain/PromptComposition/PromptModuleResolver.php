<?php

namespace App\Domain\PromptComposition;

use App\Models\PromptModule;
use App\Models\PromptModuleVersion;
use App\Models\PromptPresetModuleVersion;
use App\Models\PromptPresetVersion;
use InvalidArgumentException;

final class PromptModuleResolver
{
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
            ->map(fn (array $entry): ResolvedPromptModule => $entry['module'])
            ->values()
            ->all();

        return $resolved;
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
