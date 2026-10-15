<?php

namespace App\Domain\ContentStrategy;

use App\Models\Admin;
use App\Models\ContentBlueprintVersion;
use App\Models\ContentBrief;
use App\Models\ContentStrategyVersion;
use App\Models\PromptPreset;
use App\Services\GeoFlow\KnowledgeGovernance\GenerationKnowledgeScopeResolver;
use App\Services\GeoFlow\BlueprintSectionPlanner;
use App\Services\GeoFlow\BriefQuestionPolishingService;
use DomainException;
use Illuminate\Support\Facades\DB;

final class ContentBriefQuickCreateService
{
    public function __construct(
        private readonly BriefQuestionPolishingService $questionPolisher,
        private readonly BlueprintSectionPlanner $sectionPlanner,
    ) {}
    /**
     * @param  array{topic: string, core_question?: ?string, category_id?: ?int, primary_keyword_id?: ?int, keyword_cluster_id?: ?int, reference_urls?: list<string>, task_id?: ?int}  $data
     */
    public function create(array $data, PromptPreset $preset, Admin $actor): ContentBrief
    {
        $presetVersion = $preset->currentVersion;
        if ($presetVersion === null || $presetVersion->blueprint_template === null) {
            throw new DomainException("Preset \"{$preset->preset_key}\" has no blueprint_template on its current version.");
        }

        $topic = trim((string) $data['topic']);
        if ($topic === '') {
            throw new DomainException('topic is required.');
        }
        $coreQuestion = trim((string) ($data['core_question'] ?? ''));
        $originalQuestion = $coreQuestion;
        if ($coreQuestion === '') {
            $coreQuestion = $this->defaultCoreQuestion($topic, $preset, (string) (($preset->currentVersion?->default_brief_fields ?? [])['content_type'] ?? ''));
        }
        $model = (int) ($data['ai_model_id'] ?? 0) > 0 ? \App\Models\AiModel::query()->find((int) $data['ai_model_id']) : null;
        if ($model !== null && $originalQuestion !== '') {
            $coreQuestion = $this->questionPolisher->polish($model, $topic, $coreQuestion, (string) ($data['language_code'] ?? 'en')) ?: $coreQuestion;
        }
        $plannedSections = $this->sectionPlanner->planSections($model, $topic, $coreQuestion, (array) $presetVersion->blueprint_template);

        $defaults = (array) ($presetVersion->default_brief_fields ?? []);

        return DB::transaction(function () use ($data, $presetVersion, $actor, $topic, $coreQuestion, $defaults, $plannedSections): ContentBrief {
            $scopeData = $data;
            if (($scopeData['knowledge_mode'] ?? 'legacy_manual') === 'legacy_manual') {
                $scopeData = ['knowledge_mode' => 'legacy_manual'];
            }
            $scope = app(GenerationKnowledgeScopeResolver::class)->normalize($scopeData);
            $brief = ContentBrief::forceCreate(array_merge($scope, [
                'external_evidence_policy' => (string) ($data['external_evidence_policy'] ?? 'off'),
                'external_general_domains' => array_values((array) ($data['external_general_domains'] ?? [])),
                'topic' => $topic,
                'market_code' => (string) ($defaults['market_code'] ?? 'GLOBAL'),
                'language_code' => (string) ($data['language_code'] ?? $defaults['language_code'] ?? 'en'),
                'audience' => (string) ($defaults['audience'] ?? ''),
                'core_question' => $coreQuestion,
                'business_goal' => (string) ($defaults['business_goal'] ?? ''),
                'content_type' => (string) ($defaults['content_type'] ?? ''),
                'keyword_cluster_id' => $data['keyword_cluster_id'] ?? null,
                'primary_keyword_id' => $data['primary_keyword_id'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'reference_urls' => array_values($data['reference_urls'] ?? []),
                'cta_strength' => (string) ($defaults['cta_strength'] ?? 'soft'),
                'status' => ContentBrief::STATUS_STRATEGY_READY,
                'created_by_admin_id' => $actor->id,
                'task_id' => $data['task_id'] ?? null,
                'asset_provenance' => isset($data['retrieval_keyword']) ? ['retrieval_keyword' => mb_substr(trim((string) $data['retrieval_keyword']), 0, 300)] : null,
            ]));

            $strategy = [
                'audience' => (string) ($defaults['audience'] ?? ''),
                'core_question' => $coreQuestion,
                'primary_intent' => 'commercial',
                'secondary_intents' => [],
                'decision_stage' => 'consideration',
                'content_type' => (string) ($defaults['content_type'] ?? ''),
                'content_goal' => (string) ($defaults['business_goal'] ?? ''),
                'cta_strength' => (string) ($defaults['cta_strength'] ?? 'soft'),
                'target_url' => null,
                'target_keyword_cluster' => null,
                'questions_to_answer' => [$coreQuestion],
                'risks' => [],
                'evidence_gaps' => [],
            ];
            $strategyVersion = ContentStrategyVersion::forceCreate([
                'content_brief_id' => $brief->id,
                'version' => 1,
                'strategy' => $strategy,
                'suggestion_snapshot' => null,
                'lifecycle_status' => ContentStrategyVersion::STATUS_CONFIRMED,
                'created_by_admin_id' => $actor->id,
                'confirmed_by_admin_id' => $actor->id,
                'confirmed_at' => now(),
            ]);

            $blueprint = $this->instantiateBlueprint((array) $presetVersion->blueprint_template, $topic, $coreQuestion, $plannedSections);
            ContentBlueprintVersion::forceCreate([
                'content_brief_id' => $brief->id,
                'content_strategy_version_id' => $strategyVersion->id,
                'version' => 1,
                'blueprint' => $blueprint,
                'validation_report' => null,
                'lifecycle_status' => ContentBlueprintVersion::STATUS_CONFIRMED,
                'created_by_admin_id' => $actor->id,
                'confirmed_by_admin_id' => $actor->id,
                'confirmed_at' => now(),
            ]);

            return $brief;
        });
    }

    private function defaultCoreQuestion(string $topic, PromptPreset $preset, string $contentType): string
    {
        $text = mb_strtolower($topic.' '.(string) $preset->preset_key.' '.$contentType);
        if (str_contains($text, 'shipping') || str_contains($text, 'return') || str_contains($text, 'payment') || str_contains($text, 'tracking')) {
            return "What steps, timing, costs, and limitations should a shopper verify for {$topic}?";
        }
        if (str_contains($text, 'comparison') || str_contains($text, 'compare') || str_contains($text, ' vs ')) {
            return "Which differences, trade-offs, and use cases matter most when comparing {$topic}?";
        }
        if (str_contains($text, 'trend') || str_contains($text, 'industry') || str_contains($text, 'what is') || str_contains($text, 'guide')) {
            return "What definitions, evidence, and practical criteria help readers understand {$topic}?";
        }
        if (str_contains($text, 'brand') || str_contains($text, 'company') || str_contains($text, 'official')) {
            return "Which verifiable facts and official sources explain {$topic} to a global shopper?";
        }
        if (str_contains($text, 'buy') || str_contains($text, 'shopping') || str_contains($text, 'clothing') || str_contains($text, 'dress')) {
            return "How can shoppers evaluate {$topic} by fit, materials, budget, and intended use?";
        }

        return "What practical criteria and evidence should a reader use to make a confident decision about {$topic}?";
    }

    /**
     * 同一 preset 下所有文章的 blueprint.sections[].heading 原样来自模板，字面完全一致；
     * 这是多篇文章结构趋同、被判定为蚕食/近似重复内容的直接原因（2026-09-21 结构多样性修复）。
     * 这里不改变 heading 本身（它仍是给模型的"这节写什么"的角色标签），而是按
     * hash(topic + 该节 key) 确定性地选一种改写方向塞进 `heading_style_hint`，随
     * 整份 blueprint 一起被 PromptCompiler::render() dump 进 CONTENT BLUEPRINT 段落。
     * `litb.seo.structure` 模块内容明确要求模型不得照抄 heading 字面文本，须按
     * hint 指定的方向改写成具体到本文主题的小标题——同一篇文章每次生成结果一致
     * （幂等），不同主题的文章大概率落在不同改写方向上，避免模型在多次独立生成里
     * 收敛回同一种"默认措辞"。
     *
     * @var list<string>
     */
    private const HEADING_STYLE_HINTS = [
        'Phrase this heading as a direct question the reader is asking, grounded in the article topic.',
        'Phrase this heading as an imperative/action phrase (e.g. "Check ... before ...", "How to ...").',
        'Phrase this heading as a concise noun phrase naming the concrete topic, not a generic category label.',
        'Phrase this heading around the specific product, brand, or keyword this article is about, not a generic term.',
    ];

    /**
     * 注意：`show_references_section` 刻意**不**复制进 per-brief blueprint。
     * `PromptCompiler::render()` 会把这份 blueprint 整体 dump 成提示词里的
     * CONTENT BLUEPRINT 段落，模型因此会看到一个毫无上下文的
     * `"show_references_section": true` 键；配合 EVIDENCE 段落里的 source_url，
     * 模型时常会自行补写一个 `## References` 标题，多出的顶层小节会直接触发
     * 质量门禁按位置比对的 `section_count_mismatch`。这个开关纯属编辑侧配置，
     * 消费方（采纳时渲染引用列表）应当直接读 Preset 版本的 `blueprint_template`。
     *
     * @param  array<string, mixed>  $template
     * @param  list<array<string, mixed>>  $resolvedSections  pre-resolved by BlueprintSectionPlanner —
     *   either template['sections'] unchanged, or a per-topic section_pool selection.
     * @return array<string, mixed>
     */
    private function instantiateBlueprint(array $template, string $topic, string $coreQuestion, array $resolvedSections): array
    {
        $sections = [];
        foreach ($resolvedSections as $section) {
            $section['questions_to_answer'] = array_map(
                fn (string $question): string => str_replace('{{core_question}}', $coreQuestion, $question),
                (array) ($section['questions_to_answer'] ?? []),
            );
            $sectionKey = (string) ($section['key'] ?? $section['heading'] ?? '');
            $hintIndex = crc32($topic.'|'.$sectionKey) % count(self::HEADING_STYLE_HINTS);
            $section['heading_style_hint'] = self::HEADING_STYLE_HINTS[$hintIndex];
            $sections[] = $section;
        }

        return [
            'working_title' => $topic,
            'target_length' => (int) ($template['target_length'] ?? 1800),
            'sections' => $sections,
            'required_entities' => (array) ($template['required_entities'] ?? []),
            'faq_questions' => [$coreQuestion],
            'seo_title' => mb_substr($topic.' | LightInTheBox', 0, 60),
            'meta_description' => mb_substr($coreQuestion, 0, 155),
            'schema_types' => ['Article'],
        ];
    }
}
