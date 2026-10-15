<?php

namespace App\Domain\ContentGeneration;

use JsonException;
use RuntimeException;

final class SemanticQualityReviewer
{
    /** @var list<string> */
    public const EVIDENCE_ISSUE_CODES = ['needs_evidence', 'unsupported_claim', 'unsupported_fact'];

    /** @param array<string, mixed> $blueprint @param list<mixed> $evidence */
    public function prompt(array $blueprint, array $evidence, string $article): string
    {
        return <<<'PROMPT'
Review the article against the blueprint for answer completeness, substantive value, internal consistency, evidence support, audience/search-intent fit, and contextual continuity.

Return exactly one JSON object and no Markdown fences or commentary. The object must have exactly these keys:
{"passed":boolean,"score":number from 0 through 100,"issues":[{"code":non-empty string,"section_key":string or null,"message":non-empty string,"repair_instruction":non-empty string}]}
When passed is true, issues must be empty. When passed is false, issues must contain at least one actionable item.
Use issue code "needs_evidence" when a material claim cannot be evaluated or repaired without additional evidence. Use "unsupported_claim" or "unsupported_fact" when attached evidence contradicts or does not support a claim.

CONTENT BLUEPRINT
PROMPT
            ."\n".$this->json($blueprint)
            ."\n\nEVIDENCE SNAPSHOTS\n".$this->json($evidence)
            ."\n\nARTICLE\n".$article;
    }

    /**
     * @return array{passed: bool, score: float, issues: list<array{code: string, section_key: string|null, message: string, repair_instruction: string}>}
     */
    public function parse(string $response): array
    {
        if (trim($response) !== $response || $response === '' || str_contains($response, '```')) {
            throw new RuntimeException('Semantic review did not return a bare JSON object.');
        }

        try {
            $decoded = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Semantic review returned invalid JSON.', 0, $exception);
        }

        if (! is_array($decoded) || array_is_list($decoded) || ! $this->hasExactKeys($decoded, ['passed', 'score', 'issues'])) {
            throw new RuntimeException('Semantic review JSON does not match the required top-level contract.');
        }
        if (! is_bool($decoded['passed']) || ! is_int($decoded['score']) && ! is_float($decoded['score'])) {
            throw new RuntimeException('Semantic review passed and score fields have invalid types.');
        }

        $score = (float) $decoded['score'];
        if (! is_finite($score) || $score < 0 || $score > 100 || ! is_array($decoded['issues']) || ! array_is_list($decoded['issues'])) {
            throw new RuntimeException('Semantic review score or issues field is invalid.');
        }

        $issues = [];
        foreach ($decoded['issues'] as $issue) {
            if (! is_array($issue) || array_is_list($issue)
                || ! $this->hasExactKeys($issue, ['code', 'section_key', 'message', 'repair_instruction'])
                || ! is_string($issue['code']) || trim($issue['code']) === ''
                || ! (is_string($issue['section_key']) || $issue['section_key'] === null)
                || ! is_string($issue['message']) || trim($issue['message']) === ''
                || ! is_string($issue['repair_instruction']) || trim($issue['repair_instruction']) === '') {
                throw new RuntimeException('Semantic review issue does not match the required contract.');
            }

            $issues[] = [
                'code' => trim($issue['code']),
                'section_key' => $issue['section_key'] === null ? null : trim($issue['section_key']),
                'message' => trim($issue['message']),
                'repair_instruction' => trim($issue['repair_instruction']),
            ];
        }

        if ($decoded['passed'] !== ($issues === [])) {
            throw new RuntimeException('Semantic review passed state contradicts its issues.');
        }

        return ['passed' => $decoded['passed'], 'score' => $score, 'issues' => $issues];
    }

    /** @param list<array<string, mixed>> $issues */
    public function needsEvidence(array $issues): bool
    {
        foreach ($issues as $issue) {
            if (in_array((string) ($issue['code'] ?? ''), self::EVIDENCE_ISSUE_CODES, true)) {
                return true;
            }
        }

        return false;
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $value @param list<string> $expected */
    private function hasExactKeys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        return $actual === $expected;
    }
}
