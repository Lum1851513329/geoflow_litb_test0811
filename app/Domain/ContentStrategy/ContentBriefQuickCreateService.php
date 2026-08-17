<?php

namespace App\Domain\ContentStrategy;

use App\Models\Admin;
use App\Models\ContentBlueprintVersion;
use App\Models\ContentBrief;
use App\Models\ContentStrategyVersion;
use App\Models\PromptPreset;
use DomainException;
use Illuminate\Support\Facades\DB;

final class ContentBriefQuickCreateService
{
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
        if ($coreQuestion === '') {
            $coreQuestion = "What should a reader know before deciding on {$topic}?";
        }

        $defaults = (array) ($presetVersion->default_brief_fields ?? []);

        return DB::transaction(function () use ($data, $presetVersion, $actor, $topic, $coreQuestion, $defaults): ContentBrief {
            $brief = ContentBrief::forceCreate([
                'topic' => $topic,
                'market_code' => (string) ($defaults['market_code'] ?? 'GLOBAL'),
                'language_code' => (string) ($defaults['language_code'] ?? 'en'),
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
            ]);

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

            $blueprint = $this->instantiateBlueprint((array) $presetVersion->blueprint_template, $topic, $coreQuestion);
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

    /**
     * @param  array<string, mixed>  $template
     * @return array<string, mixed>
     */
    private function instantiateBlueprint(array $template, string $topic, string $coreQuestion): array
    {
        $sections = [];
        foreach ((array) ($template['sections'] ?? []) as $section) {
            $section['questions_to_answer'] = array_map(
                fn (string $question): string => str_replace('{{core_question}}', $coreQuestion, $question),
                (array) ($section['questions_to_answer'] ?? []),
            );
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
            'show_references_section' => (bool) ($template['show_references_section'] ?? false),
        ];
    }
}
