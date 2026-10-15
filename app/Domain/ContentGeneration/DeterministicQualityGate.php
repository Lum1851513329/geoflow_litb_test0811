<?php

namespace App\Domain\ContentGeneration;

use App\Domain\ContentStrategy\BlueprintValidator;
use DOMDocument;
use DOMElement;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Inline\AbstractWebResource;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use League\CommonMark\Node\Node;
use League\CommonMark\Node\StringContainerInterface;

final class DeterministicQualityGate
{
    private const KEYWORD_DENSITY_LIMIT = 0.03;

    public function __construct(private readonly BlueprintValidator $blueprintValidator = new BlueprintValidator) {}

    /**
     * @param  array<string, mixed>  $blueprint
     * @param  array<string, mixed>  $requirements
     */
    public function inspect(string $markdown, array $blueprint, array $requirements): QualityGateResult
    {
        $blueprintErrors = $this->blueprintValidator->validate($blueprint);
        if ($blueprintErrors !== []) {
            $issues = [];
            foreach ($blueprintErrors as $field => $messages) {
                foreach ($messages as $message) {
                    $issues[] = new QualityIssue(
                        code: 'invalid_blueprint',
                        severity: QualityIssue::SEVERITY_ERROR,
                        sectionKey: $field,
                        message: "Blueprint is invalid at \"{$field}\": {$message}",
                        repairInstruction: "Correct blueprint field \"{$field}\" before generation.",
                    );
                }
            }

            return $this->result($issues);
        }

        $sections = $this->blueprintSections($blueprint);
        $language = $this->languageCode($blueprint, $requirements);
        $visibleMarkdown = $this->renderedMarkdown($markdown);
        $document = $this->parseMarkdown($visibleMarkdown, $sections);
        $issues = [];

        foreach ($this->unresolvedVariables($visibleMarkdown) as $variable) {
            $issues[] = new QualityIssue(
                code: 'unresolved_variable',
                severity: QualityIssue::SEVERITY_ERROR,
                sectionKey: null,
                message: "Unresolved template variable \"{$variable}\" was found.",
                repairInstruction: "Replace \"{$variable}\" with its resolved value.",
            );
        }

        if ($document['headings'] === [] || $document['headings'][0]['level'] !== 1) {
            $issues[] = new QualityIssue(
                code: 'missing_title_heading',
                severity: QualityIssue::SEVERITY_ERROR,
                sectionKey: null,
                message: 'The article does not open with a single H1 title heading.',
                repairInstruction: 'Add exactly one H1 heading as the very first line, containing the article title.',
            );
        }

        $expectedSectionCount = count($sections);
        $foundSectionCount = $document['section_count_found'];
        $sectionCountsMatch = $foundSectionCount === $expectedSectionCount;
        if (! $sectionCountsMatch) {
            $issues[] = new QualityIssue(
                code: 'section_count_mismatch',
                // 格式类问题降级为 warning：这些文章面向 GEO 可见度产出，判定标准是
                // 事实正确，而不是与蓝图逐节对齐。warning 仍会计入评分（-5）并出现在
                // 质量报告里，只是不再阻断发布（result() 只按 SEVERITY_ERROR 判定 passed）。
                severity: QualityIssue::SEVERITY_WARNING,
                sectionKey: null,
                message: "Article has {$foundSectionCount} top-level sections; blueprint requires {$expectedSectionCount}.",
                repairInstruction: "Add or remove top-level sections so the article has exactly {$expectedSectionCount} sections, matching the blueprint order.",
            );
        } else {
            foreach ($sections as $section) {
                $sectionKey = $section['key'];
                $actualUnits = $this->contentUnitCount($document['section_content'][$sectionKey] ?? '', $language);
                if ($actualUnits < $section['minimum_words']) {
                    $issues[] = new QualityIssue(
                        code: 'section_too_thin',
                        // 见 section_count_mismatch 的说明：格式类问题不阻断。
                        severity: QualityIssue::SEVERITY_WARNING,
                        sectionKey: $sectionKey,
                        message: "Section \"{$section['heading']}\" has {$actualUnits} content units; it requires at least {$section['minimum_words']}.",
                        repairInstruction: "Expand \"{$section['heading']}\" with ".($section['minimum_words'] - $actualUnits).' more substantive content units that address its planned question.',
                    );
                }
            }
        }

        foreach ($this->duplicateParagraphs($document['paragraphs']) as $duplicate) {
            $issues[] = new QualityIssue(
                code: 'duplicate_paragraph',
                severity: QualityIssue::SEVERITY_ERROR,
                sectionKey: $duplicate['section_key'],
                message: 'A normalized paragraph repeats an earlier paragraph.',
                repairInstruction: 'Replace the repeated paragraph with distinct guidance for this section.',
            );
        }

        $articleWordCount = $this->contentUnitCount($document['body'], $language);
        foreach ($this->keywords($blueprint, $requirements) as $keyword) {
            $occurrences = $this->phraseOccurrences($document['body'], $keyword);
            $keywordWords = $this->contentUnitCount($keyword, $language);
            $density = $articleWordCount === 0 ? 0.0 : ($occurrences * $keywordWords) / $articleWordCount;
            if ($density > self::KEYWORD_DENSITY_LIMIT) {
                $issues[] = new QualityIssue(
                    code: 'keyword_stuffing',
                    severity: QualityIssue::SEVERITY_ERROR,
                    sectionKey: null,
                    message: sprintf('Keyword "%s" has %.1f%% token density, exceeding the 3.0%% limit.', $keyword, $density * 100),
                    repairInstruction: sprintf('Reduce "%s" to natural uses below 3.0%% of article words.', $keyword),
                );
            }
        }

        foreach ($this->requiredEntities($blueprint, $requirements) as $entity) {
            if ($this->phraseOccurrences($document['body'], $entity) === 0) {
                $issues[] = new QualityIssue(
                    code: 'missing_required_entity',
                    severity: QualityIssue::SEVERITY_ERROR,
                    sectionKey: 'required_entities',
                    message: "Required entity \"{$entity}\" is not mentioned.",
                    repairInstruction: "Mention the required entity \"{$entity}\" where its role is relevant.",
                );
            }
        }

        foreach ($this->questions($sections, $blueprint, $requirements) as $question) {
            if ($question['section_key'] !== null && ! $sectionCountsMatch) {
                continue;
            }
            $content = $question['section_key'] === null
                ? $document['body']
                : ($document['section_content'][$question['section_key']] ?? '');
            if (! $this->questionIsCovered($question['text'], $content)) {
                $issues[] = new QualityIssue(
                    code: 'missing_question_coverage',
                    // 蓝图校验要求每个实质性章节至少有一个问题（questions_required），
                    // 所以不能靠清空 questions_to_answer 来放宽——那会让蓝图直接非法。
                    // 种子命令给非首章节填的是通用占位问题，模型不会逐字命中，
                    // 因此这里降级为 warning：问题覆盖仍然计分并出现在报告里，
                    // 但不再阻断以 GEO 曝光为目标的产出。
                    severity: QualityIssue::SEVERITY_WARNING,
                    sectionKey: $question['section_key'],
                    message: "The planned question \"{$question['text']}\" is not covered.",
                    repairInstruction: "Answer the planned question: \"{$question['text']}\".",
                );
            }
        }

        foreach ($this->headingHierarchyIssues($document['headings']) as $headingIssue) {
            $issues[] = new QualityIssue(
                code: 'heading_hierarchy',
                severity: QualityIssue::SEVERITY_ERROR,
                sectionKey: $headingIssue['section_key'],
                message: "Heading level jumps from H{$headingIssue['previous_level']} to H{$headingIssue['level']} at \"{$headingIssue['text']}\".",
                repairInstruction: "Use an H{$headingIssue['expected_level']} heading after H{$headingIssue['previous_level']} \"{$headingIssue['previous_text']}\"; do not jump directly to H{$headingIssue['level']}.",
            );
        }

        if ($language !== null && $this->hasLanguageMismatch($document['body'], $language)) {
            $issues[] = new QualityIssue(
                code: 'language_mismatch',
                severity: QualityIssue::SEVERITY_ERROR,
                sectionKey: null,
                message: "Article script does not match the requested language \"{$language}\".",
                repairInstruction: "Provide the article body in the requested {$language} language.",
            );
        }

        $targetLength = (int) ($requirements['target_length'] ?? $blueprint['target_length'] ?? 0);
        if ($targetLength > 0) {
            $tolerance = max(0.0, min(0.9, (float) ($requirements['target_length_tolerance'] ?? 0.40)));
            $minimum = (int) floor($targetLength * (1 - $tolerance));
            $maximum = (int) ceil($targetLength * (1 + $tolerance));
            if ($articleWordCount < $minimum || $articleWordCount > $maximum) {
                $issues[] = new QualityIssue(
                    code: 'target_length_out_of_range',
                    // 见 section_count_mismatch 的说明：格式类问题不阻断。
                    severity: QualityIssue::SEVERITY_WARNING,
                    sectionKey: null,
                    message: "Article length is {$articleWordCount} content units; the allowed range is {$minimum}-{$maximum}.",
                    repairInstruction: "Adjust the article to {$minimum}-{$maximum} substantive content units.",
                );
            }
        }

        foreach ($this->stringList($requirements['prohibited_information'] ?? []) as $prohibited) {
            if ($this->phraseOccurrences($document['body'], $prohibited) > 0) {
                $issues[] = new QualityIssue(
                    code: 'prohibited_information',
                    severity: QualityIssue::SEVERITY_ERROR,
                    sectionKey: null,
                    message: "Prohibited information \"{$prohibited}\" appears in the article.",
                    repairInstruction: "Remove the prohibited information \"{$prohibited}\".",
                );
            }
        }

        if (($requirements['external_links_allowed'] ?? true) === false
            && $this->containsExternalDestination($visibleMarkdown)) {
            $issues[] = new QualityIssue(
                code: 'external_link_not_allowed',
                severity: QualityIssue::SEVERITY_ERROR,
                sectionKey: null,
                message: 'The article contains an external link although external links are disabled.',
                repairInstruction: 'Remove all external links while preserving useful non-linked prose.',
            );
        }

        if ($sectionCountsMatch) {
            foreach ($sections as $section) {
                if (! $section['cta_allowed'] && $this->containsCallToAction($document['section_markdown'][$section['key']] ?? '')) {
                    $issues[] = new QualityIssue(
                        code: 'cta_not_allowed',
                        severity: QualityIssue::SEVERITY_ERROR,
                        sectionKey: $section['key'],
                        message: "Section \"{$section['heading']}\" contains a call to action that its blueprint forbids.",
                        repairInstruction: "Remove promotional calls to action from \"{$section['heading']}\".",
                    );
                }
            }
        }

        $availableFactIds = array_flip($this->stringList($requirements['evidence_fact_ids'] ?? []));
        foreach ($sections as $section) {
            foreach ($section['required_fact_ids'] as $factId) {
                if (! isset($availableFactIds[$factId])) {
                    $issues[] = new QualityIssue(
                        code: 'required_fact_uncovered',
                        severity: QualityIssue::SEVERITY_ERROR,
                        sectionKey: $section['key'],
                        message: "Required fact \"{$factId}\" has no reviewed evidence snapshot.",
                        repairInstruction: "Attach reviewed evidence for fact \"{$factId}\" before generation.",
                    );
                }
            }
        }

        if ($this->hasMarketMismatch($document['body'], (string) ($requirements['market_code'] ?? ''))) {
            $issues[] = new QualityIssue(
                code: 'market_mismatch',
                severity: QualityIssue::SEVERITY_ERROR,
                sectionKey: null,
                message: 'Article currency conventions conflict with the requested market.',
                repairInstruction: 'Use currency and market conventions consistent with the requested market.',
            );
        }

        return $this->result($issues);
    }

