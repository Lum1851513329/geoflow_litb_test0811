<?php

namespace App\Services\GeoFlow\Semrush;

use InvalidArgumentException;

/** Converts Semrush Topic Gap and Prompt Gap exports into brand-scoped candidates. */
final class SemrushTopicGapConverter
{
    private const LEGACY_REQUIRED_COLUMNS = ['name', 'difficulty', 'volume', 'intents'];

    private const PROMPT_GAP_REQUIRED_COLUMNS = ['topic_name', 'topic_volume', 'topic_difficulty', 'topic_intents'];

    private const REJECT_ADULT = '/(\bnude\b|\bporn|erotic|adult content|\banal toys\b|upskirt|wet t-shirt|\bleaks\b|uncensored|nipple|mail order brides)/i';

    public function __construct(
        private readonly SemrushBrandProfileResolver $profiles,
        private readonly SemrushTopicClassifier $classifier,
    ) {}

    /** @return array<string,mixed> */
    public function convert(string $csvPath, int $limit, ?int $brandId = null, ?int $siteId = null): array
    {
        if ($brandId === null) {
            throw new InvalidArgumentException('A knowledge brand is required for Semrush conversion.');
        }
        $profile = $this->profiles->resolve($brandId, $siteId);
        $handle = fopen($csvPath, 'r');
        if ($handle === false) {
            return ['ok' => false, 'missing_columns' => []];
        }

        try {
            $header = fgetcsv($handle);
            if ($header === false) {
                return ['ok' => false, 'missing_columns' => self::PROMPT_GAP_REQUIRED_COLUMNS];
            }
            $index = array_flip(array_map('trim', $header));
            if ($this->hasColumns($index, self::PROMPT_GAP_REQUIRED_COLUMNS)) {
                $rawRows = $this->readPromptGapRows($handle, $index);
            } elseif ($this->hasColumns($index, self::LEGACY_REQUIRED_COLUMNS)) {
                $rawRows = $this->readLegacyRows($handle, $index);
            } else {
                $missing = array_values(array_filter(self::PROMPT_GAP_REQUIRED_COLUMNS, static fn (string $column): bool => ! isset($index[$column])));

                return ['ok' => false, 'missing_columns' => $missing];
            }
        } finally {
            fclose($handle);
        }

        $accepted = [];
        $quarantined = [];
        $rejectedCounts = [];
        $rejectedExamples = [];
        foreach ($rawRows as $raw) {
            $topic = trim((string) ($raw['topic'] ?? ''));
            $rejection = $this->rejectionReason($topic);
            if ($rejection !== null) {
                $rejectedCounts[$rejection] = ($rejectedCounts[$rejection] ?? 0) + 1;
                $rejectedExamples[$rejection] ??= $topic;

                continue;
            }

            $classification = $this->classifier->classify($raw, $profile);
            $category = $this->resolveCategory($profile, $classification['content_type']);
            $preset = $this->resolvePreset($profile, $classification['content_type']);
            if ($category === null || $preset === null) {
                $reason = $category === null ? 'no_category_mapping' : 'no_production_preset_mapping';
                $quarantined[] = [
                    'topic' => $topic,
                    'reason' => $reason,
                    'reason_codes' => $classification['reason_codes'],
                    'knowledge_mode' => $classification['knowledge_mode'],
                    'content_type' => $classification['content_type'],
                ];

                continue;
            }

            $rootDirectory = $this->rootDirectory($profile, $classification['directory_required']);
            if ($classification['directory_required'] && $rootDirectory === null) {
                $quarantined[] = [
                    'topic' => $topic,
                    'reason' => 'no_brand_directory',
                    'reason_codes' => $classification['reason_codes'],
                    'knowledge_mode' => $classification['knowledge_mode'],
                    'content_type' => $classification['content_type'],
                ];

                continue;
            }

            $accepted[] = [
                'topic' => $topic,
                'volume' => (int) ($raw['volume'] ?? 0),
                'difficulty' => (int) ($raw['difficulty'] ?? 0),
                'category' => $category['name'],
                'directory' => $rootDirectory['path'] ?? '',
                'preset_key' => $preset['key'],
                'score' => $this->opportunityScore((int) ($raw['volume'] ?? 0), (int) ($raw['difficulty'] ?? 0), (array) ($raw['intents'] ?? [])),
                'core_question' => '',
                'primary_keyword' => mb_strtolower((string) ($raw['primary_keyword'] ?? $topic)),
                'knowledge_mode' => $classification['knowledge_mode'],
                'content_type' => $classification['content_type'],
                'external_evidence_policy' => 'on_insufficient',
                'external_general_domains' => $profile['general_domains'],
                'official_domains' => $profile['official_domains'],
                'brand_profile_snapshot_hash' => $profile['snapshot_hash'],
                'directory_required' => $classification['directory_required'],
            ];
        }

        usort($accepted, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        return [
            'ok' => true,
            'accepted' => array_slice($accepted, 0, max(1, $limit)),
            'quarantined' => $quarantined,
            'rejected_counts' => $rejectedCounts,
            'rejected_examples' => $rejectedExamples,
            'usable_count' => count($accepted),
            'brand_profile_snapshot_hash' => $profile['snapshot_hash'],
        ];
    }

    /** @param array<string,int> $index @return list<array<string,mixed>> */
    private function readPromptGapRows($handle, array $index): array
    {
        $topics = [];
        while (($row = fgetcsv($handle)) !== false) {
            $topic = trim((string) ($row[$index['topic_name']] ?? ''));
            if ($topic === '') {
                continue;
            }
            $key = isset($index['topic_id']) ? trim((string) ($row[$index['topic_id']] ?? '')) : $topic;
            $key = $key !== '' ? $key : $topic;
            if (isset($topics[$key])) {
                continue;
            }
            $topics[$key] = [
                'topic' => $topic,
                'volume' => (int) ($row[$index['topic_volume']] ?? 0),
                'difficulty' => (int) ($row[$index['topic_difficulty']] ?? 0),
                'intents' => $this->parseIntents((string) ($row[$index['topic_intents']] ?? '')),
                'primary_keyword' => trim((string) ($row[$index['prompt']] ?? '')),
            ];
        }

        return array_values($topics);
    }

    /** @param array<string,int> $index @return list<array<string,mixed>> */
    private function readLegacyRows($handle, array $index): array
    {
        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $topic = trim((string) ($row[$index['name']] ?? ''));
            if ($topic === '') {
                continue;
            }
            $rows[] = [
                'topic' => $topic,
                'volume' => (int) ($row[$index['volume']] ?? 0),
                'difficulty' => (int) ($row[$index['difficulty']] ?? 0),
                'intents' => $this->parseIntents((string) ($row[$index['intents']] ?? '')),
                'primary_keyword' => $topic,
            ];
        }

        return $rows;
    }

