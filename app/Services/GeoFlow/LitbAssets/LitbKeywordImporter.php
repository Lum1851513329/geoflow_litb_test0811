<?php

namespace App\Services\GeoFlow\LitbAssets;

use App\Models\Keyword;
use App\Models\KeywordImportBatch;
use App\Models\KeywordImportRow;
use App\Models\KeywordLibrary;
use App\Models\KeywordMetric;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class LitbKeywordImporter
{
    /** @param iterable<array<string, mixed>> $rows */
    public function import(iterable $rows): LitbAssetManifest
    {
        $materialized = array_values(is_array($rows) ? $rows : iterator_to_array($rows, false));
        $manifest = new LitbAssetManifest();
        if ($materialized === []) {
            return $manifest;
        }

        DB::transaction(function () use ($materialized, $manifest): void {
            $first = $materialized[0];
            $library = $this->library($first);
            $scope = hash('sha256', json_encode([
                'library_asset_key' => $first['library_asset_key'],
                'language_code' => $first['language_code'],
                'market_scope' => $first['market_scope'],
                'source_type' => $first['source_type'],
            ], JSON_THROW_ON_ERROR));
            $batch = KeywordImportBatch::query()->firstOrCreate(
                ['file_sha256' => $first['source_sha256'], 'config_scope' => $scope],
                [
                    'library_id' => $library->id,
                    'original_filename' => $first['source_filename'],
                    'stored_path' => '',
                    'import_config_hash' => $scope,
                    'source_type' => $first['source_type'],
                    'status' => 'processing',
                    'mapping_json' => ['keyword' => 'keyword'],
                    'metadata_json' => ['asset_namespace' => 'litb.content-assets', 'source_path' => $first['source_path']],
                    'total_count' => count($materialized),
                    'started_at' => now(),
                ],
            );
            if (! $batch->wasRecentlyCreated) {
                foreach ($materialized as $row) {
                    $manifest->skipped((string) $row['normalized_keyword']);
                }

                return;
            }

            $imported = 0;
            $duplicates = 0;
            foreach ($materialized as $row) {
                $keyword = Keyword::query()->where([
                    'library_id' => $library->id,
                    'normalized_keyword' => $row['normalized_keyword'],
                    'language_code' => $row['language_code'],
                    'market_scope' => $row['market_scope'],
                ])->lockForUpdate()->first();
                $status = 'duplicate';
                if ($keyword === null) {
                    $keyword = Keyword::query()->create([
                        'library_id' => $library->id,
                        'keyword' => $row['keyword'],
                        'normalized_keyword' => $row['normalized_keyword'],
                        'language_code' => $row['language_code'],
                        'market_code' => $row['market_code'],
                        'market_scope' => $row['market_scope'],
                        'keyword_type' => $row['keyword_type'],
                        'search_intent' => $row['search_intent'],
                        'brand_relation' => $row['brand_relation'],
                        'status' => 'active',
                        'used_count' => 0,
                        'usage_count' => 0,
                    ]);
                    $status = 'imported';
                    $imported++;
                    $manifest->created((string) $row['normalized_keyword']);
                } else {
                    $duplicates++;
                    $manifest->skipped((string) $row['normalized_keyword']);
                }
                $importRow = KeywordImportRow::query()->create([
                    'batch_id' => $batch->id,
                    'sheet_name' => $row['source_sheet'] ?? 'text',
                    'row_number' => $row['source_line'],
                    'source_payload' => $row,
                    'keyword_id' => $keyword->id,
                    'normalized_keyword' => $row['normalized_keyword'],
                    'status' => $status,
                ]);
                $metricValues = array_filter([
                    'clicks' => $row['clicks'] ?? null,
                    'impressions' => $row['impressions'] ?? null,
                    'ctr' => $row['ctr'] ?? null,
                    'average_position' => $row['average_position'] ?? null,
                    'cost' => $row['cost'] ?? null,
                    'conversions' => $row['conversions'] ?? null,
                ], static fn (mixed $value): bool => $value !== null);
                if ($metricValues !== []) {
                    KeywordMetric::query()->create([
                        'keyword_id' => $keyword->id,
                        'import_batch_id' => $batch->id,
                        'import_row_id' => $importRow->id,
                        'source_type' => $row['source_type'],
                        'market_code' => $row['market_code'],
                        'language_code' => $row['language_code'],
                        'source_payload' => $row,
                        ...$metricValues,
                    ]);
                }
            }
            $batch->update([
                'status' => 'completed',
                'processed_count' => count($materialized),
                'imported_count' => $imported,
                'duplicate_count' => $duplicates,
                'completed_at' => now(),
            ]);
            $library->update(['keyword_count' => $library->keywords()->count()]);
        }, 3);

        return $manifest;
    }

    /** @param array<string, mixed> $row */
    private function library(array $row): KeywordLibrary
    {
        $assetKey = (string) ($row['library_asset_key'] ?? '');
        $name = (string) ($row['library_name'] ?? '');
        if ($assetKey === '' || $name === '') {
            throw new RuntimeException('litb_keywords.library_identity_missing');
        }
        $library = KeywordLibrary::query()->where('asset_key', $assetKey)->lockForUpdate()->first();
        if ($library !== null) {
            return $library;
        }
        if (KeywordLibrary::query()->where('name', $name)->exists()) {
            throw new RuntimeException('litb_keywords.operator_owned_library_collision');
        }

        return KeywordLibrary::query()->create([
            'asset_key' => $assetKey,
            'name' => $name,
            'description' => 'Governed LITB keyword asset library.',
            'keyword_count' => 0,
            'asset_provenance' => ['namespace' => 'litb.content-assets', 'asset_key' => $assetKey],
        ]);
    }
}