    /**
     * @param  array<string, mixed>  $blueprint
     * @return list<array{key: string, heading: string, minimum_words: int, questions: list<string>}>
     */
    private function blueprintSections(array $blueprint): array
    {
        $sections = [];
        foreach ((array) ($blueprint['sections'] ?? []) as $section) {
            if (! is_array($section)) {
                continue;
            }

            $key = trim((string) ($section['key'] ?? ''));
            $heading = trim((string) ($section['heading'] ?? ''));
            if ($key === '' || $heading === '') {
                continue;
            }

            $sections[] = [
                'key' => $key,
                'heading' => $heading,
                'minimum_words' => max(0, (int) ($section['minimum_words'] ?? 0)),
                'questions' => $this->stringList($section['questions_to_answer'] ?? []),
                'required_fact_ids' => $this->stringList($section['required_fact_ids'] ?? []),
                'cta_allowed' => (bool) ($section['cta_allowed'] ?? false),
            ];
        }

        return $sections;
    }

    /**
     * @param  list<array{key: string, heading: string, minimum_words: int, questions: list<string>}>  $sections
     * @return array{headings: list<array{level: int, text: string, section_key: string|null}>, paragraphs: list<array{text: string, section_key: string|null}>, section_content: array<string, string>, section_markdown: array<string, string>, body: string, section_count_found: int}
     */
    private function parseMarkdown(string $markdown, array $sections): array
    {
        $lines = preg_split('/\R/u', $markdown) ?: [];

        $rawHeadings = [];
        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*(#{1,6})\s+(.+?)\s*#*\s*$/u', $line, $matches) === 1) {
                $rawHeadings[] = ['line' => $index, 'level' => strlen($matches[1]), 'text' => trim($matches[2])];
            }
        }

