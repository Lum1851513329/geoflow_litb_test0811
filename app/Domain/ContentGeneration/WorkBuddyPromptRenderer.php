<?php

namespace App\Domain\ContentGeneration;

use App\Models\ContentGenerationRun;
use App\Services\GeoFlow\ArticleContentPromptRenderer;

/**
 * Builds the WorkBuddy-facing prompt by reusing the same mechanism the
 * legacy engine already uses for human-authored prompts
 * (ArticleContentPromptRenderer + a {{title}}/{{keyword}}/{{Knowledge}}
 * template stored as a Prompt record, editable in the existing Prompt admin
 * screen) — instead of a bespoke hand-built document. The quality-gated
 * pipeline's own compiled_prompt (numbered contract sections + concatenated
 * modules + raw JSON blueprint/strategy dumps) is deliberately optimized for
 * a model executing inside a locked-down, auditable pipeline; it was never
 * meant to be read by a person, and reads like one. WorkBuddy needs an
 * actual writing brief, so this renders through the same template system a
 * human already edits for the legacy engine — this class only turns the
 * blueprint's structured requirements + gathered evidence into the readable
 * {{Knowledge}} text that template's placeholder receives.
 *
 * Uses renderForEditor(), not renderForWorker(): the worker variant tells
 * the model to cite [K1]-style evidence IDs so EvidenceCitationRenderer can
 * auto-render a Sources footer inside the locked-down pipeline — but this
 * class's evidence block never assigns those IDs, so that instruction would
 * ask WorkBuddy to do something that isn't there. The editor variant asks
 * for natural attribution instead ("the materials show..."), which actually
 * matches what's in the evidence block below.
 */
final class WorkBuddyPromptRenderer
{
    public function __construct(
        private readonly ArticleContentPromptRenderer $promptRenderer,
    ) {}

    public function render(ContentGenerationRun $run): string
    {
        $run->loadMissing(['contentBrief.category', 'contentBrief.primaryKeyword', 'contentBrief.task.prompt', 'strategyVersion', 'blueprintVersion', 'evidence', 'evidencePreparation']);
        $brief = $run->contentBrief;
        $blueprint = (array) ($run->blueprintVersion?->blueprint ?? []);
        // The structure/evidence scaffolding below is written in whichever
        // language the article itself is in, not the admin's own language —
        // mixing languages inside one prompt is exactly the kind of
        // internal-inconsistency problem this class exists to avoid. Every
        // brief observed in production so far is English (GLOBAL market);
        // Chinese is kept as the only other supported fallback.
        $english = mb_strtolower((string) ($brief?->language_code ?? 'en')) !== 'zh';

        $title = (string) ($blueprint['working_title'] ?? $brief?->topic ?? '');
        $keyword = implode(', ', $this->keywords($brief, (array) ($run->strategyVersion?->strategy ?? []), $blueprint));
        $knowledgeContext = $this->knowledgeContext($run, $blueprint, $english);

        $promptContent = $brief?->task?->prompt?->content;

        return $this->promptRenderer->renderForEditor($title, $keyword, $promptContent, $knowledgeContext);
    }

    /** @return list<string> */
    private function keywords(mixed $brief, array $strategy, array $blueprint): array
    {
        $values = array_merge(
            [$brief?->primaryKeyword?->keyword],
            (array) ($strategy['keywords'] ?? []),
            (array) ($blueprint['keywords'] ?? []),
        );

        return array_values(array_unique(array_filter($values, static fn (mixed $v): bool => is_string($v) && trim($v) !== '')));
    }

    /** @param array<string,mixed> $blueprint */
    private function knowledgeContext(ContentGenerationRun $run, array $blueprint, bool $english): string
    {
        $blocks = [];

        $structure = $this->structureBlock($blueprint, $english);
        if ($structure !== '') {
            $header = $english
                ? 'Suggested article structure (cover in this order; write your own specific H2 for each section — do not copy the notes below as the heading):'
                : '文章结构建议（请按这个顺序覆盖，每节自己起具体小标题，不要照抄下面的说明文字当标题）：';
            $blocks[] = "{$header}\n{$structure}";
        }

        $evidence = $this->evidenceBlock($run);
        if ($evidence !== '') {
            $header = $english
                ? 'Available reference material (only use facts found here; where it is not enough, search for the missing information yourself and mention the source naturally in the text — never invent facts):'
                : '可用参考资料（只能用这些里面的事实；不够用的地方请你自己搜索补充，并在文中自然说明来源，不要编造）：';
            $blocks[] = "{$header}\n{$evidence}";
        }

        return implode("\n\n", $blocks);
    }

    /** @param array<string,mixed> $blueprint */
    private function structureBlock(array $blueprint, bool $english): string
    {
        $lines = [];
        $index = 0;
        foreach ((array) ($blueprint['sections'] ?? []) as $section) {
            if (!is_array($section)) {
                continue;
            }
            $index++;
            $line = "{$index}. ".($section['purpose'] ?? '');
            $questions = (array) ($section['questions_to_answer'] ?? []);
            if ($questions !== []) {
                $line .= $english
                    ? ' (must answer: '.implode('; ', $questions).')'
                    : '（需要回答：'.implode('；', $questions).'）';
            }
            if (($section['minimum_words'] ?? 0) > 0) {
                $line .= $english
                    ? ' [at least '.$section['minimum_words'].' words]'
                    : ' [不少于 '.$section['minimum_words'].' 词]';
            }
            $lines[] = $line;
        }
        $faqQuestions = (array) ($blueprint['faq_questions'] ?? []);
        if ($faqQuestions !== []) {
            $lines[] = ($english ? 'FAQ section should cover: ' : 'FAQ 部分建议覆盖：').implode($english ? '; ' : '；', $faqQuestions);
        }
        if (($blueprint['target_length'] ?? 0) > 0) {
            $lines[] = ($english ? 'Target total length: about ' : '全文目标长度：约 ').$blueprint['target_length'].($english ? ' words' : ' 词');
        }

        return implode("\n", $lines);
    }

    private function evidenceBlock(ContentGenerationRun $run): string
    {
        $items = [];
        foreach ($run->evidence as $evidence) {
            $title = (string) data_get($evidence->evidence_snapshot, 'title', $evidence->evidence_key);
            $content = (string) data_get($evidence->evidence_snapshot, 'content', '');
            if ($content === '') {
                continue;
            }
            $items[] = "- [{$title}] {$content}";
        }
        $preparationEvidence = (array) ($run->evidencePreparation?->result_json['evidence'] ?? []);
        foreach ($preparationEvidence as $item) {
            $title = (string) data_get($item, 'title', data_get($item, 'fact_id', ''));
            $content = (string) data_get($item, 'content', '');
            if ($content === '') {
                continue;
            }
            $items[] = "- [{$title}] {$content}";
        }

        return implode("\n", array_slice($items, 0, 30));
    }
}
