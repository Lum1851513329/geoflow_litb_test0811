<?php

namespace App\Services\GeoFlow\Keywords;

use InvalidArgumentException;

final class KeywordImportPreview
{
    private const MAPPABLE_FIELDS = [
        'keyword', 'clicks', 'impressions', 'ctr', 'position', 'country', 'language', 'date_range', 'cost', 'conversions',
    ];

    public function __construct(
        private readonly ?KeywordWorkbookReader $reader = null,
        private readonly ?KeywordColumnMapper $mapper = null,
        private readonly ?KeywordNormalizer $normalizer = null,
        private readonly ?KeywordClassifier $classifier = null,
        private readonly int $maxSamples = 10,
    ) {}

    /**
     * @param  array<string,string>  $mapping
     * @return array{valid_samples:array<int,array<string,mixed>>,rejected_samples:array<int,array<string,mixed>>,counts:array{total_rows:int,valid_rows:int,rejected_rows:int}}
     */
    public function build(string $absolutePath, string $sourceType, array $mapping): array
    {
        $reader = $this->reader ?? new KeywordWorkbookReader;
        $mapper = $this->mapper ?? new KeywordColumnMapper;
        $normalizer = $this->normalizer ?? new KeywordNormalizer;
        $classifier = $this->classifier ?? new KeywordClassifier;
        $inspection = $reader->inspect($absolutePath);
        $validSamples = [];
        $rejectedSamples = [];
        $counts = ['total_rows' => 0, 'valid_rows' => 0, 'rejected_rows' => 0];

        foreach ($inspection['sheets'] as $sheet) {
            $suggestedMapping = $mapper->suggest($sheet['headers'], $sourceType);
            $resolvedMapping = $this->resolveMapping($suggestedMapping, $mapping, $sheet['headers']);

            foreach ($sheet['rows'] as $index => $row) {
                $counts['total_rows']++;
                $payload = ['sheet' => $sheet['name'], 'row_number' => $index + 2, 'source_payload' => $row];
                $keyword = $this->mappedValue($row, $resolvedMapping, 'keyword');

                if (! is_string($keyword) || trim($keyword) === '') {
                    $counts['rejected_rows']++;
                    if (count($rejectedSamples) < $this->maxSamples) {
                        $rejectedSamples[] = $payload + ['reason' => 'The keyword cannot be blank.'];
                    }

                    continue;
                }

                try {
                    $normalized = $normalizer->normalize(
                        $keyword,
                        $this->nullableString($this->mappedValue($row, $resolvedMapping, 'language')),
                        $this->nullableString($this->mappedValue($row, $resolvedMapping, 'country')),
                    );
                } catch (InvalidArgumentException $exception) {
                    $counts['rejected_rows']++;
                    if (count($rejectedSamples) < $this->maxSamples) {
                        $rejectedSamples[] = $payload + ['reason' => $exception->getMessage()];
                    }

                    continue;
                }

                $counts['valid_rows']++;
                if (count($validSamples) < $this->maxSamples) {
                    $validSamples[] = $payload + [
                        'keyword' => [
                            'original' => $normalized->original,
                            'normalized' => $normalized->normalized,
                            'language_code' => $normalized->languageCode,
                            'market_code' => $normalized->marketCode,
                        ],
                        'classification' => $classifier->classify($normalized),
                        'mapped_values' => $this->mappedValues($row, $resolvedMapping),
                    ];
                }
            }
        }

        return ['valid_samples' => $validSamples, 'rejected_samples' => $rejectedSamples, 'counts' => $counts];
    }

    /**
     * @param  array<string,string>  $suggestedMapping
     * @param  array<string,string>  $mapping
     * @param  array<int,string>  $headers
     * @return array<string,string>
     */
    private function resolveMapping(array $suggestedMapping, array $mapping, array $headers): array
    {
        $allowedHeaders = array_flip($headers);
        $resolved = $suggestedMapping;
        foreach ($mapping as $header => $field) {
            if (isset($allowedHeaders[$header]) && in_array($field, self::MAPPABLE_FIELDS, true)) {
                $resolved[$header] = $field;
            }
        }

        return $resolved;
    }

    /** @param array<string,mixed> $row
     * @param  array<string,string>  $mapping
     */
    private function mappedValue(array $row, array $mapping, string $field): mixed
    {
        foreach ($mapping as $header => $mappedField) {
            if ($mappedField === $field) {
                return $row[$header] ?? null;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $row
     * @param  array<string,string>  $mapping
     * @return array<string,mixed>
     */
    private function mappedValues(array $row, array $mapping): array
    {
        $values = [];
        foreach ($mapping as $header => $field) {
            $values[$field] = $row[$header] ?? null;
        }

        return $values;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
