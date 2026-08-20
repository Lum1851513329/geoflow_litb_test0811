<?php

namespace App\Services\GeoFlow\KnowledgeGovernance;

use App\Models\KnowledgeBase;
use App\Models\KnowledgeImportBatch;
use App\Models\KnowledgeImportRow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Migrates references to legacy (pre-governance, all-null-ownership) knowledge_bases rows
 * onto their canonical governed replacement once a formal import batch has produced one, then
 * removes the now-unreferenced legacy duplicate. See design doc §13/§15: task/article references
 * must remain valid, and referenced rows that cannot be safely reassigned are archived, not deleted.
 */
final class LegacyKnowledgeMigrationService
{
    /**
     * Tables excluded from reassignment by deliberate judgment call (see task-10 report):
     * generated/transient content owned by the document (cascade-deletes harmlessly with it) or
     * import/dedup audit trail already governed by set-null FKs that must be preserved untouched
     * per design doc §15 ("Preserve source and migration audit rows after rollback or deletion").
     *
     * @var list<string>
     */
    private const IGNORED_REFERENCE_TABLES = [
        'knowledge_chunks',
        'knowledge_chunk_sync_rows',
        'knowledge_duplicates',
        'knowledge_import_rows',
    ];

    /** @return array<string,mixed> */
    public function plan(int $batchId): array
    {
        return $this->buildReport($batchId, dryRun: true, backupVerified: false);
    }

    /** @return array<string,mixed> */
    public function migrate(int $batchId, bool $backupVerified): array
    {
        if (! $backupVerified) {
            throw new RuntimeException('legacy_migration.backup_not_verified');
        }

        return DB::transaction(fn (): array => $this->buildReport($batchId, dryRun: false, backupVerified: true));
    }

    /** @return array<string,mixed> */
    private function buildReport(int $batchId, bool $dryRun, bool $backupVerified): array
    {
        if (! KnowledgeImportBatch::query()->whereKey($batchId)->exists()) {
            throw new RuntimeException('legacy_migration.batch_not_found');
        }

        $referenceColumns = $this->discoverReferenceColumns();
        [$mapped, $unmapped] = $this->matchLegacyRows($batchId);

        $mappingReports = [];
        foreach ($mapped as $mapping) {
            $legacyId = $mapping['legacy_knowledge_base_id'];
            $before = $this->referenceCounts($legacyId, $referenceColumns);

            if ($dryRun) {
                $mappingReports[] = [
                    ...$mapping,
                    'reference_counts' => $before,
                    'action' => 'would_reassign_and_delete',
                    'reassignment_error' => null,
                ];

                continue;
            }

            $mappingReports[] = $this->applyMapping($mapping, $referenceColumns, $before);
        }

        if (! $dryRun) {
            $this->persistRollbackMapping($batchId, $mappingReports, $unmapped);
        }

        return [
            'status' => $dryRun ? 'dry_run' : 'committed',
            'backup_verified' => $backupVerified,
            'batch_id' => $batchId,
            'generated_at' => now()->toAtomString(),
            'reference_tables' => $referenceColumns,
            'ignored_reference_tables' => $this->ignoredReferenceColumns(),
            'mapped' => $mappingReports,
            'unmapped' => $unmapped,
            'summary' => [
                'mapped_count' => count($mappingReports),
                'unmapped_count' => count($unmapped),
                'deleted_count' => count(array_filter($mappingReports, static fn (array $m): bool => $m['action'] === 'deleted')),
                'archived_count' => count(array_filter($mappingReports, static fn (array $m): bool => $m['action'] === 'archived')),
            ],
        ];
    }

