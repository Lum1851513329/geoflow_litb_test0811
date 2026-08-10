<?php

namespace App\Domain\PromptComposition;

use App\Exceptions\PromptCompilationException;

final class PromptVariableValidator
{
    /** @var list<string> */
    private const CONFLICT_VARIABLES = [
        'cta_allowed',
        'requested_cta',
        'external_links_allowed',
        'requested_external_links',
        'evidence_required',
        'allow_unsupported_claims',
        'requested_language',
        'required_output_format',
        'output_format',
        'requested_target_length',
        'minimum_target_length',
        'maximum_target_length',
    ];

    /**
     * @param list<ResolvedPromptModule> $modules
     * @return array{resolved_variables: array<string, mixed>, sensitive_variables: list<string>, module_variables: array<int, list<string>>}
     */
    public function validate(array $modules, PromptContext $context): array
    {
        $available = $context->variableValues();
        $resolved = [];
        $sensitive = [];
        $moduleVariables = [];
        $errors = [];
        $declared = [];

        foreach ($modules as $module) {
            $definitions = $this->definitions($module->variableContract);
            $moduleVariables[$module->versionId] = array_keys($definitions);
            $declared = array_fill_keys(array_merge(array_keys($declared), array_keys($definitions)), true);

            foreach ($this->placeholders($module->content) as $placeholder) {
                if (! array_key_exists($placeholder, $definitions)) {
                    $this->error(
                        $errors,
                        "module.{$module->moduleKey}.variables.{$placeholder}.unknown",
                        "Variable {$placeholder} is used by the module but is not declared in its contract.",
                    );
                }
            }

            foreach ($definitions as $name => $definition) {
                $path = "module.{$module->moduleKey}.variables.{$name}";
                $hasValue = array_key_exists($name, $resolved) || array_key_exists($name, $available);
                $value = $resolved[$name] ?? $available[$name] ?? null;

                if (! $hasValue && array_key_exists('default', $definition)) {
                    $value = $definition['default'];
                    $hasValue = true;
                }

                if (($definition['required'] ?? false) && (! $hasValue || $this->isEmptyRequiredValue($value))) {
                    $this->error($errors, $path.'.required', "Variable {$name} is required.");

                    continue;
                }

                if (! $hasValue) {
                    continue;
                }

                $type = $definition['type'] ?? 'string';
                if (! in_array($type, ['string', 'integer', 'boolean', 'array', 'object'], true)) {
                    $this->error($errors, $path.'.type', "Contract type {$type} is not supported.");

                    continue;
                }

                if (! $this->matchesType($value, $type)) {
                    $this->error($errors, $path.'.type', "Variable {$name} must be of type {$type}.");

                    continue;
                }

                if (isset($definition['max_length'])) {
                    $maxLength = $definition['max_length'];
                    if (! is_int($maxLength) || $maxLength < 0) {
                        $this->error($errors, $path.'.max_length', 'The contract max_length must be a non-negative integer.');
                    } elseif (is_string($value) && mb_strlen($value, 'UTF-8') > $maxLength) {
                        $this->error($errors, $path.'.max_length', "Variable {$name} exceeds {$maxLength} characters.");
                    }
                }

                if (($definition['sensitive'] ?? false) === true) {
                    $sensitive[$name] = true;
                }

                if (! array_key_exists($name, $resolved)) {
                    $resolved[$name] = $value;
                }
            }
        }

        foreach ($context->variables as $name => $value) {
            if (! isset($declared[$name]) && ! in_array($name, self::CONFLICT_VARIABLES, true)) {
                $this->error(
                    $errors,
                    "context.variables.{$name}.unknown",
                    "Variable {$name} is not declared by any selected module.",
                );

                continue;
            }

            if (in_array($name, self::CONFLICT_VARIABLES, true) && ! array_key_exists($name, $resolved)) {
                $resolved[$name] = $value;
            }
        }

        foreach (['language', 'market', 'target_length'] as $name) {
            if (array_key_exists($name, $available) && ! array_key_exists($name, $resolved)) {
                $resolved[$name] = $available[$name];
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
        ];
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
            if (is_string($key) && is_array($item)) {
                $definitions[$name] ??= $item;
            } else {
                $definitions[$name] ??= ['type' => 'string'];
            }
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
            if (! is_array($definition)) {
                $definition = [];
            }
            $normalized[$name] = $definition;
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
            'object' => is_object($value) || (is_array($value) && (! array_is_list($value) || $value === [])),
            default => false,
        };
    }

    /** @param array<string, list<string>> $errors */
    private function error(array &$errors, string $path, string $message): void
    {
        $errors[$path][] = $message;
    }
}
