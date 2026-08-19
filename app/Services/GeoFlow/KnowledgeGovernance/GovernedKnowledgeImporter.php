<?php

namespace App\Services\GeoFlow\KnowledgeGovernance;

use App\Models\KnowledgeBase;
use App\Models\KnowledgeBrand;
use App\Models\KnowledgeDirectory;
use App\Models\KnowledgeDuplicate;
use App\Models\KnowledgeImportBatch;
use App\Models\KnowledgeImportRow;
use App\Models\KnowledgeSite;
use App\Services\GeoFlow\KnowledgeChunkSyncCoordinator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class GovernedKnowledgeImporter
{
    public function __construct(
        private readonly KnowledgeImportValidator $validator,
        private readonly KnowledgeKeywordAssociationService $keywordAssociations,
        private readonly KnowledgeChunkSyncCoordinator $chunkSyncCoordinator,
    ) {}

    /**
     * @return array{
     *   status:string,
     *   batch_id:?int,
     *   imported_count:int,
     *   duplicate_count:int,
     *   source_checksum:string,
     *   configuration_hash:string
     * }
     */
    public function import(string $manifestPath, bool $dryRun = false, int $keywordLibraryId = 42): array
    {
        $package = $this->validator->validate($manifestPath, $keywordLibraryId);
        $summary = [
            'status' => $dryRun ? 'validated' : 'completed',
            'batch_id' => null,
            'imported_count' => count($package['manifest']),
            'duplicate_count' => count($package['duplicates']),
            'source_checksum' => $package['source_checksum'],
            'configuration_hash' => $package['configuration_hash'],
        ];
        $existing = $this->batchForPackage($package['configuration_hash'], $package['source_checksum']);
        if ($dryRun) {
            return $summary;
        }
        if ($existing !== null) {
            if ($existing->status !== 'completed') {
                return [
                    ...$summary,
                    ...$this->dispatchBatch((int) $existing->id),
                ];
            }

            return [
                ...$summary,
                'status' => 'idempotent',
                'batch_id' => (int) $existing->id,
                'imported_count' => (int) $existing->imported_count,
                'duplicate_count' => (int) $existing->duplicate_count,
            ];
        }

        try {
            $batchId = DB::transaction(
                fn (): int => $this->commitPackage($package, $keywordLibraryId),
                3,
            );
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->batchForPackage($package['configuration_hash'], $package['source_checksum']);
            if ($existing === null) {
                throw $exception;
            }
            $batchId = (int) $existing->id;
        }

        return [
            ...$summary,
            ...$this->dispatchBatch($batchId),
        ];
    }

    /**
     * @param array{
     *   manifest:list<array<string,mixed>>,
     *   duplicates:list<array<string,mixed>>,
     *   exclusions:list<array<string,mixed>>,
     *   source_checksum:string,
     *   configuration_hash:string,
     *   manifest_path:string
     * } $package
     */
    private function commitPackage(array $package, int $keywordLibraryId): int
    {
        DB::table('knowledge_brands')->insertOrIgnore([
            'key' => KnowledgeImportValidator::BRAND_KEY,
            'name' => 'LightInTheBox',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $brand = KnowledgeBrand::query()
            ->where('key', KnowledgeImportValidator::BRAND_KEY)
            ->lockForUpdate()
            ->firstOrFail();
        if ($brand->name !== 'LightInTheBox') {
            $brand->update(['name' => 'LightInTheBox']);
        }

        DB::table('knowledge_sites')->insertOrIgnore([
            'brand_id' => $brand->id,
            'key' => KnowledgeImportValidator::SITE_KEY,
            'canonical_host' => 'www.lightinthebox.com',
            'role' => 'commerce-primary',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $site = KnowledgeSite::query()
            ->where('brand_id', $brand->id)
            ->where('key', KnowledgeImportValidator::SITE_KEY)
            ->lockForUpdate()
            ->firstOrFail();
        $site->update([
            'canonical_host' => 'www.lightinthebox.com',
            'role' => 'commerce-primary',
        ]);

        $existing = KnowledgeImportBatch::query()
            ->where('brand_id', $brand->id)
            ->where('site_id', $site->id)
            ->where('configuration_hash', $package['configuration_hash'])
            ->where('source_checksum', $package['source_checksum'])
            ->lockForUpdate()
            ->first();
        if ($existing !== null) {
            return (int) $existing->id;
        }

        $directories = [];
        foreach (KnowledgeImportValidator::ROOT_DIRECTORIES as $key => $name) {
            $directory = KnowledgeDirectory::query()
                ->where('brand_id', $brand->id)
                ->where('path', $key)
                ->lockForUpdate()
                ->first();
            if ($directory === null) {
                $directory = KnowledgeDirectory::query()->create([
                    'brand_id' => $brand->id,
                    'parent_id' => null,
                    'key' => $key,
                    'path' => $key,
                    'name' => $name,
                ]);
            } else {
                $directory->update([
                    'parent_id' => null,
                    'key' => $key,
                    'name' => $name,
                ]);
            }
            $directories[$key] = $directory;
        }

        $batch = KnowledgeImportBatch::query()->create([
            'brand_id' => $brand->id,
            'site_id' => $site->id,
            'source_type' => 'governed_manifest',
            'status' => 'staged',
            'configuration_hash' => $package['configuration_hash'],
            'source_checksum' => $package['source_checksum'],
            'total_count' => count($package['manifest']) + count($package['duplicates']),
            'started_at' => now(),
            'rollback_metadata' => [
                'manifest_path' => $package['manifest_path'],
                'document_asset_keys' => array_column($package['manifest'], 'asset_key'),
            ],
        ]);

        $documentsByExternalId = [];
        $rowNumber = 0;
        foreach ($package['manifest'] as $record) {
            $rowNumber++;
            $document = KnowledgeBase::query()->where('asset_key', $record['asset_key'])->lockForUpdate()->first();
            if ($document !== null && (
                (int) $document->brand_id !== (int) $brand->id
                || (int) $document->site_id !== (int) $site->id
            )) {
                throw new RuntimeException('governed_import.existing_asset_ownership_invalid');
            }

            $payload = $this->documentPayload(
                $record,
                (int) $brand->id,
                (int) $site->id,
                (int) $directories[$record['directory_path']]->id,
                (int) $batch->id,
                $package['source_checksum'],
                $package['configuration_hash'],
            );
            if ($document === null) {
                $document = KnowledgeBase::query()->create($payload);
                $outcome = 'imported';
            } else {
                $document->fill($payload);
                $changed = $document->isDirty();
                $document->save();
                $outcome = $changed ? 'updated' : 'unchanged';
            }

            $this->keywordAssociations->associate(
                $document,
                $record['keyword_node_ids'],
                $record['keyword_cluster_ids'],
                $keywordLibraryId,
            );
            KnowledgeImportRow::query()->create([
                'brand_id' => $brand->id,
                'site_id' => $site->id,
                'batch_id' => $batch->id,
                'row_number' => $rowNumber,
                'staged_payload' => Arr::except($record, ['_content']),
                'outcome' => 'chunk_pending',
                'canonical_document_id' => $document->id,
                'rollback_metadata' => [
                    'asset_key' => $record['asset_key'],
                    'operation' => $outcome,
                ],
            ]);
            $documentsByExternalId[(string) $record['document_id']] = $document;
        }

        foreach ($package['duplicates'] as $record) {
            $rowNumber++;
            $targetExternalId = $record['duplicate_of_document_id'] ?? null;
            $target = is_string($targetExternalId)
                ? ($documentsByExternalId[$targetExternalId] ?? null)
                : null;
            $rollback = [
                'source_file_path' => $record['source_file_path'],
                'canonical_target_document_id' => $targetExternalId,
                'canonical_target_source_file_path' => $record['duplicate_of_source_file_path'] ?? null,
            ];
            $row = KnowledgeImportRow::query()->create([
                'brand_id' => $brand->id,
                'site_id' => $site->id,
                'batch_id' => $batch->id,
                'row_number' => $rowNumber,
                'staged_payload' => $record,
                'outcome' => 'duplicate',
                'canonical_document_id' => $target?->id,
                'duplicate_kind' => $record['kind'],
                'duplicate_score' => $record['similarity'] ?? null,
                'duplicate_reason' => $record['reason'],
                'rollback_metadata' => $rollback,
            ]);
            KnowledgeDuplicate::query()->create([
                'brand_id' => $brand->id,
                'site_id' => $site->id,
                'import_batch_id' => $batch->id,
                'import_row_id' => $row->id,
                'duplicate_document_id' => null,
                'canonical_document_id' => $target?->id,
                'duplicate_kind' => $record['kind'],
                'duplicate_score' => $record['similarity'] ?? null,
                'duplicate_reason' => $record['reason'],
                'rollback_metadata' => $rollback,
            ]);
        }

        $batch->update([
            'status' => 'dispatching',
            'processed_count' => $rowNumber,
            'imported_count' => count($package['manifest']),
            'duplicate_count' => count($package['duplicates']),
            'rejected_count' => 0,
            'error_log' => [],
            'completed_at' => null,
        ]);

        return (int) $batch->id;
    }

    /** @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function documentPayload(
        array $record,
        int $brandId,
        int $siteId,
        int $directoryId,
        int $batchId,
        string $sourceChecksum,
        string $configurationHash,
    ): array {
        $content = (string) $record['_content'];

        return [
            'asset_key' => $record['asset_key'],
            'name' => Str::limit((string) $record['title'], 100, ''),
            'description' => 'Governed knowledge imported from '.$record['source_file_path'],
            'content' => $content,
            'character_count' => mb_strlen($content, 'UTF-8'),
            'word_count' => count(preg_split('/\s+/u', trim(strip_tags($content)), -1, PREG_SPLIT_NO_EMPTY) ?: []),
            'file_type' => 'markdown',
            'file_path' => $record['cleaned_file_path'],
            'source_name' => Str::limit((string) $record['source_name'], 150, ''),
            'source_url' => $record['source_url'] ?: null,
            'source_type' => $record['source_type'],
            'business_line' => $record['directory_path'],
            'effective_date' => $record['effective_date'] ?: null,
            'risk_level' => $record['risk_level'],
            'review_status' => $record['review_status'],
            'asset_provenance' => [
                'namespace' => 'geoflow.governed-knowledge',
                'document_id' => $record['document_id'],
                'ingestion_method' => $record['ingestion_method'],
                'cleaned_file_hash' => $record['cleaned_file_hash'],
                'warnings' => $record['warnings'],
                'import_batch_id' => $batchId,
                'source_checksum' => $sourceChecksum,
                'configuration_hash' => $configurationHash,
            ],
            'brand_id' => $brandId,
            'site_id' => $siteId,
            'primary_directory_id' => $directoryId,
            'canonical_document_key' => $record['canonical_document_key'],
            'language_code' => $record['language_code'],
            'authority_level' => $record['authority_level'],
            'retrieved_at' => $record['retrieved_at'] ?: null,
            'review_due_at' => $record['review_due_at'] ?: null,
            'version_label' => $record['version_label'],
            'lifecycle_status' => $record['lifecycle_status'],
            'generation_eligible' => $record['generation_eligible'],
            'citation_required' => $record['citation_required'],
            'normalized_content_hash' => $record['normalized_content_hash'],
            'source_file_path' => $record['source_file_path'],
            'source_file_hash' => $record['source_file_hash'],
        ];
    }

    /** @return array{status:string,batch_id:int,imported_count:int,duplicate_count:int} */
    private function dispatchBatch(int $batchId): array
    {
        $batch = KnowledgeImportBatch::query()->findOrFail($batchId);
        $batch->update([
            'status' => 'dispatching',
            'completed_at' => null,
        ]);
        $rows = KnowledgeImportRow::query()
            ->where('batch_id', $batchId)
            ->whereIn('outcome', ['chunk_pending', 'chunk_failed'])
            ->whereNotNull('canonical_document_id')
            ->orderBy('row_number')
            ->get();

        foreach ($rows as $row) {
            try {
                $this->chunkSyncCoordinator->request((int) $row->canonical_document_id);
                $row->update([
                    'outcome' => 'chunk_dispatched',
                    'error_message' => null,
                ]);
            } catch (Throwable $exception) {
                $message = mb_substr($exception->getMessage(), 0, 2000, 'UTF-8');
                $row->update([
                    'outcome' => 'chunk_failed',
                    'error_message' => $message,
                ]);
                $errors = (array) $batch->error_log;
                $errors[] = [
                    'row_id' => (int) $row->id,
                    'document_id' => (int) $row->canonical_document_id,
                    'message' => $message,
                    'failed_at' => now()->toAtomString(),
                ];
                $batch->update([
                    'status' => 'failed',
                    'error_log' => $errors,
                    'completed_at' => null,
                ]);

                throw $exception;
            }
        }

        $outstanding = KnowledgeImportRow::query()
            ->where('batch_id', $batchId)
            ->whereIn('outcome', ['chunk_pending', 'chunk_failed'])
            ->exists();
        if ($outstanding) {
            throw new RuntimeException('governed_import.chunk_dispatch_incomplete');
        }

        $batch->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        return [
            'status' => 'completed',
            'batch_id' => (int) $batch->id,
            'imported_count' => (int) $batch->imported_count,
            'duplicate_count' => (int) $batch->duplicate_count,
        ];
    }

    private function batchForPackage(string $configurationHash, string $sourceChecksum): ?KnowledgeImportBatch
    {
        return KnowledgeImportBatch::query()
            ->where('configuration_hash', $configurationHash)
            ->where('source_checksum', $sourceChecksum)
            ->whereHas('brand', fn ($query) => $query->where('key', KnowledgeImportValidator::BRAND_KEY))
            ->whereHas('site', fn ($query) => $query->where('key', KnowledgeImportValidator::SITE_KEY))
            ->first();
    }
}