    /**
     * Matches legacy (all-null-ownership) knowledge_bases rows against the batch's import rows
     * by source identity. Driven from the import rows (not from scanning every legacy row), since
     * the join only makes sense in relation to this batch: for each import row's staged source
     * file path, look for a legacy row at that same path, then require its source_file_hash to
     * corroborate content identity before treating it as a confident match. Anything short of that
     * confidence is left untouched and reported as unmapped, never guessed.
     *
     * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>}
     */
    private function matchLegacyRows(int $batchId): array
    {
        $importRows = KnowledgeImportRow::query()
            ->where('batch_id', $batchId)
            ->whereNotNull('canonical_document_id')
            ->get(['id', 'canonical_document_id', 'staged_payload']);

        $mapped = [];
        $unmapped = [];
        $seenLegacyIds = [];

        foreach ($importRows as $row) {
            $payload = (array) $row->staged_payload;
            $sourcePath = $payload['source_file_path'] ?? null;
            if (! is_string($sourcePath) || $sourcePath === '') {
                continue;
            }

            $legacyCandidates = KnowledgeBase::query()
                ->whereNull('brand_id')
                ->whereNull('site_id')
                ->whereNull('primary_directory_id')
                ->where('source_file_path', $sourcePath)
                ->get(['id', 'source_file_hash']);

            if ($legacyCandidates->isEmpty()) {
                continue;
            }

            if ($legacyCandidates->count() > 1) {
                foreach ($legacyCandidates as $candidate) {
                    $legacyId = (int) $candidate->id;
                    if (isset($seenLegacyIds[$legacyId])) {
                        continue;
                    }
                    $seenLegacyIds[$legacyId] = true;
                    $unmapped[] = [
                        'legacy_knowledge_base_id' => $legacyId,
                        'reason' => 'multiple_legacy_rows_share_source_file_path',
                    ];
                }

                continue;
            }

            $legacy = $legacyCandidates->first();
            $legacyId = (int) $legacy->id;
            if (isset($seenLegacyIds[$legacyId])) {
                continue;
            }

            $sourceHash = $payload['source_file_hash'] ?? null;
            if (! is_string($sourceHash) || $sourceHash === '' || $legacy->source_file_hash === null) {
                $seenLegacyIds[$legacyId] = true;
                $unmapped[] = [
                    'legacy_knowledge_base_id' => $legacyId,
                    'reason' => 'legacy_row_missing_source_file_hash',
                ];

                continue;
            }

            if ($legacy->source_file_hash !== $sourceHash) {
                $seenLegacyIds[$legacyId] = true;
                $unmapped[] = [
                    'legacy_knowledge_base_id' => $legacyId,
                    'reason' => 'content_hash_mismatch_despite_path_match',
                ];

                continue;
            }

            $seenLegacyIds[$legacyId] = true;
            $mapped[] = [
                'legacy_knowledge_base_id' => $legacyId,
                'canonical_knowledge_base_id' => (int) $row->canonical_document_id,
                'matched_on' => 'source_file_path+source_file_hash',
            ];
        }

        return [$mapped, $unmapped];
    }

    /**
     * Reassigns every discovered reference for one legacy row inside its own nested transaction
     * (a savepoint, since we're already inside the outer commit transaction). If any table's
     * reassignment throws, the savepoint rolls back that row's partial changes and the row is
     * archived instead of deleted, leaving both the row and its references exactly as they were.
     *
     * @param  array<string,mixed>  $mapping
     * @param  list<array{table:string,column:string,unique_other_columns:?list<string>}>  $referenceColumns
     * @param  array<string,int>  $before
     * @return array<string,mixed>
     */
    private function applyMapping(array $mapping, array $referenceColumns, array $before): array
    {
        $legacyId = $mapping['legacy_knowledge_base_id'];
        $canonicalId = $mapping['canonical_knowledge_base_id'];
        $error = null;

        try {
            DB::transaction(function () use ($legacyId, $canonicalId, $referenceColumns): void {
                foreach ($referenceColumns as $reference) {
                    $this->reassignColumn($reference, $legacyId, $canonicalId);
                }
            });
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }

        if ($error === null) {
            $after = $this->referenceCounts($legacyId, $referenceColumns);
            if (array_sum($after) > 0) {
                $error = 'residual_references_after_reassignment';
            }
        }

        if ($error !== null) {
            $this->archiveLegacyRow($legacyId);

            return [
                ...$mapping,
                'reference_counts' => $before,
                'action' => 'archived',
                'reassignment_error' => $error,
            ];
        }

        $this->deleteLegacyRow($legacyId);

        return [
            ...$mapping,
            'reference_counts' => $before,
            'action' => 'deleted',
            'reassignment_error' => null,
        ];
    }

    /** @param array{table:string,column:string,unique_other_columns:?list<string>} $reference */
    private function reassignColumn(array $reference, int $legacyId, int $canonicalId): void
    {
        $table = $reference['table'];
        $column = $reference['column'];
        $otherColumns = $reference['unique_other_columns'];

        if ($otherColumns === null) {
            DB::table($table)->where($column, $legacyId)->update([$column => $canonicalId]);

            return;
        }

        foreach (DB::table($table)->where($column, $legacyId)->get() as $row) {
            $existsForCanonical = DB::table($table)->where($column, $canonicalId);
            foreach ($otherColumns as $otherColumn) {
                $existsForCanonical->where($otherColumn, $row->{$otherColumn});
            }

            if ($existsForCanonical->exists()) {
                // A row for the canonical document already occupies this unique slot; the legacy
                // row is a stale duplicate reference, so it is removed rather than reassigned,
                // preserving pivot/association uniqueness instead of violating it.
                DB::table($table)->where('id', $row->id)->delete();
            } else {
                DB::table($table)->where('id', $row->id)->update([$column => $canonicalId]);
            }
        }
    }

