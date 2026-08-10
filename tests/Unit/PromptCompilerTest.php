<?php

namespace Tests\Unit;

use App\Domain\PromptComposition\PromptCompiler;
use App\Domain\PromptComposition\PromptContext;
use App\Domain\PromptComposition\ResolvedPromptModule;
use App\Exceptions\PromptCompilationException;
use Tests\TestCase;

class PromptCompilerTest extends TestCase
{
    public function test_compiler_renders_one_coherent_prompt_and_records_conflict_decisions(): void
    {
        $compiled = app(PromptCompiler::class)->compile($this->modules(), $this->context([
            'cta_allowed' => false,
            'requested_cta' => 'strong',
        ]));

        $this->assertStringContainsString('CONTENT STRATEGY', $compiled->text);
        $this->assertStringContainsString('CONTENT BLUEPRINT', $compiled->text);
        $this->assertStringContainsString('Write for procurement teams in English.', $compiled->text);
        $this->assertStringNotContainsString('{{audience}}', $compiled->text);
        $this->assertSame('cta_disabled_by_hard_constraint', $compiled->conflictDecisions[0]['code']);
        $this->assertSame('cta_allowed=false', $compiled->conflictDecisions[0]['winner']);
        $this->assertSame('requested_cta=strong', $compiled->conflictDecisions[0]['loser']);
        $this->assertSame([101, 102, 103, 104, 105], $compiled->conflictDecisions[0]['affected_module_version_ids']);
    }

    public function test_missing_required_variable_has_a_field_addressable_error(): void
    {
        $module = $this->module(
            key: 'geo_citability',
            type: 'geo',
            content: 'Address {{missing_audience}}.',
            contract: ['variables' => [
                'missing_audience' => ['type' => 'string', 'required' => true],
            ]],
        );

        try {
            app(PromptCompiler::class)->compile([$module], $this->context());
            $this->fail('Expected compilation to reject a missing required variable.');
        } catch (PromptCompilationException $exception) {
            $this->assertArrayHasKey('module.geo_citability.variables.missing_audience.required', $exception->errors);
        }
    }

    public function test_placeholder_not_declared_by_the_module_is_rejected(): void
    {
        $module = $this->module(
            key: 'geo_citability',
            type: 'geo',
            content: 'Never render {{undeclared_value}}.',
            contract: ['variables' => []],
        );

        try {
            app(PromptCompiler::class)->compile([$module], $this->context());
            $this->fail('Expected compilation to reject an undeclared placeholder.');
        } catch (PromptCompilationException $exception) {
            $this->assertArrayHasKey('module.geo_citability.variables.undeclared_value.unknown', $exception->errors);
        }
    }

    public function test_contract_supports_all_types_defaults_and_max_length(): void
    {
        $module = $this->module(
            key: 'typed_contract',
            type: 'foundation',
            content: '{{name}} {{count}} {{enabled}} {{tags}} {{profile}} {{tone}}',
            contract: ['variables' => [
                'name' => ['type' => 'string', 'required' => true, 'max_length' => 12],
                'count' => ['type' => 'integer', 'required' => true],
                'enabled' => ['type' => 'boolean', 'required' => true],
                'tags' => ['type' => 'array', 'required' => true],
                'profile' => ['type' => 'object', 'required' => true],
                'tone' => ['type' => 'string', 'default' => 'clear'],
            ]],
        );

        $compiled = app(PromptCompiler::class)->compile([$module], $this->context([
            'name' => 'GEOFlow',
            'count' => 3,
            'enabled' => true,
            'tags' => ['geo', 'seo'],
            'profile' => ['level' => 'expert'],
        ]));

        $this->assertSame('clear', $compiled->resolvedVariables['tone']);
        $this->assertStringContainsString('GEOFlow 3 true ["geo","seo"] {"level":"expert"} clear', $compiled->text);
    }

