<?php

namespace App\Domain\PromptComposition;

use App\Exceptions\PromptCompilationException;

final class PromptVariableValidator
{
    /** @var array<string, string> */
    private const BUILT_IN_TYPES = [
        'cta_allowed' => 'boolean',
        'requested_cta' => 'string',
        'external_links_allowed' => 'boolean',
        'requested_external_links' => 'boolean',
        'external_links_requested' => 'boolean',
        'evidence_required' => 'boolean',
        'evidence_available' => 'boolean',
        'allow_unsupported_claims' => 'boolean',
        'language' => 'string',
        'required_language' => 'string',
        'requested_language' => 'string',
        'output_format' => 'string',
        'required_output_format' => 'string',
        'target_length' => 'integer',
        'requested_target_length' => 'integer',
        'max_target_length' => 'integer',
        'minimum_target_length' => 'integer',
        'maximum_target_length' => 'integer',
    ];

    /**
     * @param list<ResolvedPromptModule> $modules
     * @return array{
     *   resolved_variables: array<string, mixed>,
     *   sensitive_variables: list<string>,
     *   module_variables: array<int, list<string>>,
     *   precedence_decisions: list<array{code: string, winner: string, loser: string, reason: string, affected_module_version_ids: list<int>}>
     * }
     */
    public function validate(array $modules, PromptContext $context): array
    {
        $errors = [];
        $definitionsByVersion = [];
        $declared = [];
        $declaredVersions = [];
        $sensitive = [];
        $moduleVariables = [];

        foreach ($modules as $module) {
            $definitions = $this->definitions($module->variableContract);
            $definitionsByVersion[$module->versionId] = $definitions;
            $moduleVariables[$module->versionId] = array_keys($definitions);

            foreach ($definitions as $name => $definition) {
                $declared[$name] = true;
                $declaredVersions[$name][$module->versionId] = true;
                if (str_contains($name, '.')) {
                    $this->error(
                        $errors,
                        "module.{$module->moduleKey}.variables.{$name}.unsupported_name",
                        'Dotted variable names are not supported.',
                    );
                }
                if (($definition['sensitive'] ?? false) === true) {
                    $sensitive[$name] = true;
                }
            }

            foreach ($this->placeholders($module->content) as $placeholder) {
                if (str_contains($placeholder, '.')) {
                    $this->error(
                        $errors,
                        "module.{$module->moduleKey}.variables.{$placeholder}.unsupported_name",
                        'Dotted placeholders are not supported.',
                    );
                } elseif (! array_key_exists($placeholder, $definitions)) {
                    $this->error(
                        $errors,
                        "module.{$module->moduleKey}.variables.{$placeholder}.unknown",
                        "Variable {$placeholder} is used by the module but is not declared in its contract.",
                    );
                }
            }
        }

        foreach ($context->variables as $name => $value) {
            if (str_contains((string) $name, '.')) {
                $this->error($errors, "context.variables.{$name}.unsupported_name", 'Dotted variable names are not supported.');

                continue;
            }
            if (! isset($declared[$name]) && ! isset(self::BUILT_IN_TYPES[$name])) {
                $this->error($errors, "context.variables.{$name}.unknown", "Variable {$name} is not declared by any selected module.");
            }
            if (isset(self::BUILT_IN_TYPES[$name]) && ! $this->matchesType($value, self::BUILT_IN_TYPES[$name])) {
                $this->error(
                    $errors,
                    "context.variables.{$name}.type",
                    "Built-in variable {$name} must be of type ".self::BUILT_IN_TYPES[$name].'.',
                );
            }
        }

        if (array_key_exists('target_length', $context->blueprint)
            && ! $this->matchesType($context->blueprint['target_length'], 'integer')) {
            $this->error($errors, 'context.blueprint.target_length.type', 'Blueprint target_length must be an integer.');
        }

        $candidates = $this->contextCandidates($context, $declared);
        foreach ($modules as $module) {
            foreach ($definitionsByVersion[$module->versionId] as $name => $definition) {
                if (! array_key_exists('default', $definition)) {
                    continue;
                }
                $this->validateValue(
                    $definition['default'],
                    $definition,
                    "module.{$module->moduleKey}.variables.{$name}",
                    $errors,
                );
                if (isset(self::BUILT_IN_TYPES[$name])
                    && ! $this->matchesType($definition['default'], self::BUILT_IN_TYPES[$name])) {
                    $this->error(
                        $errors,
                        "module.{$module->moduleKey}.variables.{$name}.type",
                        "Built-in variable {$name} must be of type ".self::BUILT_IN_TYPES[$name].'.',
                    );
                }
                $candidates[$name][] = [
                    'value' => $definition['default'],
                    'rank' => $this->modulePrecedence($module),
                    'tie' => $module->priority,
                    'source' => "module.{$module->moduleKey}.default.{$name}",
                    'version_ids' => [$module->versionId],
                ];
            }
        }

        [$resolved, $precedenceDecisions] = $this->resolveCandidates($candidates, $declaredVersions);

        foreach (self::BUILT_IN_TYPES as $name => $type) {
            if (array_key_exists($name, $resolved) && ! $this->matchesType($resolved[$name], $type)) {
                $this->error($errors, "variables.{$name}.type", "Built-in variable {$name} must be of type {$type}.");
            }
        }

        foreach ($modules as $module) {
            foreach ($definitionsByVersion[$module->versionId] as $name => $definition) {
                $path = "module.{$module->moduleKey}.variables.{$name}";
                if (! array_key_exists($name, $resolved)) {
                    if (($definition['required'] ?? false) === true) {
                        $this->error($errors, $path.'.required', "Variable {$name} is required.");
                    }

                    continue;
                }
                if (($definition['required'] ?? false) === true && $this->isEmptyRequiredValue($resolved[$name])) {
                    $this->error($errors, $path.'.required', "Variable {$name} is required.");

                    continue;
                }
                $this->validateValue($resolved[$name], $definition, $path, $errors);
            }
        }

        if ($errors !== []) {
            throw new PromptCompilationException(
                'Prompt variable validation failed.',
                'prompt_variable_validation_failed',
                $errors,
            );
        }

        return [
            'resolved_variables' => $resolved,
            'sensitive_variables' => array_keys($sensitive),
            'module_variables' => $moduleVariables,
            'precedence_decisions' => $precedenceDecisions,
        ];
    }

