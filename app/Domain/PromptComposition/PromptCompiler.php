<?php

namespace App\Domain\PromptComposition;

use App\Exceptions\PromptCompilationException;

final class PromptCompiler
{
    /** @var array<int, string> */
    private const SECTION_HEADINGS = [
        1 => 'SYSTEM ROLE & TASK GOAL',
        2 => 'LEGAL, PLATFORM & TRUTHFULNESS HARD CONSTRAINTS',
        3 => 'BRAND & KNOWLEDGE EVIDENCE RULES',
        4 => 'CONTENT STRATEGY & CONTENT BLUEPRINT',
        5 => 'CONTENT TYPE, SEO & GEO REQUIREMENTS',
        6 => 'LANGUAGE, MARKET & WRITING STYLE',
        7 => 'KEYWORDS, PRODUCTS & DYNAMIC CONTEXT',
        8 => 'OUTPUT FORMAT',
        9 => 'QUALITY CHECKLIST',
    ];

    public function __construct(
        private readonly PromptVariableValidator $variableValidator,
        private readonly PromptConflictResolver $conflictResolver,
    ) {}

    /** @param list<ResolvedPromptModule> $modules */
    public function compile(array $modules, PromptContext $context): CompiledPrompt
    {
        $this->assertModules($modules);
        $context = $this->normalizedContext($context);

        $validation = $this->variableValidator->validate($modules, $context);
        $resolution = $this->conflictResolver->resolve(
            $modules,
            $validation['resolved_variables'],
            $context,
        );
        $variables = $resolution['variables'];
        $decisions = array_merge($validation['precedence_decisions'], $resolution['decisions']);
        $evidence = $context->evidence;

        $text = $this->render($modules, $context, $variables, $evidence);
        $estimatedTokens = $this->estimateTokens($text);

        if ($estimatedTokens > $context->modelTokenBudget) {
            [$evidence, $duplicateDecisions] = $this->removeDuplicateEvidence($evidence, $modules);
            $decisions = array_merge($decisions, $duplicateDecisions);
            $text = $this->render($modules, $context, $variables, $evidence);
            $estimatedTokens = $this->estimateTokens($text);
        }

        if ($estimatedTokens > $context->modelTokenBudget) {
            while (($indexes = $this->lowRelevanceEvidenceIndexes($evidence)) !== []) {
                $index = $indexes[0];
                $removed = $evidence[$index];
                unset($evidence[$index]);
                $evidence = array_values($evidence);
                $decisions[] = $this->evidenceDecision(
                    'low_relevance_evidence_removed',
                    'model_token_budget='.$context->modelTokenBudget,
                    'evidence='.$this->evidenceIdentity($removed, $index),
                    'Low-relevance evidence was removed after duplicate removal to meet the selected model budget.',
                    $modules,
                );
                $text = $this->render($modules, $context, $variables, $evidence);
                $estimatedTokens = $this->estimateTokens($text);

                if ($estimatedTokens <= $context->modelTokenBudget) {
                    break;
                }
            }
        }

        if ($estimatedTokens > $context->modelTokenBudget) {
            $safeDecisions = $this->redactDecisions($decisions, $validation['sensitive_variables']);
            throw new PromptCompilationException(
                'Protected prompt content exceeds the selected model token budget.',
                'prompt_token_budget_exceeded',
                [],
                [
                    'estimated_tokens' => $estimatedTokens,
                    'model_token_budget' => $context->modelTokenBudget,
                    'protected_module_version_ids' => array_values(array_map(
                        fn (ResolvedPromptModule $module): int => $module->versionId,
                        array_values(array_filter($modules, fn (ResolvedPromptModule $module): bool => $module->required)),
                    )),
                    'protected_context' => ['strategy', 'blueprint'],
                    'conflict_decisions' => $safeDecisions,
                ],
            );
        }

        $variables['evidence'] = $evidence;
        $safeVariables = $this->redactVariables($variables, $validation['sensitive_variables']);
        $safeDecisions = $this->redactDecisions($decisions, $validation['sensitive_variables']);
        $contextDigest = hash('sha256', $this->canonicalJson([
            'context' => $context->snapshot(),
            'resolved_variables' => $variables,
        ]));

        return new CompiledPrompt(
            text: $text,
            moduleSnapshots: $this->moduleSnapshots(
                $modules,
                $variables,
                $validation['sensitive_variables'],
                $validation['module_variables'],
            ),
            resolvedVariables: $safeVariables,
            conflictDecisions: $safeDecisions,
            estimatedTokens: $estimatedTokens,
            contextDigest: $contextDigest,
        );
    }