    public function test_invalid_type_and_max_length_are_reported_together(): void
    {
        $module = $this->module(
            key: 'typed_contract',
            type: 'foundation',
            content: '{{name}} {{enabled}}',
            contract: ['variables' => [
                'name' => ['type' => 'string', 'required' => true, 'max_length' => 4],
                'enabled' => ['type' => 'boolean', 'required' => true],
            ]],
        );

        try {
            app(PromptCompiler::class)->compile([$module], $this->context([
                'name' => 'too long',
                'enabled' => 'yes',
            ]));
            $this->fail('Expected compilation to reject invalid variable values.');
        } catch (PromptCompilationException $exception) {
            $this->assertArrayHasKey('module.typed_contract.variables.name.max_length', $exception->errors);
            $this->assertArrayHasKey('module.typed_contract.variables.enabled.type', $exception->errors);
        }
    }

    public function test_direct_field_map_and_legacy_required_list_contracts_are_supported(): void
    {
        $direct = $this->module(
            key: 'direct_contract',
            type: 'geo',
            content: 'Use a {{tone}} tone.',
            contract: ['tone' => ['type' => 'string', 'default' => 'plain']],
            versionId: 110,
        );
        $legacy = $this->module(
            key: 'legacy_contract',
            type: 'foundation',
            content: 'Write for {{audience}}.',
            contract: ['required' => ['audience']],
            versionId: 111,
        );

        $compiled = app(PromptCompiler::class)->compile([$direct, $legacy], $this->context());

        $this->assertStringContainsString('Use a plain tone.', $compiled->text);
        $this->assertStringContainsString('Write for procurement teams.', $compiled->text);
    }

    public function test_sections_have_a_fixed_order_independent_of_module_type_order(): void
    {
        $modules = [
            $this->module('quality', 'quality_check', 'QUALITY MODULE', versionId: 209),
            $this->module('format', 'output_format', 'FORMAT MODULE', versionId: 208),
            $this->module('foundation', 'foundation', 'ROLE MODULE', versionId: 201),
            $this->module('language', 'language_market', 'LANGUAGE MODULE', versionId: 206),
            $this->module('policy', 'knowledge_policy', 'EVIDENCE MODULE', versionId: 203),
            $this->module('seo', 'seo', 'SEO MODULE', versionId: 205),
            $this->module('platform', 'platform', 'PLATFORM MODULE', versionId: 202),
        ];

        $text = app(PromptCompiler::class)->compile($modules, $this->context())->text;
        $headings = [
            '1. SYSTEM ROLE & TASK GOAL',
            '2. LEGAL, PLATFORM & TRUTHFULNESS HARD CONSTRAINTS',
            '3. BRAND & KNOWLEDGE EVIDENCE RULES',
            '4. CONTENT STRATEGY & CONTENT BLUEPRINT',
            '5. CONTENT TYPE, SEO & GEO REQUIREMENTS',
            '6. LANGUAGE, MARKET & WRITING STYLE',
            '7. KEYWORDS, PRODUCTS & DYNAMIC CONTEXT',
            '8. OUTPUT FORMAT',
            '9. QUALITY CHECKLIST',
        ];

        $positions = array_map(fn (string $heading): int|false => strpos($text, $heading), $headings);
        $this->assertNotContains(false, $positions);
        $this->assertSame($positions, array_values(array_unique(array_merge($positions, $positions))));
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
    }

    public function test_sensitive_values_are_redacted_from_snapshots_but_remain_usable_in_execution_text(): void
    {
        $module = $this->module(
            key: 'private_brand_context',
            type: 'foundation',
            content: 'Use private launch name {{campaign_secret}}.',
            contract: ['variables' => [
                'campaign_secret' => ['type' => 'string', 'required' => true, 'sensitive' => true],
            ]],
        );

        $compiled = app(PromptCompiler::class)->compile([$module], $this->context([
            'campaign_secret' => 'Project Firefly',
        ]));

        $this->assertStringContainsString('Project Firefly', $compiled->text);
        $this->assertSame('[REDACTED]', $compiled->resolvedVariables['campaign_secret']);
        $this->assertStringContainsString('[REDACTED]', $compiled->moduleSnapshots[0]['rendered_content']);
        $this->assertStringNotContainsString('Project Firefly', json_encode($compiled->moduleSnapshots, JSON_THROW_ON_ERROR));
    }