    /**
     * @param array<string, true> $declared
     * @return array<string, list<array{value: mixed, rank: int, tie: int, source: string, version_ids: list<int>}>>
     */
    private function contextCandidates(PromptContext $context, array $declared): array
    {
        $candidates = [];
        foreach ($context->variables as $name => $value) {
            if (isset($declared[$name]) || isset(self::BUILT_IN_TYPES[$name])) {
                $candidates[$name][] = $this->candidate($value, 100, 0, "context.variables.{$name}");
            }
        }
        foreach ($context->strategy as $name => $value) {
            if (is_string($name) && (isset($declared[$name]) || isset(self::BUILT_IN_TYPES[$name]))) {
                $candidates[$name][] = $this->candidate($value, 200, 20, "strategy.{$name}");
            }
        }
        foreach ($context->blueprint as $name => $value) {
            if (is_string($name) && (isset($declared[$name]) || isset(self::BUILT_IN_TYPES[$name]))) {
                $candidates[$name][] = $this->candidate($value, 200, 30, "blueprint.{$name}");
            }
        }

        foreach ([
            'strategy' => $context->strategy,
            'blueprint' => $context->blueprint,
            'keywords' => $context->keywords,
            'products' => $context->products,
            'market' => $context->market,
        ] as $name => $value) {
            if (isset($declared[$name]) || isset(self::BUILT_IN_TYPES[$name])) {
                $candidates[$name][] = $this->candidate($value, 200, 40, "context.{$name}");
            }
        }

        if (isset($declared['evidence'])) {
            $candidates['evidence'][] = $this->candidate($context->evidence, 500, 40, 'context.evidence');
        }
        $candidates['evidence_available'][] = $this->candidate($context->evidence !== [], 700, 50, 'context.evidence');
        $candidates['language'][] = $this->candidate($context->language, 200, 50, 'context.language');

        return $candidates;
    }

    /** @return array{value: mixed, rank: int, tie: int, source: string, version_ids: list<int>} */
    private function candidate(mixed $value, int $rank, int $tie, string $source): array
    {
        return compact('value', 'rank', 'tie', 'source') + ['version_ids' => []];
    }

    /**
     * @param array<string, list<array{value: mixed, rank: int, tie: int, source: string, version_ids: list<int>}>> $candidates
     * @param array<string, array<int, true>> $declaredVersions
     * @return array{0: array<string, mixed>, 1: list<array{code: string, winner: string, loser: string, reason: string, affected_module_version_ids: list<int>}>}
     */
    private function resolveCandidates(array $candidates, array $declaredVersions): array
    {
        $resolved = [];
        $decisions = [];
        ksort($candidates, SORT_STRING);

        foreach ($candidates as $name => $sources) {
            usort($sources, fn (array $left, array $right): int => [
                -$left['rank'], -$left['tie'], $left['source'],
            ] <=> [
                -$right['rank'], -$right['tie'], $right['source'],
            ]);
            $winner = array_shift($sources);
            $resolved[$name] = $winner['value'];

            foreach ($sources as $loser) {
                if ($this->sameValue($winner['value'], $loser['value'])) {
                    continue;
                }
                $affectedVersionIds = array_values(array_unique(array_merge(
                    $winner['version_ids'],
                    $loser['version_ids'],
                    array_keys($declaredVersions[$name] ?? []),
                )));
                sort($affectedVersionIds, SORT_NUMERIC);
                $decisions[] = [
                    'code' => 'variable_precedence_resolved',
                    'winner' => $winner['source'].'='.$this->displayValue($winner['value']),
                    'loser' => $loser['source'].'='.$this->displayValue($loser['value']),
                    'reason' => 'Resolved by hard constraint > evidence > stage > brand > strategy > style precedence.',
                    'affected_module_version_ids' => $affectedVersionIds,
                ];
            }
        }

        return [$resolved, $decisions];
    }