        // The model is instructed to open with a single H1 title (the title-crafting
        // module); that H1 is the article title, never a section boundary.
        $sectionCandidates = $rawHeadings;
        if ($sectionCandidates !== [] && $sectionCandidates[0]['level'] === 1) {
            array_shift($sectionCandidates);
        }

        $topLevel = null;
        foreach ($sectionCandidates as $heading) {
            if ($topLevel === null || $heading['level'] < $topLevel) {
                $topLevel = $heading['level'];
            }
        }

        $sectionHeadingLines = [];
        foreach ($sectionCandidates as $heading) {
            if ($heading['level'] === $topLevel) {
                $sectionHeadingLines[] = $heading['line'];
            }
        }

        // Zip section-level headings 1:1, in document order, against the blueprint's
        // sections, in blueprint order. A count mismatch is handled by the caller.
        $lineToSectionKey = [];
        $matchedCount = min(count($sectionHeadingLines), count($sections));
        for ($i = 0; $i < $matchedCount; $i++) {
            $lineToSectionKey[$sectionHeadingLines[$i]] = $sections[$i]['key'];
        }

        $headings = [];
        $paragraphs = [];
        $sectionContent = [];
        $sectionMarkdown = [];
        $bodyLines = [];
        $paragraphLines = [];
        $currentSectionKey = null;
        $currentSectionLevel = null;
        $flushParagraph = function () use (&$paragraphLines, &$paragraphs, &$currentSectionKey): void {
            $text = trim(implode(' ', $paragraphLines));
            $paragraphLines = [];
            if ($text !== '') {
                $paragraphs[] = ['text' => $text, 'section_key' => $currentSectionKey];
            }
        };

        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*(#{1,6})\s+(.+?)\s*#*\s*$/u', $line, $matches) === 1) {
                $flushParagraph();
                $level = strlen($matches[1]);
                $text = trim($matches[2]);
                $sectionKey = $lineToSectionKey[$index] ?? null;
                $headings[] = ['level' => $level, 'text' => $text, 'section_key' => $sectionKey];

                if ($level === $topLevel) {
                    $currentSectionKey = $sectionKey;
                    $currentSectionLevel = $level;
                    if ($sectionKey !== null) {
                        $sectionContent[$sectionKey] ??= '';
                        $sectionMarkdown[$sectionKey] ??= '';
                    }
                } elseif ($currentSectionLevel !== null && $level <= $currentSectionLevel) {
                    $currentSectionKey = null;
                    $currentSectionLevel = null;
                }

                continue;
            }