    public function test_required_module_strategy_and_blueprint_are_not_removed_when_budget_is_exceeded(): void
    {
        $module = $this->module(
            key: 'protected_truth',
            type: 'foundation',
            content: str_repeat('Required truth constraint. ', 200),
            required: true,
        );

        try {
            app(PromptCompiler::class)->compile([$module], $this->context(modelTokenBudget: 50));
            $this->fail('Expected protected content to exceed the model budget.');
        } catch (PromptCompilationException $exception) {
            $this->assertSame('prompt_token_budget_exceeded', $exception->codeName);
            $this->assertSame([101], $exception->details['protected_module_version_ids']);
            $this->assertSame(50, $exception->details['model_token_budget']);
        }
    }

    public function test_budget_trimming_removes_only_duplicate_and_low_relevance_evidence_and_records_each_decision(): void
    {
        $baseline = app(PromptCompiler::class)->compile($this->modules(), $this->context(evidence: []));
        $low = str_repeat('low relevance background ', 40);
        $duplicate = str_repeat('duplicate evidence ', 40);
        $high = 'Critical reviewed fact that must remain.';
        $context = $this->context(
            evidence: [
                ['id' => 'high', 'content' => $high, 'relevance' => 0.95],
                ['id' => 'duplicate-a', 'content' => $duplicate, 'relevance' => 0.8],
                ['id' => 'duplicate-b', 'content' => $duplicate, 'relevance' => 0.7],
                ['id' => 'low', 'content' => $low, 'relevance' => 0.1],
            ],
            modelTokenBudget: $baseline->estimatedTokens + 1200,
        );

        $compiled = app(PromptCompiler::class)->compile($this->modules(), $context);

        $this->assertStringContainsString($high, $compiled->text);
        $this->assertStringContainsString($duplicate, $compiled->text);
        $this->assertStringNotContainsString($low, $compiled->text);
        $this->assertContains('duplicate_evidence_removed', array_column($compiled->conflictDecisions, 'code'));
        $this->assertContains('low_relevance_evidence_removed', array_column($compiled->conflictDecisions, 'code'));
        $this->assertLessThanOrEqual($context->modelTokenBudget, $compiled->estimatedTokens);
    }

    public function test_duplicate_trimming_keeps_the_most_relevant_copy_deterministically(): void
    {
        $content = str_repeat('same fact ', 80);
        $baseline = app(PromptCompiler::class)->compile($this->modules(), $this->context(evidence: []));
        $compiled = app(PromptCompiler::class)->compile($this->modules(), $this->context(
            evidence: [
                ['id' => 'lower', 'content' => $content, 'relevance' => 0.2],
                ['id' => 'higher', 'content' => $content, 'relevance' => 0.95],
            ],
            modelTokenBudget: $baseline->estimatedTokens + 1000,
        ));

        $this->assertStringContainsString('"id": "higher"', $compiled->text);
        $this->assertStringNotContainsString('"id": "lower"', $compiled->text);
        $decision = collect($compiled->conflictDecisions)->firstWhere('code', 'duplicate_evidence_removed');
        $this->assertSame('evidence=higher', $decision['winner']);
        $this->assertSame('evidence=lower', $decision['loser']);
    }