    /** @param list<ResolvedPromptModule> $modules */
    private function assertModules(array $modules): void
    {
        $seenVersionIds = [];
        foreach ($modules as $index => $module) {
            if (! $module instanceof ResolvedPromptModule) {
                throw new PromptCompilationException(
                    "Prompt module at index {$index} is not a ResolvedPromptModule.",
                    'invalid_resolved_prompt_module',
                );
            }
            if (! mb_check_encoding($module->content, 'UTF-8')) {
                throw new PromptCompilationException(
                    "Prompt module version {$module->versionId} contains invalid UTF-8.",
                    'unsupported_prompt_context_value',
                );
            }
            if (isset($seenVersionIds[$module->versionId])) {
                throw new PromptCompilationException(
                    "Prompt module version {$module->versionId} was supplied more than once.",
                    'duplicate_prompt_module_version',
                );
            }
            $seenVersionIds[$module->versionId] = true;
        }
    }

    /**
     * @param list<ResolvedPromptModule> $modules
     * @param array<string, mixed> $variables
     * @param list<mixed> $evidence
     */
    private function render(array $modules, PromptContext $context, array $variables, array $evidence): string
    {
        $sections = array_fill(1, 9, []);
        $sections[1][] = 'Produce one coherent, complete article. Treat every section below as one writing contract.';
        $sections[2][] = $this->resolvedConstraintText($variables);
        $sections[4][] = "CONTENT STRATEGY\n".$this->prettyJson($context->strategy);
        $sections[4][] = "CONTENT BLUEPRINT\n".$this->prettyJson($context->blueprint);
        $sections[6][] = 'Target language: '.$this->dataJson($context->language)
            ."\nTarget market: ".$this->dataJson($context->market);
        $sections[7][] = "KEYWORDS\n".$this->prettyJson($context->keywords);
        $sections[7][] = "PRODUCTS\n".$this->prettyJson($context->products);
        $sections[7][] = "EVIDENCE\n".$this->prettyJson($evidence);
        $sections[8][] = $this->outputConstraintText($variables);
        $sections[9][] = 'Before returning the article, verify factual support, blueprint coverage, language, links, CTA, length, and output format.';

        $renderVariables = $variables;
        $renderVariables['evidence'] = $evidence;

        foreach ($modules as $module) {
            $sections[$this->sectionFor($module)][] = sprintf(
                "MODULE %s (version %d)\n%s",
                $module->moduleKey,
                $module->versionId,
                $this->renderTemplate($module->content, $renderVariables),
            );
        }

        $rendered = [];
        foreach (self::SECTION_HEADINGS as $number => $heading) {
            $content = array_values(array_filter($sections[$number], fn (string $value): bool => trim($value) !== ''));
            $rendered[] = $number.'. '.$heading."\n\n".implode("\n\n", $content);
        }

        $text = implode("\n\n---\n\n", $rendered);
        if (preg_match('/{{[^{}]*}}/u', $text, $unresolved) === 1) {
            throw new PromptCompilationException(
                'Compiled prompt contains an unresolved placeholder.',
                'unresolved_prompt_placeholder',
                ['compiled_prompt.placeholder.unresolved' => [$unresolved[0]]],
            );
        }

        return $text;
    }

    private function sectionFor(ResolvedPromptModule $module): int
    {
        return match ($module->moduleType) {
            'foundation' => 1,
            'platform' => 2,
            'knowledge_policy' => 3,
            'content_type', 'seo', 'geo' => 5,
            'language_market', 'persona', 'maturity_stage' => 6,
            'output_format' => 8,
            'quality_check' => 9,
            default => 5,
        };
    }

    /** @param array<string, mixed> $variables */
    private function renderTemplate(string $template, array $variables): string
    {
        return preg_replace_callback(
            '/{{\s*([A-Za-z_][A-Za-z0-9_.-]*)\s*}}/u',
            fn (array $matches): string => $this->renderValue($variables[$matches[1]] ?? null),
            $template,
        ) ?? $template;
    }

    private function renderValue(mixed $value): string
    {
        return $this->dataJson($value);
    }

    /** @param array<string, mixed> $variables */
    private function resolvedConstraintText(array $variables): string
    {
        $keys = [
            'cta_allowed', 'requested_cta', 'external_links_allowed',
            'requested_external_links', 'evidence_required', 'allow_unsupported_claims',
        ];

        return "RESOLVED HARD CONSTRAINTS\n".$this->prettyJson(array_intersect_key($variables, array_flip($keys)));
    }