    /** @param array<string,int> $index @param list<string> $required */
    private function hasColumns(array $index, array $required): bool
    {
        foreach ($required as $column) {
            if (! isset($index[$column])) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string,int> */
    private function parseIntents(string $raw): array
    {
        $out = [];
        foreach (explode(';', $raw) as $part) {
            if (! str_contains($part, ':')) {
                continue;
            }
            [$key, $value] = explode(':', $part, 2);
            $out[trim($key)] = (int) trim($value);
        }

        return $out;
    }

    private function opportunityScore(int $volume, int $difficulty, array $intents): float
    {
        $commercial = ($intents['commercial'] ?? 0) + ($intents['transactional'] ?? 0);

        return log10(max(10, $volume)) * (0.5 + ($commercial / 100)) * (1 - (min(100, max(0, $difficulty)) / 150));
    }

    /** @param array<string,mixed> $profile */
    private function resolveCategory(array $profile, string $contentType): ?array
    {
        $terms = match ($contentType) {
            'comparison' => ['comparison', 'compare'],
            'brand_policy' => ['policy', 'support', 'help', 'shipping', 'return', 'service'],
            'industry' => ['industry', 'market', 'research', 'insight'],
            'educational' => ['education', 'educational', 'faq', 'guide', 'learn'],
            default => ['buying', 'shopping', 'guide', 'style', 'product'],
        };
        $best = null;
        $bestScore = 0;
        foreach ((array) ($profile['categories'] ?? []) as $category) {
            $name = mb_strtolower((string) ($category['name'] ?? ''));
            $score = 0;
            foreach ($terms as $term) {
                if (str_contains($name, $term)) {
                    $score++;
                }
            }
            if ($score > $bestScore) {
                $best = $category;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /** @param array<string,mixed> $profile */
    private function resolvePreset(array $profile, string $contentType): ?array
    {
        $aliases = match ($contentType) {
            'comparison' => ['comparison', 'compare'],
            'brand_policy' => ['trust_service', 'brand_company', 'faq', 'policy', 'support'],
            'industry' => ['category_guide', 'industry', 'authority', 'research'],
            'educational' => ['faq', 'category_guide', 'buying_guide', 'education'],
            default => ['buying_guide', 'category_guide', 'shopping', 'product'],
        };
        foreach ((array) ($profile['presets'] ?? []) as $preset) {
            foreach ((array) ($preset['content_types'] ?? []) as $type) {
                foreach ($aliases as $alias) {
                    if (str_contains(mb_strtolower((string) $type), $alias)) {
                        return $preset;
                    }
                }
            }
        }

        return null;
    }

    /** @param array<string,mixed> $profile */
    private function rootDirectory(array $profile, bool $required): ?array
    {
        if (! $required) {
            return null;
        }
        foreach ((array) ($profile['directories'] ?? []) as $directory) {
            if (($directory['parent_id'] ?? null) === null) {
                return $directory;
            }
        }

        return null;
    }

    private function rejectionReason(string $topic): ?string
    {
        return preg_match(self::REJECT_ADULT, $topic) === 1 ? 'adult' : null;
    }
}
