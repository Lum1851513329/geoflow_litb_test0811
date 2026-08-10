<?php

namespace App\Domain\ContentGeneration;

use App\Domain\ContentStrategy\BlueprintValidator;

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

        foreach ($sections as $section) {
            $sectionKey = $section['key'];
            if (! array_key_exists($sectionKey, $document['section_content'])) {
                $issues[] = new QualityIssue(
                    code: 'missing_section',
                    severity: QualityIssue::SEVERITY_ERROR,
                    sectionKey: $sectionKey,
                    message: "Blueprint section \"{$section['heading']}\" is missing from the Markdown.",
                    repairInstruction: "Add an H2 heading named \"{$section['heading']}\" and include its planned guidance.",
                );

                continue;
            }

            $actualUnits = $this->contentUnitCount($document['section_content'][$sectionKey], $language);
            if ($actualUnits < $section['minimum_words']) {
                $issues[] = new QualityIssue(
                    code: 'section_too_thin',
                    severity: QualityIssue::SEVERITY_ERROR,
                    sectionKey: $sectionKey,
                    message: "Section \"{$section['heading']}\" has {$actualUnits} content units; it requires at least {$section['minimum_words']}.",
                    repairInstruction: "Expand \"{$section['heading']}\" with ".($section['minimum_words'] - $actualUnits).' more substantive content units that address its planned question.',
                );
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
            $content = $question['section_key'] === null
                ? $document['body']
                : ($document['section_content'][$question['section_key']] ?? '');
            if (! $this->questionIsCovered($question['text'], $content)) {
                $issues[] = new QualityIssue(
                    code: 'missing_question_coverage',
                    severity: QualityIssue::SEVERITY_ERROR,
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
            ];
        }

        return $sections;
    }

    /**
     * @param  list<array{key: string, heading: string, minimum_words: int, questions: list<string>}>  $sections
     * @return array{headings: list<array{level: int, text: string, section_key: string|null}>, paragraphs: list<array{text: string, section_key: string|null}>, section_content: array<string, string>, body: string}
     */
    private function parseMarkdown(string $markdown, array $sections): array
    {
        $headingKeys = [];
        foreach ($sections as $section) {
            $headingKeys[$this->normalize($section['heading'])] = $section['key'];
            $headingKeys[$this->normalize($section['key'])] = $section['key'];
        }

        $headings = [];
        $paragraphs = [];
        $sectionContent = [];
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

        foreach (preg_split('/\R/u', $markdown) ?: [] as $line) {
            if (preg_match('/^\s*(#{1,6})\s+(.+?)\s*#*\s*$/u', $line, $matches) === 1) {
                $flushParagraph();
                $level = strlen($matches[1]);
                $text = trim($matches[2]);
                $sectionKey = $headingKeys[$this->normalize($text)] ?? null;
                $headings[] = ['level' => $level, 'text' => $text, 'section_key' => $sectionKey];

                if ($sectionKey !== null) {
                    $currentSectionKey = $sectionKey;
                    $currentSectionLevel = $level;
                    $sectionContent[$sectionKey] ??= '';
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
            'body' => trim(implode(' ', $bodyLines)),
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