    /** @param array<string, mixed> $variables */
    private function outputConstraintText(array $variables): string
    {
        $keys = ['required_output_format', 'output_format', 'requested_target_length', 'target_length'];

        return "RESOLVED OUTPUT CONTRACT\n".$this->prettyJson(array_intersect_key($variables, array_flip($keys)));
    }

    private function prettyJson(mixed $value): string
    {
        return $this->dataJson($value, true);
    }

    private function normalizedContext(PromptContext $context): PromptContext
    {
        /** @var array<string, mixed> $snapshot */
        $snapshot = $this->canonicalize($context->snapshot());

        return new PromptContext(
            strategy: $snapshot['strategy'],
            blueprint: $snapshot['blueprint'],
            keywords: $snapshot['keywords'],
            evidence: $snapshot['evidence'],
            products: $snapshot['products'],
            language: $snapshot['language'],
            market: $snapshot['market'],
            variables: $snapshot['variables'],
            modelTokenBudget: $snapshot['model_token_budget'],
        );
    }

    private function dataJson(mixed $value, bool $pretty = false): string
    {
        return json_encode(
            $this->escapeStructuralData($this->canonicalize($value)),
            ($pretty ? JSON_PRETTY_PRINT : 0)
                | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    private function escapeStructuralData(mixed $value): mixed
    {
        if (is_string($value)) {
            return str_replace(['{', '}'], ['\\u007B', '\\u007D'], $value);
        }
        if (! is_array($value)) {
            return $value;
        }

        $escaped = [];
        foreach ($value as $key => $item) {
            $safeKey = is_string($key)
                ? str_replace(['{', '}'], ['\\u007B', '\\u007D'], $key)
                : $key;
            $escaped[$safeKey] = $this->escapeStructuralData($item);
        }

        return $escaped;
    }

    /* A byte fallback tokenizer cannot emit more tokens than input bytes. */
    private function estimateTokens(string $text): int
    {
        return strlen($text);
    }

    /**
     * @param list<array{code: string, winner: string, loser: string, reason: string, affected_module_version_ids: list<int>}> $decisions
     * @param list<string> $sensitiveVariables
     * @return list<array{code: string, winner: string, loser: string, reason: string, affected_module_version_ids: list<int>}>
     */
    private function redactDecisions(array $decisions, array $sensitiveVariables): array
    {
        $sensitiveValues = [];
        foreach ($decisions as $decision) {
            foreach (['winner', 'loser'] as $field) {
                foreach ($sensitiveVariables as $name) {
                    if (preg_match('/'.preg_quote($name, '/').'=(.*)$/u', $decision[$field], $match) === 1
                        && $match[1] !== '') {
                        $sensitiveValues[$match[1]] = true;
                    }
                }
            }
        }
        $sensitiveValues = array_keys($sensitiveValues);
        usort($sensitiveValues, fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        foreach ($decisions as &$decision) {
            foreach (['winner', 'loser', 'reason'] as $field) {
                $decision[$field] = str_replace($sensitiveValues, '[REDACTED]', $decision[$field]);
                foreach ($sensitiveVariables as $name) {
                    $decision[$field] = preg_replace(
                        '/('.preg_quote($name, '/').'=).*$/u',
                        '$1[REDACTED]',
                        $decision[$field],
                    ) ?? $decision[$field];
                }
            }
        }
        unset($decision);

        return $decisions;
    }

    /**
     * @param list<mixed> $evidence
     * @param list<ResolvedPromptModule> $modules
     * @return array{0: list<mixed>, 1: list<array{code: string, winner: string, loser: string, reason: string, affected_module_version_ids: list<int>}>}
     */
    private function removeDuplicateEvidence(array $evidence, array $modules): array
    {
        $groups = [];
        $groupOrder = [];
        $decisions = [];

        foreach ($evidence as $index => $item) {
            $fingerprint = hash('sha256', $this->normalizedEvidenceContent($item));
            if (! isset($groups[$fingerprint])) {
                $groupOrder[] = $fingerprint;
            }
            $groups[$fingerprint][] = [
                'item' => $item,
                'index' => $index,
                'relevance' => $this->evidenceRelevance($item),
            ];
        }

        $kept = [];
        foreach ($groupOrder as $fingerprint) {
            $candidates = $groups[$fingerprint];
            usort($candidates, fn (array $left, array $right): int => [
                -$left['relevance'], $left['index'],
            ] <=> [
                -$right['relevance'], $right['index'],
            ]);
            $winner = array_shift($candidates);
            $kept[] = $winner['item'];

            foreach ($candidates as $loser) {
                $decisions[] = $this->evidenceDecision(
                    'duplicate_evidence_removed',
                    'evidence='.$this->evidenceIdentity($winner['item'], $winner['index']),
                    'evidence='.$this->evidenceIdentity($loser['item'], $loser['index']),
                    'The most relevant exact normalized-content copy was retained before lower-relevance evidence was considered.',
                    $modules,
                );
            }
        }

        return [$kept, $decisions];
    }

    /** @param list<mixed> $evidence @return list<int> */
    private function lowRelevanceEvidenceIndexes(array $evidence): array
    {
        $candidates = [];
        foreach ($evidence as $index => $item) {
            $relevance = $this->evidenceRelevance($item);
            if ($relevance < 0.5) {
                $candidates[] = ['index' => $index, 'relevance' => $relevance];
            }
        }
        usort($candidates, fn (array $left, array $right): int => [$left['relevance'], $left['index']] <=> [$right['relevance'], $right['index']]);

        return array_column($candidates, 'index');
    }

    private function evidenceRelevance(mixed $evidence): float
    {
        return is_array($evidence) && is_numeric($evidence['relevance'] ?? null)
            ? (float) $evidence['relevance']
            : 0.5;
    }

    private function normalizedEvidenceContent(mixed $evidence): string
    {
        $content = is_array($evidence) ? ($evidence['content'] ?? $evidence) : $evidence;
        $rendered = is_string($content) ? $content : $this->canonicalJson($content);
        $rendered = preg_replace('/\s+/u', ' ', trim($rendered)) ?? trim($rendered);

        return mb_strtolower($rendered, 'UTF-8');
    }

    private function evidenceIdentity(mixed $evidence, int $fallbackIndex): string
    {
        if (is_array($evidence) && (is_string($evidence['id'] ?? null) || is_int($evidence['id'] ?? null))) {
            return (string) $evidence['id'];
        }

        return 'index-'.$fallbackIndex;
    }

    /**
     * @param list<ResolvedPromptModule> $modules
     * @return array{code: string, winner: string, loser: string, reason: string, affected_module_version_ids: list<int>}
     */
    private function evidenceDecision(string $code, string $winner, string $loser, string $reason, array $modules): array
    {
        $affected = array_values(array_map(
            fn (ResolvedPromptModule $module): int => $module->versionId,
            array_values(array_filter(
                $modules,
                fn (ResolvedPromptModule $module): bool => $module->moduleType === 'knowledge_policy',
            )),
        ));

        return [
            'code' => $code,
            'winner' => $winner,
            'loser' => $loser,
            'reason' => $reason,
            'affected_module_version_ids' => $affected,
        ];
    }

    /**
     * @param list<ResolvedPromptModule> $modules
     * @param array<string, mixed> $variables
     * @param list<string> $sensitiveVariables
     * @param array<int, list<string>> $moduleVariables
     * @return list<array<string, mixed>>
     */
    private function moduleSnapshots(array $modules, array $variables, array $sensitiveVariables, array $moduleVariables): array
    {
        $snapshots = [];
        foreach ($modules as $module) {
            $names = $moduleVariables[$module->versionId] ?? [];
            $moduleResolved = array_intersect_key($variables, array_flip($names));
            $safeResolved = $this->redactVariables($moduleResolved, $sensitiveVariables);
            $snapshots[] = [
                'module_id' => $module->moduleId,
                'module_key' => $module->moduleKey,
                'module_type' => $module->moduleType,
                'version_id' => $module->versionId,
                'priority' => $module->priority,
                'required' => $module->required,
                'template_sha256' => hash('sha256', $module->content),
                'rendered_content' => $this->renderTemplate($module->content, $safeResolved),
                'resolved_variables' => $safeResolved,
            ];
        }

        return $snapshots;
    }

    /** @param array<string, mixed> $variables @param list<string> $sensitiveVariables @return array<string, mixed> */
    private function redactVariables(array $variables, array $sensitiveVariables): array
    {
        foreach ($sensitiveVariables as $name) {
            if (array_key_exists($name, $variables)) {
                $variables[$name] = '[REDACTED]';
            }
        }

        return $variables;
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if ($value instanceof \JsonSerializable) {
            $value = $value->jsonSerialize();
        } elseif (is_object($value) || is_resource($value)) {
            throw new PromptCompilationException(
                'Prompt context contains an unsupported object or resource.',
                'unsupported_prompt_context_value',
            );
        }
        if (is_float($value) && ! is_finite($value)) {
            throw new PromptCompilationException(
                'Prompt context contains a non-finite number.',
                'unsupported_prompt_context_value',
            );
        }
        if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
            throw new PromptCompilationException(
                'Prompt context contains invalid UTF-8.',
                'unsupported_prompt_context_value',
            );
        }
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
