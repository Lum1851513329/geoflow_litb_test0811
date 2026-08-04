<?php

namespace App\Services\GeoFlow\Keywords;

use App\Events\KeywordImportProgressed;
use App\Models\Keyword;
use App\Models\KeywordImportBatch;
use App\Models\KeywordImportRow;
use App\Models\KeywordMetric;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        $this->assertMaxLength($sourceType, 50, 'The source type');
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $sourceType) !== 1) {
            throw new InvalidArgumentException('The source type contains unsupported characters.');
        }
        $mapping = $this->stringMap($metadata['mapping_json'] ?? []);
        $batchMetadata = is_array($metadata['metadata_json'] ?? null) ? $metadata['metadata_json'] : [];
        $originalFilename = $this->nullableString($metadata['original_filename'] ?? null) ?? basename($sourcePath);
        $this->assertMaxLength($originalFilename, 255, 'The original filename');
        $periodStart = $this->nullableString($metadata['period_start'] ?? null);
        $periodEnd = $this->nullableString($metadata['period_end'] ?? null);
        $this->validatePeriod($periodStart, $periodEnd);
        $fileHash = hash_file('sha256', $sourcePath);
        if ($fileHash === false) {
            throw new InvalidArgumentException('The source workbook could not be hashed.');
        }

        $config = [
            'library_id' => (int) $libraryId,
            'source_type' => $sourceType,
            'mapping_json' => $mapping,
            'metadata_json' => $batchMetadata,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
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
                'original_filename' => $originalFilename,
                'stored_path' => $storedPath,
                'file_sha256' => $fileHash,
                'import_config_hash' => $configHash,
                'config_scope' => $configHash,
                'source_type' => $sourceType,
                'status' => 'uploaded',
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
        [$batch, $claimed] = $this->claimImport((int) $batch->getKey());
        if (! $claimed) {
            return $batch;
        }
        $this->safeProgress($batch);

        try {
            $disk = Storage::disk('local');
            if (! $disk->exists($batch->stored_path)) {
                throw new RuntimeException('The stored workbook is missing.');
            }

            $absolutePath = $disk->path($batch->stored_path);
            $actualHash = hash_file('sha256', $absolutePath);
            if ($actualHash === false || ! hash_equals((string) $batch->file_sha256, $actualHash)) {
                throw new RuntimeException('The stored workbook SHA-256 does not match the import batch.');
            }

            $inspection = ($this->reader ?? new KeywordWorkbookReader)->inspect($absolutePath);
            $work = [];
            foreach ($inspection['sheets'] as $sheet) {
                $resolvedMapping = $this->resolveMapping($sheet['headers'], $batch->source_type, $batch->mapping_json ?? []);
                foreach ($sheet['rows'] as $row) {
                    $work[] = ['sheet_name' => $sheet['name'], 'row' => $row, 'mapping' => $resolvedMapping];
                }
            }
            unset($inspection);

            DB::transaction(function () use ($batch, $work): void {
                $lockedBatch = KeywordImportBatch::query()->lockForUpdate()->findOrFail($batch->id);
                if ($lockedBatch->status !== 'processing') {
                    throw new RuntimeException('The keyword import is no longer processing.');
                }
                $lockedBatch->forceFill(['total_count' => count($work)])->save();
            });
            foreach (array_chunk($work, $this->chunkSize) as $chunk) {
                DB::transaction(function () use ($batch, $chunk): void {
                    $lockedBatch = KeywordImportBatch::query()->lockForUpdate()->findOrFail($batch->id);
                    if ($lockedBatch->status !== 'processing') {
                        throw new RuntimeException('The keyword import is no longer processing.');
                    }
                    foreach ($chunk as $item) {
                        $this->importRow($lockedBatch, $item['sheet_name'], $item['row'], $item['mapping']);
                    }

                    $this->refreshCounts($lockedBatch);
                });
                $this->safeProgress($batch->fresh());
            }

            DB::transaction(function () use ($batch): void {
                $lockedBatch = KeywordImportBatch::query()->lockForUpdate()->findOrFail($batch->id);
                if ($lockedBatch->status !== 'processing') {
                    throw new RuntimeException('The keyword import is no longer processing.');
                }
                $this->refreshCounts($lockedBatch);
                $lockedBatch->library?->forceFill([
                    'keyword_count' => Keyword::query()->where('library_id', $lockedBatch->library_id)->count(),
                ])->save();
                $lockedBatch->forceFill(['status' => 'completed', 'completed_at' => now(), 'error_summary' => null])->save();
            });
            $this->safeProgress($batch->fresh());

            return $batch->fresh();
        } catch (Throwable $exception) {
            $failedBatch = $this->markFailed((int) $batch->id, $exception);
            if ($failedBatch) {
                $this->safeProgress($failedBatch);
            }

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
            $this->assertMaxLength((string) $keywordValue, 200, 'The keyword');
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
            $marketScope = strtoupper($marketScope);
            $device = $this->nullableString($metadata['device'] ?? null);
            $this->assertMaxLength($normalized->normalized, 200, 'The normalized keyword');
            $this->assertMaxLength($normalized->languageCode ?? 'en', 10, 'The language code');
            $this->assertMaxLength($marketScope, 10, 'The market scope');
            $this->assertMaxLength($device, 30, 'The device');
            if ($marketScope !== 'GLOBAL' && preg_match('/^[A-Z]{2}$/', $marketScope) !== 1) {
                throw new InvalidArgumentException('The market scope must be GLOBAL or an ISO country code.');
            }
            [$periodStart, $periodEnd] = $this->period($this->mappedValue($values, $mapping, 'date_range'), $batch);
            $metricValues = [
                'clicks' => $this->nullableUnsignedBigInteger($this->mappedValue($values, $mapping, 'clicks')),
                'impressions' => $this->nullableUnsignedBigInteger($this->mappedValue($values, $mapping, 'impressions')),
                'ctr' => $this->nullableDecimal($this->mappedValue($values, $mapping, 'ctr'), 10, 6, true),
                'average_position' => $this->nullableDecimal($this->mappedValue($values, $mapping, 'position'), 10, 4),
                'cost' => $this->nullableDecimal($this->mappedValue($values, $mapping, 'cost'), 14, 4),
                'conversions' => $this->nullableDecimal($this->mappedValue($values, $mapping, 'conversions'), 14, 4),
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
                    'device' => $device,
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
            $periodStart = $batch->period_start?->toDateString();
            $periodEnd = $batch->period_end?->toDateString();
            $this->validatePeriod($periodStart, $periodEnd);

            return [$periodStart, $periodEnd];
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})\s+(?:-|to)\s+(\d{4}-\d{2}-\d{2})$/i', $value, $matches) !== 1) {
            throw new InvalidArgumentException('The date range must contain ISO start and end dates.');
        }
        $this->validatePeriod($matches[1], $matches[2]);

        return [$matches[1], $matches[2]];
    }

    private function validatePeriod(?string $periodStart, ?string $periodEnd): void
    {
        $start = $periodStart === null ? null : $this->strictDate($periodStart, 'start');
        $end = $periodEnd === null ? null : $this->strictDate($periodEnd, 'end');
        if ($start && $end && $start > $end) {
            throw new InvalidArgumentException('The period start date must not be after the end date.');
        }
    }

    private function strictDate(string $value, string $label): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (! $date
            || $date->format('Y-m-d') !== $value
            || is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw new InvalidArgumentException("The period {$label} date must be a real ISO calendar date.");
        }

        return $date;
    }

    private function nullableUnsignedBigInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $normalized = $this->metricString($value);
        if (preg_match('/^\+?(\d+)$/', $normalized, $matches) !== 1) {
            throw new InvalidArgumentException('Integer metrics must be complete non-negative decimal integers.');
        }
        $digits = ltrim($matches[1], '0');
        $digits = $digits === '' ? '0' : $digits;
        $maximum = '9223372036854775807';
        if (strlen($digits) > strlen($maximum)
            || strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0) {
            throw new InvalidArgumentException('Integer metrics cannot exceed the signed BIGINT maximum.');
        }

        return (int) $digits;
    }

    private function nullableDecimal(mixed $value, int $precision, int $scale, bool $percent = false): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $normalized = $this->metricString($value);
        $hasPercentSign = str_ends_with($normalized, '%');
        if ($hasPercentSign) {
            if (! $percent) {
                throw new InvalidArgumentException('Only CTR metrics may use percent notation.');
            }
            $normalized = trim(substr($normalized, 0, -1));
        }
        if (preg_match('/^\+?(\d+)(?:\.(\d+))?$/', $normalized, $matches) !== 1) {
            throw new InvalidArgumentException('Decimal metrics must use non-negative plain decimal notation.');
        }
        $integer = ltrim($matches[1], '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = $matches[2] ?? '';
        if ($hasPercentSign) {
            [$integer, $fraction] = $this->divideDecimalByHundred($integer, $fraction);
        }

        $integerDigits = $integer === '0' ? 1 : strlen($integer);
        if ($integerDigits > $precision - $scale || strlen($fraction) > $scale) {
            throw new InvalidArgumentException("Decimal metrics must fit DECIMAL({$precision}, {$scale}).");
        }

        return $fraction === '' ? $integer : $integer.'.'.$fraction;
    }

    private function metricString(mixed $value): string
    {
        if (is_float($value) && ! is_finite($value)) {
            throw new InvalidArgumentException('Metric values must be finite numbers.');
        }
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            throw new InvalidArgumentException('Metric values must be decimal numbers.');
        }
        $normalized = trim((string) $value);
        if ($normalized === '' || str_contains(strtolower($normalized), 'e')) {
            throw new InvalidArgumentException('Metric values cannot use empty or exponent notation.');
        }

        return $normalized;
    }

    /** @return array{0:string,1:string} */
    private function divideDecimalByHundred(string $integer, string $fraction): array
    {
        $combined = $integer.$fraction;
        $decimalPosition = strlen($integer) - 2;
        if ($decimalPosition <= 0) {
            $resultInteger = '0';
            $resultFraction = str_repeat('0', -$decimalPosition).$combined;
        } else {
            $resultInteger = substr($combined, 0, $decimalPosition);
            $resultFraction = substr($combined, $decimalPosition);
        }
        $resultInteger = ltrim($resultInteger, '0');

        return [$resultInteger === '' ? '0' : $resultInteger, $resultFraction];
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

    private function assertMaxLength(?string $value, int $maximum, string $label): void
    {
        if ($value !== null && mb_strlen($value, 'UTF-8') > $maximum) {
            throw new InvalidArgumentException("{$label} cannot exceed {$maximum} characters.");
        }
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

    /** @return array{0:KeywordImportBatch,1:bool} */
    private function claimImport(int $batchId): array
    {
        return DB::transaction(function () use ($batchId): array {
            $batch = KeywordImportBatch::query()->lockForUpdate()->findOrFail($batchId);
            if (in_array($batch->status, ['completed', 'processing'], true)) {
                return [$batch, false];
            }
            if (! in_array($batch->status, ['uploaded', 'pending', 'failed'], true)) {
                throw new InvalidArgumentException("A keyword import in {$batch->status} status cannot be started.");
            }

            $batch->forceFill([
                'status' => 'processing',
                'started_at' => $batch->started_at ?? now(),
                'completed_at' => null,
                'error_summary' => null,
            ])->save();

            return [$batch, true];
        });
    }

    private function markFailed(int $batchId, Throwable $exception): ?KeywordImportBatch
    {
        return DB::transaction(function () use ($batchId, $exception): ?KeywordImportBatch {
            $batch = KeywordImportBatch::query()->lockForUpdate()->find($batchId);
            if (! $batch || $batch->status !== 'processing') {
                return $batch;
            }
            $batch->forceFill([
                'status' => 'failed',
                'error_summary' => mb_substr($exception->getMessage(), 0, 2000),
            ])->save();

            return $batch;
        });
    }

    private function safeProgress(KeywordImportBatch $batch): void
    {
        $dispatch = static function () use ($batch): void {
            try {
                KeywordImportProgressed::dispatch(
                    (int) $batch->id,
                    (string) $batch->status,
                    (int) $batch->processed_count,
                    (int) $batch->total_count,
                    $batch->error_summary,
                );
            } catch (Throwable $exception) {
                Log::warning('Keyword import progress event failed.', [
                    'batch_id' => $batch->id,
                    'status' => $batch->status,
                    'exception' => $exception,
                ]);
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);

            return;
        }

        $dispatch();
    }
}