            if (trim($line) === '') {
                $flushParagraph();

                continue;
            }

            $cleanLine = $this->stripMarkdown($line);
            if ($currentSectionKey !== null) {
                $sectionMarkdown[$currentSectionKey] = trim(($sectionMarkdown[$currentSectionKey] ?? '')."\n".$line);
            }
            if ($cleanLine === '') {
                continue;
            }

            $bodyLines[] = $cleanLine;
            $paragraphLines[] = $cleanLine;
            if ($currentSectionKey !== null) {
                $sectionContent[$currentSectionKey] = trim(($sectionContent[$currentSectionKey] ?? '').' '.$cleanLine);
            }
        }

        $flushParagraph();

        return [
            'headings' => $headings,
            'paragraphs' => $paragraphs,
            'section_content' => $sectionContent,
            'section_markdown' => $sectionMarkdown,
            'body' => trim(implode(' ', $bodyLines)),
            'section_count_found' => count($sectionHeadingLines),
        ];
    }

    private function renderedMarkdown(string $markdown): string
    {
        $visibleLines = [];
        $fence = null;
        $inHtmlComment = false;

        foreach (preg_split('/\R/u', $markdown) ?: [] as $line) {
            if ($fence !== null) {
                $closingPattern = '/^\s*'.preg_quote($fence['character'], '/').'{'.$fence['length'].',}\s*$/u';
                if (preg_match($closingPattern, $line) === 1) {
                    $fence = null;
                    $visibleLines[] = '';
                }

                continue;
            }

            if (! $inHtmlComment && preg_match('/^\s*(`{3,}|~{3,})[^\r\n]*$/u', $line, $matches) === 1) {
                $fence = [
                    'character' => $matches[1][0],
                    'length' => strlen($matches[1]),
                ];
                $visibleLines[] = '';

                continue;
            }

            $line = $this->withoutHtmlComments($line, $inHtmlComment);
            if ($line === '') {
                $visibleLines[] = '';

                continue;
            }

            if (preg_match('/^\s*(`{3,}|~{3,})[^\r\n]*$/u', $line, $matches) === 1) {
                $fence = [
                    'character' => $matches[1][0],
                    'length' => strlen($matches[1]),
                ];
                $visibleLines[] = '';

                continue;
            }

            $visibleLines[] = $line;
        }

        return implode("\n", $visibleLines);
    }

    private function withoutHtmlComments(string $line, bool &$inHtmlComment): string
    {
        while (true) {
            if ($inHtmlComment) {
                $closing = strpos($line, '-->');
                if ($closing === false) {
                    return '';
                }

                $line = substr($line, $closing + 3);
                $inHtmlComment = false;
            }

            $opening = strpos($line, '<!--');
            if ($opening === false) {
                return $line;
            }

            $closing = strpos($line, '-->', $opening + 4);
            if ($closing === false) {
                $inHtmlComment = true;

                return substr($line, 0, $opening);
            }

            $line = substr($line, 0, $opening).substr($line, $closing + 3);
        }
    }

    /** @return list<string> */
    private function unresolvedVariables(string $markdown): array
    {
        preg_match_all('/\{\{\s*[^{}]+?\s*\}\}/u', $markdown, $matches);

        return array_values(array_unique($matches[0] ?? []));
    }

    /** @param list<array{text: string, section_key: string|null}> $paragraphs
     * @return list<array{section_key: string|null}>
     */
    private function duplicateParagraphs(array $paragraphs): array
    {
        $seen = [];
        $duplicates = [];
        foreach ($paragraphs as $paragraph) {
            $normalized = $this->normalize($paragraph['text']);
            if ($this->wordCount($normalized) < 5) {
                continue;
            }

            if (isset($seen[$normalized])) {
                $duplicates[] = ['section_key' => $paragraph['section_key']];
            } else {
                $seen[$normalized] = true;
            }
        }

        return $duplicates;
    }

    /** @param array<string, mixed> $blueprint
     * @param  array<string, mixed>  $requirements
     * @return list<string>
     */
    private function keywords(array $blueprint, array $requirements): array
    {
        $values = [
            $requirements['primary_keyword'] ?? null,
            $requirements['core_keyword'] ?? null,
            $requirements['keyword'] ?? null,
            $blueprint['primary_keyword'] ?? null,
            $blueprint['core_keyword'] ?? null,
            $blueprint['keyword'] ?? null,
        ];

        foreach ([$requirements['keywords'] ?? [], $blueprint['keywords'] ?? []] as $keywords) {
            $values = [...$values, ...$this->stringList($keywords)];
        }

        return $this->uniqueStrings($values);
    }

    /** @param array<string, mixed> $blueprint
     * @param  array<string, mixed>  $requirements
     * @return list<string>
     */
    private function requiredEntities(array $blueprint, array $requirements): array
    {
        return $this->uniqueStrings([
            ...$this->stringList($blueprint['required_entities'] ?? []),
            ...$this->stringList($requirements['required_entities'] ?? []),
            ...$this->stringList($requirements['entities'] ?? []),
            ...$this->stringList($requirements['required_mentions'] ?? []),
        ]);
    }

    /**
     * @param  list<array{key: string, heading: string, minimum_words: int, questions: list<string>}>  $sections
     * @param  array<string, mixed>  $blueprint
     * @param  array<string, mixed>  $requirements
     * @return list<array{section_key: string|null, text: string}>
     */
    private function questions(array $sections, array $blueprint, array $requirements): array
    {
        $questions = [];
        foreach ($sections as $section) {
            foreach ($section['questions'] as $question) {
                $questions[] = ['section_key' => $section['key'], 'text' => $question];
            }
        }

        foreach ([$blueprint['faq_questions'] ?? [], $requirements['required_questions'] ?? [], $requirements['questions_to_answer'] ?? [], $requirements['questions'] ?? []] as $list) {
            foreach ($this->stringList($list) as $question) {
                $questions[] = ['section_key' => null, 'text' => $question];
            }
        }

        return $questions;
    }

    /** @param list<array{level: int, text: string, section_key: string|null}> $headings
     * @return list<array{level: int, text: string, section_key: string|null, previous_level: int, previous_text: string, expected_level: int}>
     */
    private function headingHierarchyIssues(array $headings): array
    {
        $issues = [];
        $previous = null;
        foreach ($headings as $heading) {
            if ($previous !== null && $heading['level'] > $previous['level'] + 1) {
                $issues[] = [
                    'level' => $heading['level'],
                    'text' => $heading['text'],
                    'section_key' => $heading['section_key'],
                    'previous_level' => $previous['level'],
                    'previous_text' => $previous['text'],
                    'expected_level' => $previous['level'] + 1,
                ];
            }
            $previous = $heading;
        }

        return $issues;
    }

    private function questionIsCovered(string $question, string $content): bool
    {
        $questionTerms = array_values(array_filter(
            $this->wordsIn($question),
            static fn (string $term): bool => ! in_array($term, [
                'a', 'an', 'and', 'are', 'do', 'does', 'for', 'how', 'is', 'of', 'should', 'the', 'to', 'what', 'when', 'which', 'who', 'why', 'with', 'you', 'your',
            ], true),
        ));

        if ($questionTerms === []) {
            return $this->normalize($question) !== '' && str_contains($this->normalize($content), $this->normalize($question));
        }

        $contentTerms = array_flip($this->wordsIn($content));
        $matches = count(array_filter($questionTerms, static fn (string $term): bool => isset($contentTerms[$term])));

        return $matches >= (int) ceil(count($questionTerms) / 2);
    }

    private function hasLanguageMismatch(string $body, string $language): bool
    {
        preg_match_all('/\p{Han}/u', $body, $han);
        preg_match_all('/[A-Za-z]+/', $body, $latin);
        $hanCount = count($han[0] ?? []);
        $latinWordCount = count($latin[0] ?? []);
        $language = strtolower(str_replace('_', '-', $language));

        if (str_starts_with($language, 'en')) {
            return $hanCount >= 10 && $latinWordCount < 3;
        }

        if (str_starts_with($language, 'zh')) {
            return $latinWordCount >= 8 && $hanCount === 0;
        }

        return false;
    }

    private function containsCallToAction(string $content): bool
    {
        $document = $this->markdownDocument($content);
        $walker = $document->walker();
        while (($event = $walker->next()) !== null) {
            if (! $event->isEntering()) {
                continue;
            }
            $node = $event->getNode();
            if ($node instanceof AbstractWebResource && $this->isActionLabel($this->nodeText($node))) {
                return true;
            }
            if (($node instanceof HtmlInline || $node instanceof HtmlBlock)
                && $this->htmlContainsCtaMarker($node->getLiteral())) {
                return true;
            }
        }

        $prose = $this->stripMarkdown($content);

        return preg_match(
            '/(?:^|[.!?。！？]\s*)'
                .'(?:(?=[^.!?。！？]{0,160}\b(?:now|today|tonight|before|while|stock|inventory|supplies|availability|limited(?:[- ]time)?|lasts?|ends?|runs?\s+out)\b)'
                .'(?:buy|purchase|order|shop|secure|reserve)\s+(?:now|today|yours?\b|(?:your|this|the|our|a|an)\b)'
                .'|subscribe\s+(?:now|today|to|for)\b|sign\s+up\s+(?:now|today|to|for)\b'
                .'|get\s+started|learn\s+more|click\s+here|start\s+(?:your\s+)?free\s+trial|request\s+a\s+demo|contact\s+sales|download\s+now|book\s+(?:a\s+)?(?:call|consultation)|add\s+to\s+cart|join\s+now)'
                .'(?:\b|$)|(?:^|[。！？]\s*)(?:立即购买|马上购买|点击购买|立即订阅|立即注册|免费试用|预约咨询)/iu',
            $prose,
        ) === 1;
    }

    private function containsExternalDestination(string $markdown): bool
    {
        $walker = $this->markdownDocument($markdown)->walker();
        while (($event = $walker->next()) !== null) {
            if (! $event->isEntering()) {
                continue;
            }
            $node = $event->getNode();
            if ($node instanceof AbstractWebResource && $this->isExternalDestination($node->getUrl())) {
                return true;
            }
            if ($node instanceof HtmlInline || $node instanceof HtmlBlock) {
                foreach ($this->htmlDestinations($node->getLiteral()) as $destination) {
                    if ($this->isExternalDestination($destination)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function markdownDocument(string $markdown): Node
    {
        $converter = new GithubFlavoredMarkdownConverter([
            'allow_unsafe_links' => true,
            'html_input' => 'allow',
        ]);

        return $converter->convert($markdown)->getDocument();
    }

    private function isExternalDestination(string $destination): bool
    {
        $destination = trim(html_entity_decode($destination, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $destination = preg_replace('/[\x00-\x20\x7F]+/u', '', $destination) ?? $destination;
        if ($destination === '' || str_starts_with($destination, '#')
            || str_starts_with($destination, '/') && ! str_starts_with($destination, '//')
            || str_starts_with($destination, './') || str_starts_with($destination, '../')
            || str_starts_with($destination, '?')) {
            return false;
        }

        return str_starts_with($destination, '//')
            || preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/u', $destination) === 1;
    }

    /** @return list<string> */
    private function htmlDestinations(string $html): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<div id="geoflow-fragment">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return [];
        }

        $destinations = [];
        foreach ($document->getElementsByTagName('*') as $element) {
            foreach (['href', 'src', 'action', 'formaction', 'poster', 'xlink:href'] as $attribute) {
                if ($element->hasAttribute($attribute)) {
                    $destinations[] = $element->getAttribute($attribute);
                }
            }
            if ($element->hasAttribute('srcset')) {
                foreach (explode(',', $element->getAttribute('srcset')) as $candidate) {
                    $parts = preg_split('/\s+/u', trim($candidate)) ?: [];
                    if (($parts[0] ?? '') !== '') {
                        $destinations[] = $parts[0];
                    }
                }
            }
        }

        return $destinations;
    }

    private function htmlContainsCtaMarker(string $html): bool
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<div id="geoflow-fragment">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return false;
        }

        foreach ($document->getElementsByTagName('*') as $element) {
            if (! $element instanceof DOMElement || $element->getAttribute('id') === 'geoflow-fragment') {
                continue;
            }
            $classes = preg_split('/\s+/u', trim($element->getAttribute('class'))) ?: [];
            if (strtolower($element->tagName) === 'button'
                || $element->hasAttribute('data-cta')
                || strtolower($element->getAttribute('role')) === 'button'
                || array_filter($classes, static fn (string $class): bool => preg_match('/(?:^|[-_])cta(?:$|[-_])|call[-_]?to[-_]?action/i', $class) === 1)
                || $this->isActionLabel(trim($element->textContent))) {
                return true;
            }
        }

        return false;
    }

    private function nodeText(Node $node): string
    {
        $text = '';
        $walker = $node->walker();
        while (($event = $walker->next()) !== null) {
            $child = $event->getNode();
            if ($event->isEntering() && $child instanceof StringContainerInterface) {
                $text .= ' '.$child->getLiteral();
            }
        }

        return trim($text);
    }

    private function isActionLabel(string $label): bool
    {
        return preg_match(
            '/^\s*(?:buy|purchase|order|shop|secure|reserve)\s*[.!]?\s*$'
                .'|(?:^|\b)(?:(?:buy|purchase|order|shop|secure|reserve)\s+(?:now|today|yours?|(?:your|this|the|our|a|an)\s+(?:product|pair|item|collection|plan|place|copy|spot|access))'
                .'|subscribe(?:\s+(?:now|today|to|for)(?:\s+\S+){0,5})?|sign\s+up(?:\s+(?:now|today|to|for)(?:\s+\S+){0,5})?'
                .'|get\s+started|learn\s+more|click\s+here|start\s+(?:your\s+)?free\s+trial|request\s+a\s+demo|contact\s+sales|download\s+now|book\s+(?:a\s+)?(?:call|consultation)|add\s+to\s+cart|join\s+now)(?:\b|$)'
                .'|(?:立即购买|马上购买|点击购买|立即订阅|立即注册|免费试用|预约咨询)/iu',
            $label,
        ) === 1;
    }

    private function hasMarketMismatch(string $body, string $market): bool
    {
        $market = strtoupper(trim($market));
        $forbidden = match ($market) {
            'US' => '/(?:£|\bGBP\b|¥|\bCNY\b)/u',
            'GB', 'UK' => '/(?:\$|\bUSD\b|¥|\bCNY\b)/u',
            'CN' => '/(?:\$|\bUSD\b|£|\bGBP\b)/u',
            default => null,
        };

        return $forbidden !== null && preg_match($forbidden, $body) === 1;
    }

    /** @param array<string, mixed> $blueprint
     * @param  array<string, mixed>  $requirements
     */
    private function languageCode(array $blueprint, array $requirements): ?string
    {
        foreach ([$requirements['language_code'] ?? null, $requirements['language'] ?? null, $blueprint['language_code'] ?? null, $blueprint['language'] ?? null] as $language) {
            if (is_string($language) && trim($language) !== '') {
                return trim($language);
            }
        }

        return null;
    }

    private function phraseOccurrences(string $text, string $phrase): int
    {
        $phrase = trim($phrase);
        if ($phrase === '') {
            return 0;
        }

        $normalizedText = $this->normalize($text);
        $normalizedPhrase = $this->normalize($phrase);
        if ($normalizedPhrase === '') {
            return 0;
        }

        return preg_match_all('/(?<![\p{L}\p{N}])'.preg_quote($normalizedPhrase, '/').'(?![\p{L}\p{N}])/u', $normalizedText, $matches) ?: 0;
    }

    private function wordCount(string $text): int
    {
        $text = $this->withoutFormatCharacters($text);
        preg_match_all('/[\p{L}\p{N}]+/u', $text, $matches);

        return count($matches[0] ?? []);
    }

    /** @return list<string> */
    private function wordsIn(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $this->normalize($text), $matches);

        return $matches[0] ?? [];
    }

    private function normalize(string $value): string
    {
        $value = $this->stripMarkdown($value);
        $value = mb_strtolower($value);
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    private function stripMarkdown(string $value): string
    {
        $value = $this->withoutFormatCharacters($value);
        $value = strip_tags($value);
        $value = preg_replace('/!?(?:\[([^\]]*)\])\([^)]*\)/u', '$1', $value) ?? $value;
        $value = preg_replace('/[`*_~>#]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/^\s*(?:[-+*]|\d+\.)\s+/u', '', $value) ?? $value;

        return trim($value);
    }

    private function withoutFormatCharacters(string $value): string
    {
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($value, \Normalizer::FORM_C);
            if ($normalized !== false) {
                $value = $normalized;
            }
        }

        $normalized = @preg_replace('/\p{Default_Ignorable_Code_Point}+/u', '', $value);
        if ($normalized !== null) {
            return $normalized;
        }

        return preg_replace('/[\p{Cf}\x{034F}\x{180B}-\x{180D}\x{FE00}-\x{FE0F}\x{E0100}-\x{E01EF}]+/u', '', $value) ?? $value;
    }

    private function contentUnitCount(string $text, ?string $language): int
    {
        if ($language === null || ! preg_match('/^(zh|ja|ko)(?:-|$)/i', $language)) {
            return $this->wordCount($text);
        }

        $text = $this->withoutFormatCharacters($text);
        preg_match_all('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $text, $cjk);
        $nonCjkText = preg_replace('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]+/u', ' ', $text) ?? $text;

        return count($cjk[0] ?? []) + $this->wordCount($nonCjkText);
    }

    /** @param list<QualityIssue> $issues */
    private function result(array $issues): QualityGateResult
    {
        $errorCount = count(array_filter($issues, static fn (QualityIssue $issue): bool => $issue->severity === QualityIssue::SEVERITY_ERROR));
        $warningCount = count($issues) - $errorCount;

        return new QualityGateResult(
            passed: $errorCount === 0,
            score: max(0, 100 - ($errorCount * 15) - ($warningCount * 5)),
            issues: $issues,
        );
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $values): array
    {
        if (is_string($values)) {
            $values = [$values];
        }
        if (! is_array($values)) {
            return [];
        }

        return $this->uniqueStrings($values);
    }

    /** @param list<mixed> $values
     * @return list<string>
     */
    private function uniqueStrings(array $values): array
    {
        $unique = [];
        $seen = [];
        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }
            $value = trim($value);
            $normalized = $this->normalize($value);
            if ($normalized === '' || isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;
            $unique[] = $value;
        }

        return $unique;
    }
}