    private function modulePrecedence(ResolvedPromptModule $module): int
    {
        if (in_array($module->moduleType, ['foundation', 'platform'], true)) {
            return 600;
        }
        if ($module->moduleType === 'knowledge_policy') {
            return 500;
        }
        if ($module->moduleType === 'maturity_stage' || str_contains($module->moduleKey, 'stage')) {
            return 400;
        }
        if ($module->moduleType === 'brand' || str_contains($module->moduleKey, 'brand')) {
            return 300;
        }
        if (in_array($module->moduleType, ['content_type', 'seo', 'geo'], true)) {
            return 200;
        }

        return 100;
    }

    /** @param array<string, mixed> $definition @param array<string, list<string>> $errors */
    private function validateValue(mixed $value, array $definition, string $path, array &$errors): void
    {
        $type = $definition['type'] ?? 'string';
        if (! in_array($type, ['string', 'integer', 'boolean', 'array', 'object'], true)) {
            $this->error($errors, $path.'.type', "Contract type {$type} is not supported.");

            return;
        }
        if (! $this->matchesType($value, $type)) {
            $this->error($errors, $path.'.type', "Variable must be of type {$type}.");

            return;
        }
        if (isset($definition['max_length'])) {
            $maxLength = $definition['max_length'];
            if (! is_int($maxLength) || $maxLength < 0) {
                $this->error($errors, $path.'.max_length', 'The contract max_length must be a non-negative integer.');
            } elseif (is_string($value) && mb_strlen($value, 'UTF-8') > $maxLength) {
                $this->error($errors, $path.'.max_length', "Variable exceeds {$maxLength} characters.");
            }
        }
    }

    /** @param array<string, mixed> $contract @return array<string, array<string, mixed>> */
    private function definitions(array $contract): array
    {
        if (array_key_exists('variables', $contract)) {
            $definitions = $contract['variables'];
        } elseif (array_key_exists('properties', $contract)) {
            $definitions = $contract['properties'];
        } else {
            $definitions = array_diff_key($contract, array_flip([
                'type', 'required', 'optional', 'additionalProperties', '$schema', 'description',
            ]));
        }
        if (! is_array($definitions)) {
            $definitions = [];
        }
        if (array_is_list($definitions)) {
            $named = [];
            foreach ($definitions as $definition) {
                if (is_array($definition) && is_string($definition['name'] ?? null)) {
                    $name = $definition['name'];
                    unset($definition['name']);
                    $named[$name] = $definition;
                }
            }
            $definitions = $named;
        }
        $optional = is_array($contract['optional'] ?? null) ? $contract['optional'] : [];
        foreach ($optional as $name => $definition) {
            if (is_int($name) && is_string($definition)) {
                $definitions[$definition] ??= ['type' => 'string'];
            } elseif (is_string($name)) {
                $definitions[$name] ??= is_array($definition) ? $definition : ['type' => (string) $definition];
            }
        }
        $required = is_array($contract['required'] ?? null) ? $contract['required'] : [];
        foreach ($required as $key => $item) {
            $name = is_string($key) ? $key : $item;
            if (! is_string($name)) {
                continue;
            }
            $definitions[$name] ??= is_string($key) && is_array($item) ? $item : ['type' => 'string'];
            $definitions[$name]['required'] = true;
        }
        $normalized = [];
        foreach ($definitions as $name => $definition) {
            if (! is_string($name)) {
                continue;
            }
            if (is_string($definition)) {
                $definition = ['type' => $definition];
            }
            $normalized[$name] = is_array($definition) ? $definition : [];
        }

        return $normalized;
    }

    /** @return list<string> */
    private function placeholders(string $content): array
    {
        preg_match_all('/{{\s*([A-Za-z_][A-Za-z0-9_.-]*)\s*}}/u', $content, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    private function isEmptyRequiredValue(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            'array' => is_array($value) && (array_is_list($value) || $value === []),
            'object' => $value instanceof \JsonSerializable || (is_array($value) && (! array_is_list($value) || $value === [])) || is_object($value),
            default => false,
        };
    }

    private function sameValue(mixed $left, mixed $right): bool
    {
        return serialize($left) === serialize($right);
    }

    private function displayValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @param array<string, list<string>> $errors */
    private function error(array &$errors, string $path, string $message): void
    {
        $errors[$path][] = $message;
    }
}
