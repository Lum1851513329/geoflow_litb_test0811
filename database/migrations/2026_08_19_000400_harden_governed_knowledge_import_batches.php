<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_NAME = 'knowledge_import_batches_scope_config_source_unique';

    private const CLAIM_INDEX = 'knowledge_import_rows_dispatch_claim_index';

    public function up(): void
    {
        Schema::table('knowledge_import_rows', function (Blueprint $table): void {
            $table->string('dispatch_claim_token', 36)->nullable();
            $table->timestamp('dispatch_claimed_at')->nullable();
            $table->unsignedInteger('dispatch_attempt_count')->default(0);
            $table->index(['outcome', 'dispatch_claimed_at'], self::CLAIM_INDEX);
        });

        $this->reconcileDuplicateBatchIdentities();

        Schema::table('knowledge_import_batches', function (Blueprint $table): void {
            $table->unique(
                ['brand_id', 'site_id', 'configuration_hash', 'source_checksum'],
                self::UNIQUE_NAME,
            );
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_import_batches', function (Blueprint $table): void {
            $table->dropUnique(self::UNIQUE_NAME);
        });
        Schema::table('knowledge_import_rows', function (Blueprint $table): void {
            $table->dropIndex(self::CLAIM_INDEX);
            $table->dropColumn([
                'dispatch_claim_token',
                'dispatch_claimed_at',
                'dispatch_attempt_count',
            ]);
        });
    }

    private function reconcileDuplicateBatchIdentities(): void
    {
        $groups = DB::table('knowledge_import_batches')
            ->select(['brand_id', 'site_id', 'configuration_hash', 'source_checksum'])
            ->groupBy(['brand_id', 'site_id', 'configuration_hash', 'source_checksum'])
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('brand_id')
            ->orderBy('site_id')
            ->orderBy('configuration_hash')
            ->orderBy('source_checksum')
            ->get();

        foreach ($groups as $group) {
            $batches = DB::table('knowledge_import_batches')
                ->where('brand_id', $group->brand_id)
                ->where('site_id', $group->site_id)
                ->where('configuration_hash', $group->configuration_hash)
                ->where('source_checksum', $group->source_checksum)
                ->orderBy('id')
                ->get(['id', 'rollback_metadata']);
            $canonicalBatchId = (int) $batches->first()->id;

            foreach ($batches->slice(1) as $batch) {
                $configurationHash = $this->reconciledConfigurationHash(
                    (int) $group->brand_id,
                    (int) $group->site_id,
                    (string) $group->configuration_hash,
                    (string) $group->source_checksum,
                    (int) $batch->id,
                );
                $metadata = $batch->rollback_metadata === null
                    ? []
                    : json_decode((string) $batch->rollback_metadata, true, 512, JSON_THROW_ON_ERROR);
                $metadata['identity_reconciliation'] = [
                    'reason' => 'legacy_duplicate_batch_tuple',
                    'canonical_batch_id' => $canonicalBatchId,
                    'original_configuration_hash' => (string) $group->configuration_hash,
                    'original_source_checksum' => (string) $group->source_checksum,
                ];
                DB::table('knowledge_import_batches')
                    ->where('id', $batch->id)
                    ->update([
                        'configuration_hash' => $configurationHash,
                        'rollback_metadata' => json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    ]);
            }
        }
    }

    private function reconciledConfigurationHash(
        int $brandId,
        int $siteId,
        string $configurationHash,
        string $sourceChecksum,
        int $batchId,
    ): string {
        $nonce = 0;
        do {
            $candidate = hash('sha256', implode("\0", [
                'legacy-duplicate-batch',
                $brandId,
                $siteId,
                $configurationHash,
                $sourceChecksum,
                $batchId,
                $nonce,
            ]));
            $nonce++;
        } while (DB::table('knowledge_import_batches')
            ->where('brand_id', $brandId)
            ->where('site_id', $siteId)
            ->where('configuration_hash', $candidate)
            ->where('source_checksum', $sourceChecksum)
            ->exists());

        return $candidate;
    }
};
