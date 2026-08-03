<?php

namespace App\Services\GeoFlow\Keywords;

use App\Events\KeywordImportProgressed;
use App\Models\Keyword;
use App\Models\KeywordImportBatch;
use App\Models\KeywordImportRow;
use App\Models\KeywordMetric;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class KeywordImportService
{
    private const MAPPABLE_FIELDS = [
        'keyword', 'clicks', 'impressions', 'ctr', 'position', 'country', 'language', 'date_range', 'cost', 'conversions',
    ];

    public function __construct(
        private readonly ?KeywordWorkbookReader $reader = null,
        private readonly ?KeywordColumnMapper $mapper = null,
        private readonly ?KeywordNormalizer $normalizer = null,
        private readonly ?KeywordClassifier $classifier = null,
        private readonly int $chunkSize = 250,
    ) {
        if ($this->chunkSize < 1) {
            throw new InvalidArgumentException('The import chunk size must be positive.');
        }
    }

    /** @param array<string,mixed> $metadata */
    public function createBatch(array $metadata): KeywordImportBatch
    {
        $sourcePath = $this->requiredString($metadata, 'source_path');
        if (! is_file($sourcePath) || ! is_readable($sourcePath)) {
            throw new InvalidArgumentException('The source workbook must be a readable file.');
        }

        $libraryId = filter_var($metadata['library_id'] ?? null, FILTER_VALIDATE_INT);
        if ($libraryId === false || $libraryId < 1) {
            throw new InvalidArgumentException('A valid keyword library is required.');
        }

        $sourceType = $this->requiredString($metadata, 'source_type');
        $mapping = $this->stringMap($metadata['mapping_json'] ?? []);
        $batchMetadata = is_array($metadata['metadata_json'] ?? null) ? $metadata['metadata_json'] : [];
        $fileHash = hash_file('sha256', $sourcePath);
        if ($fileHash === false) {
            throw new InvalidArgumentException('The source workbook could not be hashed.');
        }

        $config = [
            'library_id' => (int) $libraryId,
            'source_type' => $sourceType,
            'mapping_json' => $mapping,
            'metadata_json' => $batchMetadata,
            'period_start' => $this->nullableString($metadata['period_start'] ?? null),
            'period_end' => $this->nullableString($metadata['period_end'] ?? null),
        ];
        $configHash = hash('sha256', json_encode($this->canonicalize($config), JSON_THROW_ON_ERROR));

        if (KeywordImportBatch::query()->where('file_sha256', $fileHash)->where('import_config_hash', $configHash)->exists()) {
            throw new InvalidArgumentException('This workbook and import configuration already have a batch.');
        }

        $storedPath = 'keyword-imports/'.Str::uuid().'.xlsx';
        $contents = file_get_contents($sourcePath);
        if ($contents === false || ! Storage::disk('local')->put($storedPath, $contents)) {
            throw new RuntimeException('The workbook could not be persisted to private storage.');
        }

        try {
            return KeywordImportBatch::query()->create([
                'library_id' => (int) $libraryId,
                'original_filename' => $this->nullableString($metadata['original_filename'] ?? null) ?? basename($sourcePath),
                'stored_path' => $storedPath,
                'file_sha256' => $fileHash,
                'import_config_hash' => $configHash,
                'source_type' => $sourceType,
                'status' => 'pending',
                'mapping_json' => $mapping,
                'metadata_json' => $batchMetadata,
                'period_start' => $config['period_start'],
                'period_end' => $config['period_end'],
                'created_by' => $metadata['created_by'] ?? null,
            ]);
        } catch (QueryException $exception) {
            Storage::disk('local')->delete($storedPath);
            if (KeywordImportBatch::query()->where('file_sha256', $fileHash)->where('import_config_hash', $configHash)->exists()) {
                throw new InvalidArgumentException('This workbook and import configuration already have a batch.', previous: $exception);
            }

            throw $exception;
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($storedPath);

            throw $exception;
        }
    }

    public function import(KeywordImportBatch $batch): KeywordImportBatch
    {
        $batch = KeywordImportBatch::query()->findOrFail($batch->getKey());
        if ($batch->status === 'completed') {
            return $batch;
        }
        if ($batch->status === 'reverted') {
            throw new InvalidArgumentException('A reverted keyword import cannot be replayed.');
        }

        $batch->forceFill([
            'status' => 'processing',
            'started_at' => $batch->started_at ?? now(),
            'completed_at' => null,
            'error_summary' => null,
        ])->save();
        $this->progress($batch);

        try {
            $disk = Storage::disk('local');
            if (! $disk->exists($batch->stored_path)) {
                throw new RuntimeException('The stored workbook is missing.');
            }

            $inspection = ($this->reader ?? new KeywordWorkbookReader)->inspect($disk->path($batch->stored_path));
            $work = [];
            foreach ($inspection['sheets'] as $sheet) {
                $resolvedMapping = $this->resolveMapping($sheet['headers'], $batch->source_type, $batch->mapping_json ?? []);
                foreach ($sheet['rows'] as $row) {
                    $work[] = ['sheet_name' => $sheet['name'], 'row' => $row, 'mapping' => $resolvedMapping];
                }
            }

            $batch->forceFill(['total_count' => count($work)])->save();
            foreach (array_chunk($work, $this->chunkSize) as $chunk) {
                DB::transaction(function () use ($batch, $chunk): void {
                    foreach ($chunk as $item) {
                        $this->importRow($batch, $item['sheet_name'], $item['row'], $item['mapping']);
                    }

                    $this->refreshCounts($batch);
                });
                $this->progress($batch->fresh());
            }

            DB::transaction(function () use ($batch): void {
                $this->refreshCounts($batch);
                $batch->library?->forceFill([
                    'keyword_count' => Keyword::query()->where('library_id', $batch->library_id)->count(),
                ])->save();
                $batch->forceFill(['status' => 'completed', 'completed_at' => now(), 'error_summary' => null])->save();
            });
            $this->progress($batch->fresh());

            return $batch->fresh();
        } catch (Throwable $exception) {
            $batch->fresh()?->forceFill([
                'status' => 'failed',
                'error_summary' => mb_substr($exception->getMessage(), 0, 2000),
            ])->save();
            $this->progress($batch->fresh());

            throw $exception;
        }
    }

    /**
     * @param  array{row_number:int,values:array<string,mixed>}  $row
     * @param  array<string,string>  $mapping
     */
    private function importRow(KeywordImportBatch $batch, string $sheetName, array $row, array $mapping): void
    {
        $existingRow = KeywordImportRow::query()
            ->where('batch_id', $batch->id)
            ->where('sheet_name', $sheetName)
            ->where('row_number', $row['row_number'])
            ->first();
        if ($existingRow && in_array($existingRow->status, ['imported', 'duplicate', 'rejected'], true)) {
            return;
        }

        $values = $row['values'];
        $keywordValue = $this->mappedValue($values, $mapping, 'keyword');
        if (! is_scalar($keywordValue) || trim((string) $keywordValue) === '') {
            KeywordImportRow::query()->updateOrCreate(
                ['batch_id' => $batch->id, 'sheet_name' => $sheetName, 'row_number' => $row['row_number']],
                ['source_payload' => $values, 'status' => 'rejected', 'error_message' => 'The keyword cannot be blank.']
            );

            return;
        }

        try {
            $metadata = $batch->metadata_json ?? [];
            $normalizer = $this->normalizer ?? new KeywordNormalizer;
            $normalized = $normalizer->normalize(
                (string) $keywordValue,
                $this->nullableString($this->mappedValue($values, $mapping, 'language'))
                    ?? $this->nullableString($metadata['language_code'] ?? null)
                    ?? 'en',
                $this->nullableString($this->mappedValue($values, $mapping, 'country'))
                    ?? $this->nullableString($metadata['market_code'] ?? null),
            );
            $marketScope = $this->nullableString($metadata['market_scope'] ?? null)
                ?? $normalized->marketCode
                ?? 'GLOBAL';
            [$periodStart, $periodEnd] = $this->period($this->mappedValue($values, $mapping, 'date_range'), $batch);
            $metricValues = [
                'clicks' => $this->nullableInteger($this->mappedValue($values, $mapping, 'clicks')),
                'impressions' => $this->nullableInteger($this->mappedValue($values, $mapping, 'impressions')),
                'ctr' => $this->nullableDecimal($this->mappedValue($values, $mapping, 'ctr'), true),
                'average_position' => $this->nullableDecimal($this->mappedValue($values, $mapping, 'position')),
                'cost' => $this->nullableDecimal($this->mappedValue($values, $mapping, 'cost')),
                'conversions' => $this->nullableDecimal($this->mappedValue($values, $mapping, 'conversions')),
            ];
            $keyword = Keyword::query()
                ->where('library_id', $batch->library_id)
                ->where('normalized_keyword', $normalized->normalized)
                ->where('language_code', $normalized->languageCode ?? 'en')
                ->where('market_scope', $marketScope)
                ->first();
            $created = false;
            if (! $keyword) {
                $keyword = Keyword::query()
                    ->where('library_id', $batch->library_id)
                    ->where('keyword', (string) $keywordValue)
                    ->whereNull('normalized_keyword')
                    ->first();
            }

            $classification = ($this->classifier ?? new KeywordClassifier)->classify($normalized);
            if ($keyword) {
                if ($keyword->normalized_keyword === null) {
                    $keyword->forceFill([
                        'normalized_keyword' => $normalized->normalized,
                        'language_code' => $normalized->languageCode ?? 'en',
                        'market_code' => $normalized->marketCode,
                        'market_scope' => $marketScope,
                        ...$classification,
                    ])->save();
                }
            } else {
                $keyword = Keyword::query()->create([
                    'library_id' => $batch->library_id,
                    'keyword' => trim((string) $keywordValue),
                    'normalized_keyword' => $normalized->normalized,
                    'language_code' => $normalized->languageCode ?? 'en',
                    'market_code' => $normalized->marketCode,
                    'market_scope' => $marketScope,
                    ...$classification,
                    'status' => 'active',
                    'used_count' => 0,
                    'usage_count' => 0,
                ]);
                $created = true;
            }

            $importRow = KeywordImportRow::query()->updateOrCreate(
                ['batch_id' => $batch->id, 'sheet_name' => $sheetName, 'row_number' => $row['row_number']],
                [
                    'source_payload' => $values,
                    'keyword_id' => $keyword->id,
                    'normalized_keyword' => $normalized->normalized,
                    'status' => $created ? 'imported' : 'duplicate',
                    'error_message' => null,
                ]
            );
            KeywordMetric::query()->updateOrCreate(
                ['import_row_id' => $importRow->id],
                [
                    'keyword_id' => $keyword->id,
                    'import_batch_id' => $batch->id,
                    'source_type' => $batch->source_type,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'market_code' => $normalized->marketCode,
                    'language_code' => $normalized->languageCode ?? 'en',
                    'device' => $this->nullableString($metadata['device'] ?? null),
                    ...$metricValues,
                    'source_payload' => $values,
                ]
            );
        } catch (InvalidArgumentException $exception) {
            KeywordImportRow::query()->updateOrCreate(
                ['batch_id' => $batch->id, 'sheet_name' => $sheetName, 'row_number' => $row['row_number']],
                ['source_payload' => $values, 'status' => 'rejected', 'error_message' => $exception->getMessage()]
            );
        }
    }

    private function refreshCounts(KeywordImportBatch $batch): void
    {
        $counts = KeywordImportRow::query()
            ->where('batch_id', $batch->id)
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        $batch->forceFill([
            'processed_count' => $counts->sum(),
            'imported_count' => (int) ($counts['imported'] ?? 0),
            'duplicate_count' => (int) ($counts['duplicate'] ?? 0),
            'rejected_count' => (int) ($counts['rejected'] ?? 0),
        ])->save();
    }

    /** @param array<int,string> $headers
     * @param  array<string,string>  $explicit
     * @return array<string,string>
     */
    private function resolveMapping(array $headers, string $sourceType, array $explicit): array
    {
        $resolved = ($this->mapper ?? new KeywordColumnMapper)->suggest($headers, $sourceType);
        $allowedHeaders = array_flip($headers);
        foreach ($explicit as $header => $field) {
            if (! isset($allowedHeaders[$header]) || ! in_array($field, self::MAPPABLE_FIELDS, true)) {
                continue;
            }
            foreach ($resolved as $resolvedHeader => $resolvedField) {
                if ($resolvedField === $field) {
                    unset($resolved[$resolvedHeader]);
                }
            }
            $resolved[$header] = $field;
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

    /** @return array{0:?string,1:?string} */
    private function period(mixed $value, KeywordImportBatch $batch): array
    {
        $value = $this->nullableString($value);
        if ($value === null) {
            return [$batch->period_start?->toDateString(), $batch->period_end?->toDateString()];
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})\s+(?:-|to)\s+(\d{4}-\d{2}-\d{2})$/i', $value, $matches) !== 1) {
            throw new InvalidArgumentException('The date range must contain ISO start and end dates.');
        }

        return [$matches[1], $matches[2]];
    }

    private function nullableInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value) || (float) $value < 0 || (float) $value !== floor((float) $value)) {
            throw new InvalidArgumentException('Integer metrics must be non-negative whole numbers.');
        }

        return (int) $value;
    }

    private function nullableDecimal(mixed $value, bool $percent = false): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $hasPercentSign = is_string($value) && str_ends_with(trim($value), '%');
        $normalized = $hasPercentSign ? rtrim(trim($value), "% \t\n\r\0\x0B") : $value;
        if (! is_numeric($normalized) || (float) $normalized < 0) {
            throw new InvalidArgumentException('Decimal metrics must be non-negative numbers.');
        }
        $number = (float) $normalized;

        return $percent && $hasPercentSign ? $number / 100 : $number;
    }

    /** @param array<string,mixed> $metadata */
    private function requiredString(array $metadata, string $key): string
    {
        $value = $this->nullableString($metadata[$key] ?? null);
        if ($value === null) {
            throw new InvalidArgumentException("The {$key} value is required.");
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @return array<string,string> */
    private function stringMap(mixed $mapping): array
    {
        if (! is_array($mapping)) {
            throw new InvalidArgumentException('The column mapping must be an array.');
        }
        $result = [];
        foreach ($mapping as $header => $field) {
            if (is_string($header) && is_string($field)) {
                $result[$header] = $field;
            }
        }

        return $result;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item) => $this->canonicalize($item), $value);
        }
        ksort($value);

        return array_map(fn (mixed $item) => $this->canonicalize($item), $value);
    }

    private function progress(KeywordImportBatch $batch): void
    {
        KeywordImportProgressed::dispatch(
            (int) $batch->id,
            (string) $batch->status,
            (int) $batch->processed_count,
            (int) $batch->total_count,
            $batch->error_summary,
        );
    }
}
