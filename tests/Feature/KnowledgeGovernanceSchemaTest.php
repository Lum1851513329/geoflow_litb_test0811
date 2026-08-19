<?php

namespace Tests\Feature;

use App\Models\KnowledgeBase;
use App\Models\KnowledgeDuplicate;
use App\Models\KnowledgeImportBatch;
use App\Models\KnowledgeImportRow;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class KnowledgeGovernanceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_documents_cannot_share_a_normalized_hash_within_a_brand_and_language(): void
    {
        [$brandId, $siteId, $directoryId] = $this->ownershipIds();

        $this->createDocument($brandId, $siteId, $directoryId, 'pt', 'same-normalized-hash');
        $this->createDocument($brandId, $siteId, $directoryId, 'en', 'same-normalized-hash');

        $this->expectException(QueryException::class);

        $this->createDocument($brandId, $siteId, $directoryId, 'pt', 'same-normalized-hash');
    }

    public function test_superseded_version_can_coexist_with_an_active_version_of_the_same_normalized_content(): void
    {
        [$brandId, $siteId, $directoryId] = $this->ownershipIds();

        $this->createDocument($brandId, $siteId, $directoryId, 'en', 'same-normalized-hash', 'active');
        $this->createDocument($brandId, $siteId, $directoryId, 'en', 'same-normalized-hash', 'superseded');

        $this->assertSame(2, KnowledgeBase::query()
            ->where('brand_id', $brandId)
            ->where('language_code', 'en')
            ->where('normalized_content_hash', 'same-normalized-hash')
            ->count());
    }

    public function test_duplicate_audit_rows_remain_after_a_discarded_document_is_deleted(): void
    {
        [$brandId, $siteId, $directoryId] = $this->ownershipIds();
        $canonicalDocument = $this->createDocument($brandId, $siteId, $directoryId, 'pt', 'canonical-content');
        $duplicateDocument = $this->createDocument($brandId, $siteId, $directoryId, 'pt', 'duplicate-content');
        $batch = KnowledgeImportBatch::query()->create([
            'brand_id' => $brandId,
            'site_id' => $siteId,
            'source_type' => 'folder',
            'status' => 'completed',
            'configuration_hash' => str_repeat('a', 64),
            'source_checksum' => str_repeat('b', 64),
        ]);
        $row = KnowledgeImportRow::query()->create([
            'brand_id' => $brandId,
            'site_id' => $siteId,
            'batch_id' => $batch->id,
            'row_number' => 1,
            'staged_payload' => ['source_file' => 'company.txt'],
            'outcome' => 'duplicate',
            'canonical_document_id' => $canonicalDocument->id,
            'duplicate_kind' => 'exact',
            'duplicate_score' => 1,
            'duplicate_reason' => 'Normalized content hash matches the canonical document.',
            'rollback_metadata' => ['discarded_document_id' => $duplicateDocument->id],
        ]);
        $audit = KnowledgeDuplicate::query()->create([
            'brand_id' => $brandId,
            'site_id' => $siteId,
            'import_batch_id' => $batch->id,
            'duplicate_document_id' => $duplicateDocument->id,
            'canonical_document_id' => $canonicalDocument->id,
            'duplicate_kind' => 'exact',
            'duplicate_score' => 1,
            'duplicate_reason' => 'Markdown source takes precedence over its TXT mirror.',
            'rollback_metadata' => ['import_row_id' => $row->id],
        ]);

        $duplicateDocument->delete();

        $this->assertDatabaseHas('knowledge_duplicates', [
            'id' => $audit->id,
            'canonical_document_id' => $canonicalDocument->id,
            'duplicate_document_id' => null,
        ]);
        $this->assertSame(['source_file' => 'company.txt'], $row->refresh()->staged_payload);
        $this->assertSame($canonicalDocument->id, $row->canonicalDocument->id);
    }

    public function test_import_row_rejects_a_canonical_document_from_another_brand(): void
    {
        $scope = $this->scopedOwnership();
        $batch = $this->createBatch($scope['brand_a'], $scope['site_a']);
        $otherBrandDocument = $this->createDocument(
            $scope['brand_b'],
            $scope['site_b'],
            $scope['directory_b'],
            'pt',
            'other-brand-content',
        );

        $this->expectException(QueryException::class);

        KnowledgeImportRow::query()->create([
            'brand_id' => $scope['brand_a'],
            'site_id' => $scope['site_a'],
            'batch_id' => $batch->id,
            'row_number' => 1,
            'staged_payload' => ['source_file' => 'other-brand.md'],
            'canonical_document_id' => $otherBrandDocument->id,
        ]);
    }

    public function test_import_row_rejects_a_canonical_document_from_another_site(): void
    {
        $scope = $this->scopedOwnership();
        $batch = $this->createBatch($scope['brand_a'], $scope['site_a']);
        $otherSiteDocument = $this->createDocument(
            $scope['brand_a'],
            $scope['site_a_secondary'],
            $scope['directory_a'],
            'pt',
            'other-site-content',
        );

        $this->expectException(QueryException::class);

        KnowledgeImportRow::query()->create([
            'brand_id' => $scope['brand_a'],
            'site_id' => $scope['site_a'],
            'batch_id' => $batch->id,
            'row_number' => 1,
            'staged_payload' => ['source_file' => 'other-site.md'],
            'canonical_document_id' => $otherSiteDocument->id,
        ]);
    }

    public function test_duplicate_audit_rejects_a_document_from_another_brand_or_site(): void
    {
        $scope = $this->scopedOwnership();
        $batch = $this->createBatch($scope['brand_a'], $scope['site_a']);
        $canonicalDocument = $this->createDocument(
            $scope['brand_a'],
            $scope['site_a'],
            $scope['directory_a'],
            'pt',
            'canonical-content',
        );
        $otherBrandDocument = $this->createDocument(
            $scope['brand_b'],
            $scope['site_b'],
            $scope['directory_b'],
            'pt',
            'other-brand-content',
        );
        $otherSiteDocument = $this->createDocument(
            $scope['brand_a'],
            $scope['site_a_secondary'],
            $scope['directory_a'],
            'pt',
            'other-site-content',
        );

        $this->assertDuplicateAuditDocumentScopeRejected($batch, $canonicalDocument, $otherBrandDocument);
        $this->assertDuplicateAuditDocumentScopeRejected($batch, $canonicalDocument, $otherSiteDocument);
    }

    public function test_duplicate_audit_rejects_an_import_row_from_another_batch(): void
    {
        $scope = $this->scopedOwnership();
        $firstBatch = $this->createBatch($scope['brand_a'], $scope['site_a']);
        $secondBatch = $this->createBatch($scope['brand_a'], $scope['site_a']);
        $row = KnowledgeImportRow::query()->create([
            'brand_id' => $scope['brand_a'],
            'site_id' => $scope['site_a'],
            'batch_id' => $secondBatch->id,
            'row_number' => 1,
            'staged_payload' => ['source_file' => 'second-batch.md'],
        ]);

        $this->expectException(QueryException::class);

        KnowledgeDuplicate::query()->create([
            'brand_id' => $scope['brand_a'],
            'site_id' => $scope['site_a'],
            'import_batch_id' => $firstBatch->id,
            'import_row_id' => $row->id,
            'duplicate_kind' => 'exact',
        ]);
    }

    public function test_governance_and_import_audit_schema_expose_the_staged_document_contract(): void
    {
        $this->assertTrue(Schema::hasColumns('knowledge_bases', [
            'canonical_document_key',
            'language_code',
            'authority_level',
            'retrieved_at',
            'review_due_at',
            'version_label',
            'lifecycle_status',
            'generation_eligible',
            'citation_required',
            'normalized_content_hash',
            'source_file_path',
            'source_file_hash',
        ]));
        $this->assertTrue(Schema::hasColumns('knowledge_import_batches', [
            'brand_id',
            'site_id',
            'configuration_hash',
            'source_checksum',
            'rollback_metadata',
        ]));
        $this->assertTrue(Schema::hasColumns('knowledge_import_rows', [
            'brand_id',
            'site_id',
            'staged_payload',
            'outcome',
            'canonical_document_id',
            'duplicate_kind',
            'duplicate_score',
            'duplicate_reason',
            'rollback_metadata',
        ]));
        $this->assertTrue(Schema::hasColumns('knowledge_duplicates', [
            'brand_id',
            'site_id',
            'duplicate_document_id',
            'canonical_document_id',
            'duplicate_kind',
            'duplicate_score',
            'duplicate_reason',
            'rollback_metadata',
        ]));
    }

    /**
     * @return array{int, int, int}
     */
    private function ownershipIds(): array
    {
        $now = now();
        $brandId = DB::table('knowledge_brands')->insertGetId([
            'key' => 'lightinthebox',
            'name' => 'LightInTheBox',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $siteId = DB::table('knowledge_sites')->insertGetId([
            'brand_id' => $brandId,
            'key' => 'lightinthebox-main',
            'canonical_host' => 'www.lightinthebox.com',
            'role' => 'commerce-primary',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $directoryId = DB::table('knowledge_directories')->insertGetId([
            'brand_id' => $brandId,
            'key' => 'brand-company',
            'path' => 'brand-company',
            'name' => 'Brand company',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$brandId, $siteId, $directoryId];
    }

    private function createDocument(
        int $brandId,
        int $siteId,
        int $directoryId,
        string $languageCode,
        string $normalizedContentHash,
        string $lifecycleStatus = 'active',
    ): KnowledgeBase {
        return KnowledgeBase::query()->create([
            'name' => 'Governed document '.str()->random(8),
            'content' => 'Governed knowledge content.',
            'brand_id' => $brandId,
            'site_id' => $siteId,
            'primary_directory_id' => $directoryId,
            'language_code' => $languageCode,
            'normalized_content_hash' => $normalizedContentHash,
            'lifecycle_status' => $lifecycleStatus,
        ]);
    }

    private function createBatch(int $brandId, int $siteId): KnowledgeImportBatch
    {
        return KnowledgeImportBatch::query()->create([
            'brand_id' => $brandId,
            'site_id' => $siteId,
            'source_type' => 'folder',
            'status' => 'completed',
            'configuration_hash' => str()->random(64),
            'source_checksum' => str()->random(64),
        ]);
    }

    private function assertDuplicateAuditDocumentScopeRejected(
        KnowledgeImportBatch $batch,
        KnowledgeBase $canonicalDocument,
        KnowledgeBase $duplicateDocument,
    ): void {
        try {
            KnowledgeDuplicate::query()->create([
                'brand_id' => $batch->brand_id,
                'site_id' => $batch->site_id,
                'import_batch_id' => $batch->id,
                'duplicate_document_id' => $duplicateDocument->id,
                'canonical_document_id' => $canonicalDocument->id,
                'duplicate_kind' => 'exact',
            ]);
            $this->fail('Expected a duplicate audit document outside the batch scope to be rejected.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @return array{brand_a: int, site_a: int, site_a_secondary: int, directory_a: int, brand_b: int, site_b: int, directory_b: int}
     */
    private function scopedOwnership(): array
    {
        $now = now();
        $brandA = DB::table('knowledge_brands')->insertGetId([
            'key' => 'brand-a',
            'name' => 'Brand A',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $brandB = DB::table('knowledge_brands')->insertGetId([
            'key' => 'brand-b',
            'name' => 'Brand B',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $siteA = $this->createSite($brandA, 'brand-a-main', $now);
        $siteASecondary = $this->createSite($brandA, 'brand-a-secondary', $now);
        $siteB = $this->createSite($brandB, 'brand-b-main', $now);
        $directoryA = $this->createDirectory($brandA, 'brand-a-directory', $now);
        $directoryB = $this->createDirectory($brandB, 'brand-b-directory', $now);

        return [
            'brand_a' => $brandA,
            'site_a' => $siteA,
            'site_a_secondary' => $siteASecondary,
            'directory_a' => $directoryA,
            'brand_b' => $brandB,
            'site_b' => $siteB,
            'directory_b' => $directoryB,
        ];
    }

    private function createSite(int $brandId, string $key, \DateTimeInterface $now): int
    {
        return DB::table('knowledge_sites')->insertGetId([
            'brand_id' => $brandId,
            'key' => $key,
            'canonical_host' => $key.'.test',
            'role' => 'commerce-primary',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createDirectory(int $brandId, string $key, \DateTimeInterface $now): int
    {
        return DB::table('knowledge_directories')->insertGetId([
            'brand_id' => $brandId,
            'key' => $key,
            'path' => $key,
            'name' => str($key)->replace('-', ' ')->title(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