    public function test_initial_conflicts_use_deterministic_precedence_and_structured_decisions(): void
    {
        $compiled = app(PromptCompiler::class)->compile($this->modules(), $this->context([
            'cta_allowed' => false,
            'requested_cta' => 'strong',
            'external_links_allowed' => false,
            'requested_external_links' => true,
            'evidence_required' => true,
            'allow_unsupported_claims' => true,
            'requested_language' => 'French',
            'required_output_format' => 'Markdown',
            'output_format' => 'HTML',
            'requested_target_length' => 900,
        ]));

        $this->assertSame(
            [
                'cta_disabled_by_hard_constraint',
                'external_links_disabled_by_hard_constraint',
                'evidence_requirement_enforced',
                'language_enforced',
                'output_format_enforced',
                'target_length_enforced',
            ],
            array_column($compiled->conflictDecisions, 'code'),
        );
        foreach ($compiled->conflictDecisions as $decision) {
            $this->assertSame(
                ['code', 'winner', 'loser', 'reason', 'affected_module_version_ids'],
                array_keys($decision),
            );
        }
        $this->assertSame('English', $compiled->resolvedVariables['requested_language']);
        $this->assertSame('Markdown', $compiled->resolvedVariables['output_format']);
        $this->assertSame(1800, $compiled->resolvedVariables['requested_target_length']);
    }

    public function test_context_digest_is_stable_for_reordered_associative_input(): void
    {
        $module = $this->module(
            key: 'custom_context',
            type: 'foundation',
            content: 'Use {{custom}}.',
            contract: ['variables' => [
                'custom' => ['type' => 'object', 'required' => true],
            ]],
        );
        $left = $this->context(['custom' => ['b' => 2, 'a' => 1]]);
        $right = $this->context(['custom' => ['a' => 1, 'b' => 2]]);

        $leftCompiled = app(PromptCompiler::class)->compile([$module], $left);
        $rightCompiled = app(PromptCompiler::class)->compile([$module], $right);

        $this->assertSame($leftCompiled->contextDigest, $rightCompiled->contextDigest);
    }

    /** @return list<ResolvedPromptModule> */
    private function modules(): array
    {
        return [
            $this->module(
                key: 'foundation_truth',
                type: 'foundation',
                content: 'Write for {{audience}} in {{language}}.',
                contract: ['variables' => [
                    'audience' => ['type' => 'string', 'required' => true],
                    'language' => ['type' => 'string', 'required' => true],
                ]],
                required: true,
                versionId: 101,
            ),
            $this->module('platform_rules', 'platform', 'Follow platform rules.', required: true, versionId: 102),
            $this->module('evidence_policy', 'knowledge_policy', 'Use cited evidence.', required: true, versionId: 103),
            $this->module('geo_citability', 'geo', 'Make claims directly citable.', required: true, versionId: 104),
            $this->module('markdown_output', 'output_format', 'Return Markdown.', required: true, versionId: 105),
        ];
    }

    private function module(
        string $key,
        string $type,
        string $content,
        array $contract = ['variables' => []],
        bool $required = false,
        int $versionId = 101,
    ): ResolvedPromptModule {
        return new ResolvedPromptModule(
            moduleId: $versionId + 1000,
            versionId: $versionId,
            moduleKey: $key,
            moduleType: $type,
            priority: 100,
            required: $required,
            content: $content,
            variableContract: $contract,
        );
    }

    private function context(
        array $variables = [],
        ?array $evidence = null,
        int $modelTokenBudget = 20_000,
    ): PromptContext {
        return new PromptContext(
            strategy: [
                'audience' => 'procurement teams',
                'primary_intent' => 'commercial_investigation',
            ],
            blueprint: [
                'working_title' => 'Choosing a supplier',
                'target_length' => 1800,
                'sections' => [['key' => 'criteria', 'heading' => 'Selection criteria']],
            ],
            keywords: ['supplier guide', 'vendor comparison'],
            evidence: $evidence ?? [['id' => 'fact-1', 'content' => 'Reviewed source fact.', 'relevance' => 0.9]],
            products: [['id' => 42, 'name' => 'Example product']],
            language: 'English',
            market: 'United States',
            variables: $variables,
            modelTokenBudget: $modelTokenBudget,
        );
    }
}
