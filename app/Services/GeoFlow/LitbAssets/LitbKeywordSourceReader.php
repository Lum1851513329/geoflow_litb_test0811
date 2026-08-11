<?php

namespace App\Services\GeoFlow\LitbAssets;

use App\Services\GeoFlow\Keywords\KeywordClassifier;
use App\Services\GeoFlow\Keywords\KeywordColumnMapper;
use App\Services\GeoFlow\Keywords\KeywordNormalizer;
use App\Services\GeoFlow\Keywords\KeywordWorkbookReader;
use InvalidArgumentException;
use RuntimeException;

final class LitbKeywordSourceReader
{
    public function __construct(
        private readonly KeywordNormalizer $normalizer,
        private readonly KeywordClassifier $classifier,
        private readonly KeywordWorkbookReader $workbookReader,
        private readonly KeywordColumnMapper $columnMapper,
    ) {}

    /**
     * @param array{library_asset_key:string,library_name:string,language_code:string,market_code:string,source_type:string} $sourceConfig
     * @return \Generator<int, array<string, mixed>>
     */
    public function read(string $path, array $sourceConfig): \Generator
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('litb_keywords.source_unreadable');
        }
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (! in_array($extension, ['txt', 'md', 'xlsx'], true)) {
            throw new InvalidArgumentException('litb_keywords.source_type_unsupported');
        }
        $sha256 = hash_file('sha256', $path);
        if ($sha256 === false) {
            throw new RuntimeException('litb_keywords.source_hash_failed');
        }
        if ($extension === 'xlsx') {
            yield from $this->workbookRows($path, $sourceConfig, $sha256);

            return;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('litb_keywords.source_open_failed');
        }
        try {
            $lineNumber = 0;
            while (($line = fgets($handle)) !== false) {
                $lineNumber++;
                $keyword = preg_replace('/^\s*(?:[-*+]\s+|\d+[.)]\s+|#+\s*)/u', '', trim($line));
                if ($keyword === null || $keyword === '' || str_starts_with($keyword, '<!--')) {
                    continue;
                }
                yield $this->row($path, $sha256, $lineNumber, $keyword, $sourceConfig);
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string, string> $sourceConfig @return \Generator<int, array<string, mixed>> */
    private function workbookRows(string $path, array $sourceConfig, string $sha256): \Generator
    {
        $inspection = $this->workbookReader->inspect($path);
        foreach ($this->workbookReader->chunks($path, $inspection) as $chunk) {
            $mapping = $this->columnMapper->suggest($chunk['headers'], $sourceConfig['source_type']);
            foreach ($chunk['rows'] as $sourceRow) {
                $mapped = [];
                foreach ($mapping as $header => $field) {
                    $mapped[$field] = $sourceRow['values'][$header] ?? null;
                }
                $keyword = trim((string) ($mapped['keyword'] ?? ''));
                if ($keyword === '') {
                    continue;
                }
                $rowConfig = $sourceConfig;
                $rowConfig['language_code'] = trim((string) ($mapped['language'] ?? $sourceConfig['language_code']));
                $market = strtoupper(trim((string) ($mapped['country'] ?? $sourceConfig['market_code'])));
                $rowConfig['market_code'] = preg_match('/^[A-Z]{2}$/D', $market) === 1 ? $market : $sourceConfig['market_code'];
                yield $this->row($path, $sha256, (int) $sourceRow['row_number'], $keyword, $rowConfig) + [
                    'source_sheet' => $chunk['sheet_name'],
                    'clicks' => $this->number($mapped['clicks'] ?? null),
                    'impressions' => $this->number($mapped['impressions'] ?? null),
                    'ctr' => $this->number($mapped['ctr'] ?? null),
                    'average_position' => $this->number($mapped['position'] ?? null),
                    'cost' => $this->number($mapped['cost'] ?? null),
                    'conversions' => $this->number($mapped['conversions'] ?? null),
                ];
            }
        }
    }

    /** @param array<string, string> $sourceConfig @return array<string, mixed> */
    private function row(string $path, string $sha256, int $line, string $keyword, array $sourceConfig): array
    {
        $normalized = $this->normalizer->normalize($keyword, $sourceConfig['language_code'], $sourceConfig['market_code']);
        $classification = $this->classifier->classify($normalized);

        return [
            ...$sourceConfig,
            'source_path' => $path,
            'source_filename' => basename($path),
            'source_sha256' => $sha256,
            'source_line' => $line,
            'keyword' => $keyword,
            'normalized_keyword' => $normalized->normalized,
            'language_code' => $normalized->languageCode,
            'market_code' => $normalized->marketCode,
            'market_scope' => $normalized->marketCode ?? 'GLOBAL',
            ...$classification,
        ];
    }

    private function number(mixed $value): int|float|null
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return str_contains((string) $value, '.') ? (float) $value : (int) $value;
    }
}