    /**
     * @param  list<array{table:string,column:string,unique_other_columns:?list<string>}>  $columns
     * @return array<string,int>
     */
    private function referenceCounts(int $legacyId, array $columns): array
    {
        $counts = [];
        foreach ($columns as $reference) {
            $counts[$reference['table'].'.'.$reference['column']] = (int) DB::table($reference['table'])
                ->where($reference['column'], $legacyId)
                ->count();
        }

        return $counts;
    }

    private function archiveLegacyRow(int $legacyId): void
    {
        KnowledgeBase::query()->whereKey($legacyId)->update(['lifecycle_status' => 'archived']);
    }

    private function deleteLegacyRow(int $legacyId): void
    {
        KnowledgeBase::query()->whereKey($legacyId)->delete();
    }

    /**
     * Discovers every single-column foreign key pointing at knowledge_bases(id) via live schema
     * introspection (Schema::getForeignKeys() over Schema::getTables()), so a future table that
     * adds such a reference is covered automatically without a code change here. This intentionally
     * excludes composite ownership-tuple foreign keys (brand_id, site_id, id) and the tables in
     * self::IGNORED_REFERENCE_TABLES.
     *
     * tasks.knowledge_base_id is added explicitly because the production PostgreSQL schema
     * (database/migrations/2026_04_18_120000_geoflow_legacy_schema.php, raw SQL) never declared a
     * real foreign key constraint for it, so schema introspection alone would miss it there (the
     * sqlite test schema does declare it as a real constraint, so tests would find it either way).
     *
     * @return list<array{table:string,column:string,unique_other_columns:?list<string>}>
     */
    private function discoverReferenceColumns(): array
    {
        $discovered = [];
        foreach (Schema::getTables() as $tableInfo) {
            $table = $tableInfo['name'];
            if ($table === 'knowledge_bases' || in_array($table, self::IGNORED_REFERENCE_TABLES, true)) {
                continue;
            }
            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                if (($foreignKey['foreign_table'] ?? null) !== 'knowledge_bases' || count($foreignKey['columns']) !== 1) {
                    continue;
                }
                $column = $foreignKey['columns'][0];
                $discovered[$table.'.'.$column] = $this->describeColumn($table, $column);
            }
        }

        $discovered['tasks.knowledge_base_id'] ??= $this->describeColumn('tasks', 'knowledge_base_id');

        return array_values($discovered);
    }

    /**
     * The tables in self::IGNORED_REFERENCE_TABLES are surfaced here purely for dry-run
     * transparency; they are never reassigned and never block delete-vs-archive decisions.
     *
     * @return list<array{table:string,column:string}>
     */
    private function ignoredReferenceColumns(): array
    {
        $discovered = [];
        foreach (self::IGNORED_REFERENCE_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                if (($foreignKey['foreign_table'] ?? null) !== 'knowledge_bases' || count($foreignKey['columns']) !== 1) {
                    continue;
                }
                $discovered[] = ['table' => $table, 'column' => $foreignKey['columns'][0]];
            }
        }

        return $discovered;
    }

    /** @return array{table:string,column:string,unique_other_columns:?list<string>} */
    private function describeColumn(string $table, string $column): array
    {
        $otherColumns = null;
        if (Schema::hasTable($table)) {
            foreach (Schema::getIndexes($table) as $index) {
                if (! ($index['unique'] ?? false) || ($index['primary'] ?? false)) {
                    continue;
                }
                if (! in_array($column, $index['columns'], true)) {
                    continue;
                }
                $otherColumns = array_values(array_diff($index['columns'], [$column]));
                break;
            }
        }

        return ['table' => $table, 'column' => $column, 'unique_other_columns' => $otherColumns];
    }

    private function persistRollbackMapping(int $batchId, array $mappingReports, array $unmapped): void
    {
        $batch = KnowledgeImportBatch::query()->whereKey($batchId)->lockForUpdate()->first();
        if ($batch === null) {
            return;
        }

        $metadata = (array) $batch->rollback_metadata;
        $metadata['legacy_reference_migration'] = [
            'migrated_at' => now()->toAtomString(),
            'mapped' => $mappingReports,
            'unmapped' => $unmapped,
        ];
        $batch->update(['rollback_metadata' => $metadata]);
    }
}
