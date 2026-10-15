<?php

namespace Tests\Feature;

use App\Jobs\PrepareKnowledgeChunkSyncJob;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeBrand;
use App\Models\KnowledgeDirectory;
use App\Models\KnowledgeSite;
use App\Models\Task;
use App\Services\GeoFlow\KnowledgeChunkSyncCoordinator;
use App\Services\GeoFlow\KnowledgeGovernance\GovernedKnowledgeImporter;
use App\Services\GeoFlow\KnowledgeGovernance\KnowledgeImportValidator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class ImportGovernedKnowledgeCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private int $keywordNodeId;

    private int $keywordClusterId;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->root = storage_path('framework/testing/governed-import-'.uniqid('', true));
        File::ensureDirectoryExists($this->root);
        DB::table('keyword_libraries')->insert([
            'id' => 42,
            'name' => 'LightInTheBox governed keywords',
            'description' => '',
            'keyword_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $taxonomyId = DB::table('keyword_taxonomies')->insertGetId([
            'name' => 'Governed product taxonomy',
            'slug' => 'governed-product-taxonomy',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $keywordId = DB::table('keywords')->insertGetId([
            'library_id' => 42,
            'keyword' => 'return policy',
            'normalized_keyword' => 'return policy',
            'language_code' => 'en',
            'market_scope' => 'GLOBAL',
            'keyword_type' => 'policy',
            'search_intent' => 'informational',
            'brand_relation' => 'brand',
            'status' => 'active',
            'used_count' => 0,
            'usage_count' => 0,
            'created_at' => now(),
        ]);
        $this->keywordNodeId = DB::table('keyword_nodes')->insertGetId([
            'taxonomy_id' => $taxonomyId,
            'name' => 'Returns',
            'slug' => 'returns',
            'path' => 'returns',
            'depth' => 0,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('keyword_node_keyword')->insert([
            'node_id' => $this->keywordNodeId,
            'keyword_id' => $keywordId,
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->keywordClusterId = DB::table('keyword_clusters')->insertGetId([
            'name' => 'Return policy',
            'slug' => 'return-policy',
            'taxonomy_node_id' => $this->keywordNodeId,
            'primary_keyword_id' => $keywordId,
            'language_code' => 'en',
            'market_scope' => 'GLOBAL',
            'search_intent' => 'informational',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_dry_run_validates_the_complete_package_without_writing_or_dispatching(): void
    {
        $manifest = $this->writePackage();

        $this->artisan('geoflow:knowledge:import-governed', [
            'manifest' => $manifest,
            '--dry-run' => true,
            '--keyword-library' => 42,
        ])->assertSuccessful();

        $this->assertDatabaseCount('knowledge_brands', 0);
        $this->assertDatabaseCount('knowledge_sites', 0);
        $this->assertDatabaseCount('knowledge_directories', 0);
        $this->assertDatabaseCount('knowledge_bases', 0);
        $this->assertDatabaseCount('knowledge_import_batches', 0);
        $this->assertDatabaseCount('knowledge_import_rows', 0);
        $this->assertDatabaseCount('knowledge_duplicates', 0);
        Queue::assertNothingPushed();
    }

    public function test_first_import_creates_governed_rows_duplicate_audits_and_after_commit_chunk_jobs(): void
    {
        $manifest = $this->writePackage();

        $this->artisan('geoflow:knowledge:import-governed', [
            'manifest' => $manifest,
            '--keyword-library' => 42,
        ])->assertSuccessful();

        $brand = DB::table('knowledge_brands')->sole();
        $site = DB::table('knowledge_sites')->sole();
        $document = DB::table('knowledge_bases')->sole();
        $batch = DB::table('knowledge_import_batches')->sole();

        $this->assertSame('lightinthebox', $brand->key);
        $this->assertSame('lightinthebox-main', $site->key);
        $this->assertSame((int) $brand->id, (int) $site->brand_id);
        $this->assertDatabaseCount('knowledge_directories', 9);
        $this->assertSame((int) $brand->id, (int) $document->brand_id);
        $this->assertSame((int) $site->id, (int) $document->site_id);
        $this->assertSame('en', $document->language_code);
        $this->assertSame('active', $document->lifecycle_status);
        $this->assertSame("# Returns\n\nReturn eligible items within 30 days.\n", $document->content);
        $this->assertSame('completed', $batch->status);
        $this->assertSame(3, (int) $batch->total_count);
        $this->assertSame(1, (int) $batch->imported_count);
        $this->assertSame(2, (int) $batch->duplicate_count);
        $this->assertDatabaseCount('knowledge_import_rows', 3);
        $this->assertDatabaseCount('knowledge_duplicates', 2);
        $this->assertDatabaseHas('knowledge_keyword_nodes', [
            'knowledge_base_id' => $document->id,
            'keyword_node_id' => $this->keywordNodeId,
        ]);
        $this->assertDatabaseHas('knowledge_keyword_clusters', [
            'knowledge_base_id' => $document->id,
            'keyword_cluster_id' => $this->keywordClusterId,
        ]);
        $this->assertSame(
            [(int) $brand->id],
            DB::table('knowledge_import_rows')->pluck('brand_id')->unique()->values()->all(),
        );
        $this->assertSame(
            [(int) $site->id],
            DB::table('knowledge_import_rows')->pluck('site_id')->unique()->values()->all(),
        );
        $this->assertSame(
            [(int) $brand->id],
            DB::table('knowledge_duplicates')->pluck('brand_id')->unique()->values()->all(),
        );
        $this->assertSame(
            [(int) $site->id],
            DB::table('knowledge_duplicates')->pluck('site_id')->unique()->values()->all(),
        );
        $this->assertDatabaseHas('knowledge_duplicates', [
            'canonical_document_id' => $document->id,
            'duplicate_kind' => 'exact',
            'duplicate_reason' => 'markdown_over_txt',
        ]);
        $excludedTargetAudit = DB::table('knowledge_duplicates')
            ->whereNull('canonical_document_id')
            ->sole();
        $this->assertSame(
            $this->exclusionRecord()['document_id'],
            json_decode((string) $excludedTargetAudit->rollback_metadata, true, 512, JSON_THROW_ON_ERROR)['canonical_target_document_id'],
        );
        Queue::assertPushed(
            PrepareKnowledgeChunkSyncJob::class,
            fn (PrepareKnowledgeChunkSyncJob $job): bool => $job->knowledgeBaseId === (int) $document->id
                && $job->afterCommit === true
                && $job->queue === 'knowledge',
        );
    }

    public function test_rerunning_the_same_configuration_and_source_is_idempotent(): void
    {
        $manifest = $this->writePackage();

        $arguments = [
            'manifest' => $manifest,
            '--keyword-library' => 42,
        ];
        $this->artisan('geoflow:knowledge:import-governed', $arguments)->assertSuccessful();
        $this->artisan('geoflow:knowledge:import-governed', $arguments)->assertSuccessful();

        $this->assertDatabaseCount('knowledge_bases', 1);
        $this->assertDatabaseCount('knowledge_import_batches', 1);
        $this->assertDatabaseCount('knowledge_import_rows', 3);
        $this->assertDatabaseCount('knowledge_duplicates', 2);
        Queue::assertPushed(PrepareKnowledgeChunkSyncJob::class, 1);
    }

    public function test_formal_package_uses_the_builder_source_set_hash_for_batch_identity(): void
    {
        $manifest = $this->writePackage();
        $sourceSetHash = 'adef92e7e18acb5105878d6a03a5c23f5e711aafd7f0a515690815b3a080a86e';
        $this->assertSame($sourceSetHash, json_decode(
            File::get($this->root.'/run_summary.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        )['source_set_hash']);

        $this->artisan('geoflow:knowledge:import-governed', [
            'manifest' => $manifest,
            '--keyword-library' => 42,
        ])->assertSuccessful();

        $this->assertSame($sourceSetHash, DB::table('knowledge_import_batches')->sole()->source_checksum);
    }

    public function test_tampered_summary_source_set_hash_is_rejected_without_creating_a_new_batch(): void
    {
        $manifest = $this->writePackage();
        $arguments = [
            'manifest' => $manifest,
            '--keyword-library' => 42,
        ];
        $this->artisan('geoflow:knowledge:import-governed', $arguments)->assertSuccessful();
        $summary = json_decode(File::get($this->root.'/run_summary.json'), true, 512, JSON_THROW_ON_ERROR);
        $summary['source_set_hash'] = hash('sha256', 'tampered source mapping');
        File::put($this->root.'/run_summary.json', json_encode($summary, JSON_THROW_ON_ERROR));

        $this->artisan('geoflow:knowledge:import-governed', $arguments)->assertFailed();

        $this->assertDatabaseCount('knowledge_import_batches', 1);
        $this->assertDatabaseCount('knowledge_bases', 1);
        $this->assertDatabaseCount('knowledge_import_rows', 3);
    }

    public function test_invalid_ownership_in_a_later_manifest_row_leaves_the_whole_database_unchanged(): void
    {
        $records = [$this->documentRecord(), [
            ...$this->documentRecord(
                'url:https://www.lightinthebox.com/company',
                "# Company\n\nCompany information.\n",
                'brand-company',
            ),
            'brand_key' => 'another-brand',
        ]];
        $manifest = $this->writePackage($records, []);

        $this->artisan('geoflow:knowledge:import-governed', [
            'manifest' => $manifest,
            '--keyword-library' => 42,
        ])->assertFailed();

        $this->assertNoImportWrites();
    }

    public function test_missing_duplicate_target_rejects_the_package_before_any_write(): void
    {
        $duplicates = [[
            'kind' => 'exact',
            'source_file_path' => 'LightInTheBox_PT_Official_KB_TXT/missing.txt',
            'source_file_hash' => hash('sha256', 'missing source'),
            'normalized_content_hash' => hash('sha256', 'missing normalized'),
            'duplicate_of_source_file_path' => 'missing.md',
            'duplicate_of_document_id' => 'missing-document',
            'reason' => 'markdown_over_txt',
        ]];
        $manifest = $this->writePackage([$this->documentRecord()], $duplicates);

        $this->artisan('geoflow:knowledge:import-governed', [
            'manifest' => $manifest,
            '--keyword-library' => 42,
        ])->assertFailed();

        $this->assertNoImportWrites();
    }

    public function test_missing_keyword_target_rejects_the_package_before_any_write(): void
    {
        $record = [
            ...$this->documentRecord(),
            'keyword_node_ids' => [999999],
        ];
        $manifest = $this->writePackage([$record], []);

        $this->artisan('geoflow:knowledge:import-governed', [
            'manifest' => $manifest,
            '--keyword-library' => 42,
        ])->assertFailed();

        $this->assertNoImportWrites();
    }

    public function test_false_asset_identity_cannot_overwrite_a_task_referenced_document(): void
    {
        $existing = KnowledgeBase::query()->create([
            'asset_key' => 'knowledge:'.str_repeat('a', 32),
            'name' => 'Operator document',
            'content' => 'Operator-owned content.',
            'file_type' => 'markdown',
        ]);
        $task = Task::query()->create([
            'name' => 'Referenced task',
            'status' => 'paused',
            'knowledge_base_id' => $existing->id,
        ]);
        $record = $this->documentRecord();
        $record['document_id'] = str_repeat('a', 32);
        $record['asset_key'] = 'knowledge:'.$record['document_id'];
        $manifest = $this->writePackage([$record], []);

        $this->artisan('geoflow:knowledge:import-governed', [
            'manifest' => $manifest,
            '--keyword-library' => 42,
        ])->assertFailed();

        $this->assertSame('Operator-owned content.', $existing->fresh()->content);
        $this->assertSame((int) $existing->id, (int) $task->fresh()->knowledge_base_id);
        $this->assertDatabaseCount('knowledge_import_batches', 0);
    }

    public function test_front_matter_must_match_every_governed_manifest_field(): void
    {
        $manifest = $this->writePackage();
        $cleanedPath = File::glob($this->root.'/cleaned/policy-compliance/*.md')[0];
        $cleaned = str_replace('title: "Returns"', 'title: "Forged Returns"', File::get($cleanedPath));
        File::put($cleanedPath, $cleaned);
        $record = $this->readJsonLines($manifest)[0];
        $record['cleaned_file_hash'] = hash('sha256', $cleaned);
        $this->writeJsonLines($manifest, [$record]);
        $this->writeSummary();

        $this->artisan('geoflow:knowledge:import-governed', [
            'manifest' => $manifest,
            '--keyword-library' => 42,
        ])->assertFailed();

        $this->assertNoImportWrites();
    }

    public function test_cleaned_body_must_already_match_task_six_unicode_normalization(): void
    {
        $body = "# Café\u{00A0}\n\nDetails.\u{00A0}\n";
        $manifest = $this->writePackage([
            $this->documentRecord(
                'url:https://www.lightinthebox.com/cafe',
                $body,
                'brand-company',
            ),
        ], []);

        $this->artisan('geoflow:knowledge:import-governed', [
            'manifest' => $manifest,
            '--dry-run' => true,
            '--keyword-library' => 42,
        ])->assertFailed();

        $this->assertNoImportWrites();
    }

    public function test_dry_run_preflight_rejects_active_canonical_collision_without_writes(): void
    {
        [$brand, $site, $directory] = $this->ownership();
        $existing = $this->documentRecord(
            'url:https://www.lightinthebox.com/returns',
            "# Old Returns\n\nOld policy content.\n",
        );
        KnowledgeBase::query()->create($this->existingDocumentPayload($existing, $brand, $site, $directory));
        $before = [
            'brands' => DB::table('knowledge_brands')->count(),
            'sites' => DB::table('knowledge_sites')->count(),
            'directories' => DB::table('knowledge_directories')->count(),
            'documents' => DB::table('knowledge_bases')->count(),
        ];
        $manifest = $this->writePackage();

        $this->artisan('geoflow:knowledge:import-governed', [
            'manifest' => $manifest,
            '--dry-run' => true,
            '--keyword-library' => 42,
        ])->assertFailed();

        $this->assertSame($before['brands'], DB::table('knowledge_brands')->count());
        $this->assertSame($before['sites'], DB::table('knowledge_sites')->count());
        $this->assertSame($before['directories'], DB::table('knowledge_directories')->count());
        $this->assertSame($before['documents'], DB::table('knowledge_bases')->count());
        $this->assertDatabaseCount('knowledge_import_batches', 0);
    }

    public function test_dry_run_preflight_rejects_foreign_owned_existing_asset_without_writes(): void
    {
        $brand = KnowledgeBrand::query()->create(['key' => 'another-brand', 'name' => 'Another Brand']);
        $site = KnowledgeSite::query()->create([
            'brand_id' => $brand->id,
            'key' => 'another-site',
            'canonical_host' => 'example.test',
            'role' => 'commerce-primary',
        ]);
        $directory = KnowledgeDirectory::query()->create([
            'brand_id' => $brand->id,
            'key' => 'policy-compliance',
            'path' => 'policy-compliance',
            'name' => 'Policy & Compliance',
        ]);
        $record = $this->documentRecord();
        KnowledgeBase::query()->create($this->existingDocumentPayload($record, $brand, $site, $directory));
        $before = [
            'brands' => DB::table('knowledge_brands')->count(),
            'sites' => DB::table('knowledge_sites')->count(),
            'directories' => DB::table('knowledge_directories')->count(),
            'documents' => DB::table('knowledge_bases')->count(),
        ];
        $manifest = $this->writePackage([$record], []);

        $this->artisan('geoflow:knowledge:import-governed', [
            'manifest' => $manifest,
            '--dry-run' => true,
            '--keyword-library' => 42,
        ])->assertFailed();

        $this->assertSame($before['brands'], DB::table('knowledge_brands')->count());
        $this->assertSame($before['sites'], DB::table('knowledge_sites')->count());
        $this->assertSame($before['directories'], DB::table('knowledge_directories')->count());
        $this->assertSame($before['documents'], DB::table('knowledge_bases')->count());
        $this->assertDatabaseCount('knowledge_import_batches', 0);
    }

    public function test_commit_rechecks_stale_prevalidation_under_the_owner_locks(): void
    {
        $validator = $this->app->make(KnowledgeImportValidator::class);
        $importer = $this->app->make(GovernedKnowledgeImporter::class);
        $canonicalKey = 'url:https://www.lightinthebox.com/stale-prevalidation';
        $firstRecord = $this->documentRecord(
            $canonicalKey,
            "# First package\n\nFirst governed content.\n",
        );
        $firstPackage = $validator->validate($this->writePackage([$firstRecord], []), 42);
        $secondRecord = $this->documentRecord(
            $canonicalKey,
            "# Second package\n\nConflicting governed content.\n",
        );
        $secondPackage = $validator->validate($this->writePackage([$secondRecord], []), 42);
        $commit = new \ReflectionMethod(GovernedKnowledgeImporter::class, 'commitPackage');

        DB::transaction(fn (): int => $commit->invoke($importer, $firstPackage, 42));

        $rejection = null;
        try {
            DB::transaction(fn (): int => $commit->invoke($importer, $secondPackage, 42));
        } catch (RuntimeException $exception) {
            $rejection = $exception;
        }
        $this->assertInstanceOf(RuntimeException::class, $rejection);
        $this->assertSame('governed_import.preflight_active_document_conflict', $rejection->getMessage());

        $this->assertDatabaseCount('knowledge_brands', 1);
        $this->assertDatabaseCount('knowledge_sites', 1);
        $this->assertDatabaseCount('knowledge_directories', 9);
        $this->assertDatabaseCount('knowledge_bases', 1);
        $this->assertDatabaseCount('knowledge_import_batches', 1);
        $this->assertDatabaseCount('knowledge_import_rows', 1);
        $this->assertDatabaseCount('knowledge_duplicates', 0);
        $this->assertSame($firstRecord['_body'], KnowledgeBase::query()->sole()->content);
        $this->assertSame(
            $firstPackage['configuration_hash'],
            DB::table('knowledge_import_batches')->sole()->configuration_hash,
        );
        Queue::assertNothingPushed();
    }

    public function test_preflight_models_package_final_state_when_superseding_v1_with_active_v2(): void
    {
        [$brand, $site, $directory] = $this->ownership();
        $canonicalKey = 'url:https://www.lightinthebox.com/versioned-returns';
        $v1 = $this->documentRecord(
            $canonicalKey,
            "# Returns v1\n\nOld policy content.\n",
        );
        KnowledgeBase::query()->create($this->existingDocumentPayload($v1, $brand, $site, $directory));
        $v1['lifecycle_status'] = 'superseded';
        $v2 = $this->documentRecord(
            $canonicalKey,
            "# Returns v2\n\nCurrent policy content.\n",
        );
        $manifest = $this->writePackage([$v1, $v2], []);
        $arguments = [
            'manifest' => $manifest,
            '--keyword-library' => 42,
        ];

        $this->artisan('geoflow:knowledge:import-governed', $arguments)->assertSuccessful();
        $this->artisan('geoflow:knowledge:import-governed', $arguments)->assertSuccessful();

        $this->assertDatabaseCount('knowledge_bases', 2);
        $this->assertDatabaseCount('knowledge_import_batches', 1);
        $this->assertSame(
            ['superseded', 'active'],
            KnowledgeBase::query()->orderBy('id')->pluck('lifecycle_status')->all(),
        );
    }

    public function test_manifest_enums_urls_and_single_active_version_are_strictly_validated(): void
    {
        $invalidFields = [
            'risk_level' => 'critical',
            'review_status' => 'approved',
            'source_type' => 'uploaded_file',
            'ingestion_method' => 'api',
            'authority_level' => 'unknown',
            'lifecycle_status' => 'published',
            'source_url' => 'javascript:alert(1)',
        ];
        foreach ($invalidFields as $field => $value) {
            $record = [...$this->documentRecord(), $field => $value];
            $manifest = $this->writePackage([$record], []);
            $this->artisan('geoflow:knowledge:import-governed', [
                'manifest' => $manifest,
                '--dry-run' => true,
                '--keyword-library' => 42,
            ])->assertFailed();
        }

        $second = $this->documentRecord(
            'url:https://www.lightinthebox.com/returns',
            "# Returns Updated\n\nUpdated policy content.\n",
        );
        $manifest = $this->writePackage([$this->documentRecord(), $second], []);
        $this->artisan('geoflow:knowledge:import-governed', [
            'manifest' => $manifest,
            '--dry-run' => true,
            '--keyword-library' => 42,
        ])->assertFailed();

        $this->assertNoImportWrites();
    }

    public function test_global_identity_collisions_and_exact_duplicate_hash_divergence_are_rejected(): void
    {
        $record = $this->documentRecord();
        $collision = $this->exclusionRecord();
        $collision['document_id'] = $record['document_id'];
        $manifest = $this->writePackage([$record], [], [$collision]);
        $this->artisan('geoflow:knowledge:import-governed', [
            'manifest' => $manifest,
            '--dry-run' => true,
            '--keyword-library' => 42,
        ])->assertFailed();

        $duplicate = $this->manifestDuplicate($record);
        $duplicate['normalized_content_hash'] = hash('sha256', "different\n");
        $manifest = $this->writePackage([$record], [$duplicate]);
        $this->artisan('geoflow:knowledge:import-governed', [
            'manifest' => $manifest,
            '--dry-run' => true,
            '--keyword-library' => 42,
        ])->assertFailed();

        $this->assertNoImportWrites();
    }

    public function test_exclusion_and_duplicate_dual_role_source_identity_is_consistent_with_task_six(): void
    {
        [$exclusions, $duplicate] = $this->dualRoleAuditFixture();
        $manifest = $this->writePackage([$this->documentRecord()], [$duplicate], $exclusions);

        $this->artisan('geoflow:knowledge:import-governed', [
            'manifest' => $manifest,
            '--dry-run' => true,
            '--keyword-library' => 42,
        ])->assertSuccessful();

        $this->assertNoImportWrites();
    }

    public function test_duplicate_sources_cannot_diverge_from_exclusions_reuse_manifest_or_self_target(): void
    {
        [$exclusions, $duplicate] = $this->dualRoleAuditFixture();
        $divergent = [...$duplicate, 'source_file_hash' => hash('sha256', 'divergent bytes')];
        $manifest = $this->writePackage([$this->documentRecord()], [$divergent], $exclusions);
        $this->artisan('geoflow:knowledge:import-governed', [
            'manifest' => $manifest,
            '--dry-run' => true,
            '--keyword-library' => 42,
        ])->assertFailed();

        $record = $this->documentRecord();
        $manifestReuse = [
            ...$duplicate,
            'source_file_path' => $record['source_file_path'],
            'source_file_hash' => $record['source_file_hash'],
        ];
        $manifest = $this->writePackage([$record], [$manifestReuse], $exclusions);
        $this->artisan('geoflow:knowledge:import-governed', [
            'manifest' => $manifest,
            '--dry-run' => true,
            '--keyword-library' => 42,
        ])->assertFailed();

        $source = $exclusions[1];
        $selfTarget = [
            ...$duplicate,
            'duplicate_of_source_file_path' => $source['source_file_path'],
            'duplicate_of_document_id' => $source['document_id'],
        ];
        $manifest = $this->writePackage([$record], [$selfTarget], $exclusions);
        $this->artisan('geoflow:knowledge:import-governed', [
            'manifest' => $manifest,
            '--dry-run' => true,
            '--keyword-library' => 42,
        ])->assertFailed();

        $this->assertNoImportWrites();
    }

    public function test_english_orphan_txt_exclusion_from_task_six_remains_a_valid_audit_target(): void
    {
        $canonicalKey = 'path:orphan-txt';
        $contentHash = hash('sha256', "orphan english text\n");
        $exclusion = [
            'document_id' => $this->documentId($canonicalKey, $contentHash),
            'canonical_document_key' => $canonicalKey,
            'source_file_path' => 'LightInTheBox_PT_Official_KB_TXT/01_Company_Info/Orphan.txt',
            'source_file_hash' => hash('sha256', 'orphan txt bytes'),
            'normalized_content_hash' => $contentHash,
            'language_code' => 'en',
            'reason' => 'txt_mirror_without_markdown_canonical',
            'source_kind' => 'txt_mirror',
            'warnings' => [],
        ];
        $manifest = $this->writePackage([$this->documentRecord()], [], [$exclusion]);

        $this->artisan('geoflow:knowledge:import-governed', [
            'manifest' => $manifest,
            '--dry-run' => true,
            '--keyword-library' => 42,
        ])->assertSuccessful();

        $this->assertNoImportWrites();
    }

    public function test_run_summary_schema_flags_counts_and_all_artifact_hashes_are_required(): void
    {
        $manifest = $this->writePackage();
        $summary = json_decode(File::get($this->root.'/run_summary.json'), true, 512, JSON_THROW_ON_ERROR);
        $summary['schema_version'] = 999;
        $summary['validation']['english_only_manifest'] = false;
        $summary['counts']['manifest_documents'] = 999;
        unset($summary['artifact_hashes']['near_duplicate_review.jsonl']);
        File::put($this->root.'/run_summary.json', json_encode($summary, JSON_THROW_ON_ERROR));

        $this->artisan('geoflow:knowledge:import-governed', [
            'manifest' => $manifest,
            '--dry-run' => true,
            '--keyword-library' => 42,
        ])->assertFailed();

        $this->assertNoImportWrites();
    }

    public function test_run_summary_discovered_source_counts_must_match_the_audit_artifacts(): void
    {
        $manifest = $this->writePackage();
        $summary = json_decode(File::get($this->root.'/run_summary.json'), true, 512, JSON_THROW_ON_ERROR);
        $summary['counts']['canonical_source_files']++;
        File::put($this->root.'/run_summary.json', json_encode($summary, JSON_THROW_ON_ERROR));

        $this->artisan('geoflow:knowledge:import-governed', [
            'manifest' => $manifest,
            '--dry-run' => true,
            '--keyword-library' => 42,
        ])->assertFailed();

        $this->assertNoImportWrites();
    }

    public function test_nonempty_near_duplicate_review_resolves_two_formal_same_scope_documents(): void
    {
        $records = [
            $this->documentRecord(),
            $this->documentRecord(
                'url:https://www.lightinthebox.com/exchanges',
                "# Exchanges\n\nExchange eligible items within 30 days.\n",
            ),
        ];
        $nearReview = $this->nearReviewRecord($records[0], $records[1]);
        $manifest = $this->writePackage($records, [], null, [$nearReview]);

        $this->artisan('geoflow:knowledge:import-governed', [
            'manifest' => $manifest,
            '--dry-run' => true,
            '--keyword-library' => 42,
        ])->assertSuccessful();

        $this->assertNoImportWrites();
    }

    public function test_near_duplicate_review_rejects_invalid_resolution_score_and_identity_scope(): void
    {
        $records = [
            $this->documentRecord(),
            $this->documentRecord(
                'url:https://www.lightinthebox.com/exchanges',
                "# Exchanges\n\nExchange eligible items within 30 days.\n",
            ),
        ];
        $valid = $this->nearReviewRecord($records[0], $records[1]);
        $invalidRecords = [
            [...$valid, 'action' => 'merge'],
            [...$valid, 'similarity' => 0.50],
            [...$valid, 'source_file_paths' => [$records[0]['source_file_path'], 'missing.md']],
        ];
        foreach ($invalidRecords as $invalid) {
            $manifest = $this->writePackage($records, [], null, [$invalid]);
            $this->artisan('geoflow:knowledge:import-governed', [
                'manifest' => $manifest,
                '--dry-run' => true,
                '--keyword-library' => 42,
            ])->assertFailed();
        }

        $this->assertNoImportWrites();
    }

    public function test_preexisting_governed_document_is_updated_in_place_and_keeps_task_reference(): void
    {
        [$brand, $site, $directory] = $this->ownership();
        $record = $this->documentRecord();
        $existing = KnowledgeBase::query()->create([
            ...$this->existingDocumentPayload($record, $brand, $site, $directory),
            'source_name' => 'Old source label',
        ]);
        $task = Task::query()->create([
            'name' => 'Referenced governed task',
            'status' => 'paused',
            'knowledge_base_id' => $existing->id,
        ]);
        $manifest = $this->writePackage([$record], []);

        $this->artisan('geoflow:knowledge:import-governed', [
            'manifest' => $manifest,
            '--keyword-library' => 42,
        ])->assertSuccessful();

        $this->assertSame((int) $existing->id, (int) KnowledgeBase::query()->sole()->id);
        $this->assertSame((int) $existing->id, (int) $task->fresh()->knowledge_base_id);
        $this->assertSame($record['source_name'], $existing->fresh()->source_name);
    }

    public function test_exact_normalized_body_is_imported_without_trimming_leading_content(): void
    {
        $body = "\n# Returns\n\nReturn eligible items within 30 days.\n";
        $record = $this->documentRecord(
            'url:https://www.lightinthebox.com/leading-body',
            $body,
        );
        $record['title'] = 'Returns';
        $manifest = $this->writePackage([$record], []);

        $this->artisan('geoflow:knowledge:import-governed', [
            'manifest' => $manifest,
            '--keyword-library' => 42,
        ])->assertSuccessful();

        $this->assertSame($body, KnowledgeBase::query()->sole()->content);
    }

    public function test_transaction_failure_rolls_back_owners_documents_and_audit_rows(): void
    {
        $second = $this->documentRecord(
            'url:https://www.lightinthebox.com/company',
            "# Company\n\nCompany information.\n",
            'brand-company',
        );
        $manifest = $this->writePackage([$this->documentRecord(), $second], []);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER governed_import_force_failure
BEFORE INSERT ON knowledge_import_rows
FOR EACH ROW WHEN NEW.row_number = 2
BEGIN
    SELECT RAISE(ABORT, 'forced transaction rollback');
END
SQL);

        $this->artisan('geoflow:knowledge:import-governed', [
            'manifest' => $manifest,
            '--keyword-library' => 42,
        ])->assertFailed();

        $this->assertNoImportWrites();
    }

    public function test_transaction_failure_rolls_back_preexisting_document_update_and_keeps_task_reference(): void
    {
        [$brand, $site, $directory] = $this->ownership();
        $record = $this->documentRecord();
        $existing = KnowledgeBase::query()->create([
            ...$this->existingDocumentPayload($record, $brand, $site, $directory),
            'source_name' => 'Operator-approved old label',
        ]);
        $task = Task::query()->create([
            'name' => 'Referenced governed task',
            'status' => 'paused',
            'knowledge_base_id' => $existing->id,
        ]);
        $second = $this->documentRecord(
            'url:https://www.lightinthebox.com/company',
            "# Company\n\nCompany information.\n",
            'brand-company',
        );
        $manifest = $this->writePackage([$record, $second], []);
        DB::unprepared(<<<'SQL'
CREATE TRIGGER governed_import_update_force_failure
BEFORE INSERT ON knowledge_import_rows
FOR EACH ROW WHEN NEW.row_number = 2
BEGIN
    SELECT RAISE(ABORT, 'forced update transaction rollback');
END
SQL);

        $this->artisan('geoflow:knowledge:import-governed', [
            'manifest' => $manifest,
            '--keyword-library' => 42,
        ])->assertFailed();

        $this->assertSame('Operator-approved old label', $existing->fresh()->source_name);
        $this->assertSame((int) $existing->id, (int) $task->fresh()->knowledge_base_id);
        $this->assertDatabaseCount('knowledge_bases', 1);
        $this->assertDatabaseCount('knowledge_import_batches', 0);
        $this->assertDatabaseCount('knowledge_import_rows', 0);
        $this->assertDatabaseCount('knowledge_duplicates', 0);
    }

    public function test_failed_chunk_dispatch_is_auditable_and_rerun_reconciles_only_missing_dispatches(): void
    {
        $records = [
            $this->documentRecord(),
            $this->documentRecord(
                'url:https://www.lightinthebox.com/company',
                "# Company\n\nCompany information.\n",
                'brand-company',
            ),
        ];
        $manifest = $this->writePackage($records, []);
        $firstCoordinator = new class extends KnowledgeChunkSyncCoordinator
        {
            /** @var list<int> */
            public array $calls = [];

            public function request(int $knowledgeBaseId, bool $requireRealEmbedding = false, bool $force = false): bool
            {
                $this->calls[] = $knowledgeBaseId;
                if (count($this->calls) === 2) {
                    throw new RuntimeException('queue unavailable');
                }

                return true;
            }
        };
        $this->app->instance(KnowledgeChunkSyncCoordinator::class, $firstCoordinator);

        $this->artisan('geoflow:knowledge:import-governed', [
            'manifest' => $manifest,
            '--keyword-library' => 42,
        ])->assertFailed();

        $batch = DB::table('knowledge_import_batches')->sole();
        $this->assertSame('failed', $batch->status);
        $this->assertSame(['chunk_dispatched', 'chunk_failed'], DB::table('knowledge_import_rows')->orderBy('row_number')->pluck('outcome')->all());
        $this->assertStringContainsString('queue unavailable', (string) $batch->error_log);

        $retryCoordinator = new class extends KnowledgeChunkSyncCoordinator
        {
            /** @var list<int> */
            public array $calls = [];

            public function request(int $knowledgeBaseId, bool $requireRealEmbedding = false, bool $force = false): bool
            {
                $this->calls[] = $knowledgeBaseId;

                return true;
            }
        };
        $this->app->instance(KnowledgeChunkSyncCoordinator::class, $retryCoordinator);
        $this->app->forgetInstance(GovernedKnowledgeImporter::class);

        $this->artisan('geoflow:knowledge:import-governed', [
            'manifest' => $manifest,
            '--keyword-library' => 42,
        ])->assertSuccessful();

        $this->assertCount(1, $retryCoordinator->calls);
        $this->assertSame($firstCoordinator->calls[1], $retryCoordinator->calls[0]);
        $this->assertDatabaseCount('knowledge_bases', 2);
        $this->assertDatabaseCount('knowledge_import_batches', 1);
        $this->assertDatabaseCount('knowledge_import_rows', 2);
        $this->assertSame('completed', DB::table('knowledge_import_batches')->sole()->status);
        $this->assertSame(['chunk_dispatched', 'chunk_dispatched'], DB::table('knowledge_import_rows')->orderBy('row_number')->pluck('outcome')->all());
    }

    public function test_interleaved_reconciler_cannot_double_claim_or_dispatch_the_same_row(): void
    {
        $manifest = $this->writePackage([$this->documentRecord()], []);
        $coordinator = new class extends KnowledgeChunkSyncCoordinator
        {
            public int $calls = 0;

            /** @var callable():void */
            public $duringFirstRequest;

            public function request(int $knowledgeBaseId, bool $requireRealEmbedding = false, bool $force = false): bool
            {
                $this->calls++;
                if ($this->calls === 1) {
                    ($this->duringFirstRequest)();
                }

                return true;
            }
        };
        $this->app->instance(KnowledgeChunkSyncCoordinator::class, $coordinator);
        $coordinator->duringFirstRequest = function () use ($manifest): void {
            $this->app->make(GovernedKnowledgeImporter::class)->import($manifest, false, 42);
        };

        $this->app->make(GovernedKnowledgeImporter::class)->import($manifest, false, 42);

        $this->assertSame(1, $coordinator->calls);
        $this->assertSame('chunk_dispatched', DB::table('knowledge_import_rows')->sole()->outcome);
        $this->assertSame('completed', DB::table('knowledge_import_batches')->sole()->status);
        $this->assertSame(1, (int) DB::table('knowledge_import_rows')->sole()->dispatch_attempt_count);
    }

    public function test_false_coordinator_result_uses_actual_chunk_state_and_never_marks_idle_as_dispatched(): void
    {
        $manifest = $this->writePackage([$this->documentRecord()], []);
        $coordinator = new class extends KnowledgeChunkSyncCoordinator
        {
            public int $calls = 0;

            public function request(int $knowledgeBaseId, bool $requireRealEmbedding = false, bool $force = false): bool
            {
                $this->calls++;

                return false;
            }
        };
        $this->app->instance(KnowledgeChunkSyncCoordinator::class, $coordinator);
        $arguments = [
            'manifest' => $manifest,
            '--keyword-library' => 42,
        ];

        $this->artisan('geoflow:knowledge:import-governed', $arguments)->assertFailed();
        $this->assertSame('chunk_failed', DB::table('knowledge_import_rows')->sole()->outcome);
        $this->assertSame('failed', DB::table('knowledge_import_batches')->sole()->status);

        DB::table('knowledge_bases')->update(['chunk_sync_status' => 'failed']);
        $this->app->forgetInstance(GovernedKnowledgeImporter::class);
        $this->artisan('geoflow:knowledge:import-governed', $arguments)->assertFailed();
        $this->assertSame('chunk_failed', DB::table('knowledge_import_rows')->sole()->outcome);

        DB::table('knowledge_bases')->update([
            'chunk_sync_status' => 'pending',
            'chunk_source_hash' => hash('sha256', (string) DB::table('knowledge_bases')->sole()->content),
        ]);
        $this->app->forgetInstance(GovernedKnowledgeImporter::class);
        $this->artisan('geoflow:knowledge:import-governed', $arguments)->assertSuccessful();

        $row = DB::table('knowledge_import_rows')->sole();
        $this->assertSame('chunk_dispatched', $row->outcome);
        $this->assertSame(3, (int) $row->dispatch_attempt_count);
        $this->assertNull($row->dispatch_claim_token);
        $this->assertNull($row->dispatch_claimed_at);
        $this->assertSame(3, $coordinator->calls);
    }

    public function test_concurrent_batch_error_append_is_preserved_when_dispatch_fails(): void
    {
        $manifest = $this->writePackage([$this->documentRecord()], []);
        $coordinator = new class extends KnowledgeChunkSyncCoordinator
        {
            public function request(int $knowledgeBaseId, bool $requireRealEmbedding = false, bool $force = false): bool
            {
                DB::table('knowledge_import_batches')->update([
                    'error_log' => json_encode([[
                        'message' => 'interleaved reconciler failure',
                    ]], JSON_THROW_ON_ERROR),
                ]);

                throw new RuntimeException('primary reconciler failure');
            }
        };
        $this->app->instance(KnowledgeChunkSyncCoordinator::class, $coordinator);

        $this->artisan('geoflow:knowledge:import-governed', [
            'manifest' => $manifest,
            '--keyword-library' => 42,
        ])->assertFailed();

        $errors = json_decode((string) DB::table('knowledge_import_batches')->sole()->error_log, true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(2, $errors);
        $this->assertSame('interleaved reconciler failure', $errors[0]['message']);
        $this->assertSame('primary reconciler failure', $errors[1]['message']);
    }

    public function test_stale_dispatch_claim_is_recoverable_without_duplicate_import_rows(): void
    {
        $manifest = $this->writePackage([$this->documentRecord()], []);
        $failing = new class extends KnowledgeChunkSyncCoordinator
        {
            public function request(int $knowledgeBaseId, bool $requireRealEmbedding = false, bool $force = false): bool
            {
                throw new RuntimeException('initial dispatch failure');
            }
        };
        $this->app->instance(KnowledgeChunkSyncCoordinator::class, $failing);
        $arguments = [
            'manifest' => $manifest,
            '--keyword-library' => 42,
        ];
        $this->artisan('geoflow:knowledge:import-governed', $arguments)->assertFailed();
        DB::table('knowledge_import_rows')->update([
            'outcome' => 'chunk_claimed',
            'dispatch_claim_token' => 'stranded-claim',
            'dispatch_claimed_at' => now()->subMinutes(10),
        ]);

        $successful = new class extends KnowledgeChunkSyncCoordinator
        {
            public int $calls = 0;

            public function request(int $knowledgeBaseId, bool $requireRealEmbedding = false, bool $force = false): bool
            {
                $this->calls++;

                return true;
            }
        };
        $this->app->instance(KnowledgeChunkSyncCoordinator::class, $successful);
        $this->app->forgetInstance(GovernedKnowledgeImporter::class);
        $this->artisan('geoflow:knowledge:import-governed', $arguments)->assertSuccessful();

        $row = DB::table('knowledge_import_rows')->sole();
        $this->assertSame('chunk_dispatched', $row->outcome);
        $this->assertSame(2, (int) $row->dispatch_attempt_count);
        $this->assertSame(1, $successful->calls);
        $this->assertDatabaseCount('knowledge_import_rows', 1);
        $this->assertDatabaseCount('knowledge_import_batches', 1);
    }

    public function test_import_batch_identity_is_enforced_by_the_database(): void
    {
        [$brand, $site] = $this->ownership();
        $payload = [
            'brand_id' => $brand->id,
            'site_id' => $site->id,
            'source_type' => 'governed_manifest',
            'status' => 'staged',
            'configuration_hash' => hash('sha256', 'config'),
            'source_checksum' => hash('sha256', 'source'),
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('knowledge_import_batches')->insert($payload);

        $this->expectException(QueryException::class);
        DB::table('knowledge_import_batches')->insert($payload);
    }

    public function test_batch_identity_migration_reconciles_populated_duplicates_without_losing_audits(): void
    {
        $migration = require database_path('migrations/2026_08_19_000400_harden_governed_knowledge_import_batches.php');
        $migration->down();
        [$brand, $site] = $this->ownership();
        $configurationHash = hash('sha256', 'legacy duplicate config');
        $sourceChecksum = hash('sha256', 'legacy duplicate source');
        $batchIds = [];
        foreach ([1, 2] as $number) {
            $batchIds[] = DB::table('knowledge_import_batches')->insertGetId([
                'brand_id' => $brand->id,
                'site_id' => $site->id,
                'source_type' => 'governed_manifest',
                'status' => 'completed',
                'configuration_hash' => $configurationHash,
                'source_checksum' => $sourceChecksum,
                'rollback_metadata' => json_encode(['legacy_marker' => $number], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        foreach ($batchIds as $index => $batchId) {
            $rowId = DB::table('knowledge_import_rows')->insertGetId([
                'brand_id' => $brand->id,
                'site_id' => $site->id,
                'batch_id' => $batchId,
                'row_number' => 1,
                'staged_payload' => json_encode(['legacy_row' => $index + 1], JSON_THROW_ON_ERROR),
                'outcome' => 'duplicate',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('knowledge_duplicates')->insert([
                'brand_id' => $brand->id,
                'site_id' => $site->id,
                'import_batch_id' => $batchId,
                'import_row_id' => $rowId,
                'duplicate_kind' => 'exact',
                'duplicate_reason' => 'legacy audit record',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $migration->up();

        $batches = DB::table('knowledge_import_batches')->orderBy('id')->get();
        $this->assertCount(2, $batches);
        $this->assertDatabaseCount('knowledge_import_rows', 2);
        $this->assertDatabaseCount('knowledge_duplicates', 2);
        $this->assertSame($configurationHash, $batches[0]->configuration_hash);
        $this->assertNotSame($configurationHash, $batches[1]->configuration_hash);
        $metadata = json_decode((string) $batches[1]->rollback_metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(2, $metadata['legacy_marker']);
        $this->assertSame($configurationHash, $metadata['identity_reconciliation']['original_configuration_hash']);
        $this->assertSame($sourceChecksum, $metadata['identity_reconciliation']['original_source_checksum']);
        $this->assertSame((int) $batches[0]->id, $metadata['identity_reconciliation']['canonical_batch_id']);
        $this->assertTrue(Schema::hasColumns('knowledge_import_rows', [
            'dispatch_claim_token',
            'dispatch_claimed_at',
            'dispatch_attempt_count',
        ]));

        $this->expectException(QueryException::class);
        DB::table('knowledge_import_batches')->insert([
            'brand_id' => $brand->id,
            'site_id' => $site->id,
            'source_type' => 'governed_manifest',
            'status' => 'staged',
            'configuration_hash' => $configurationHash,
            'source_checksum' => $sourceChecksum,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>|null  $records
     * @param  list<array<string, mixed>>|null  $duplicates
     * @param  list<array<string, mixed>>|null  $exclusions
     * @param  list<array<string, mixed>>|null  $nearReviews
     */
    private function writePackage(
        ?array $records = null,
        ?array $duplicates = null,
        ?array $exclusions = null,
        ?array $nearReviews = null,
    ): string {
        $records ??= [$this->documentRecord()];
        $manifest = [];
        foreach ($records as $record) {
            $body = (string) $record['_body'];
            unset($record['_body']);
            $path = $this->root.'/'.$record['cleaned_file_path'];
            File::ensureDirectoryExists(dirname($path));
            $content = $this->renderCleanedDocument($record, $body);
            File::put($path, $content);
            $record['cleaned_file_hash'] = hash('sha256', $content);
            $manifest[] = $record;
        }

        $exclusions ??= [$this->exclusionRecord()];
        $duplicates ??= [
            $this->manifestDuplicate($manifest[0]),
            $this->exclusionDuplicate($exclusions[0]),
        ];

        $this->writeJsonLines($this->root.'/manifest.jsonl', $manifest);
        $this->writeJsonLines($this->root.'/duplicate_audit.jsonl', $duplicates);
        $this->writeJsonLines($this->root.'/exclusion_audit.jsonl', $exclusions);
        $this->writeJsonLines($this->root.'/near_duplicate_review.jsonl', $nearReviews ?? []);
        $this->writeSummary();

        return $this->root.'/manifest.jsonl';
    }

    /** @return array<string, mixed> */
    private function documentRecord(
        string $canonicalKey = 'url:https://www.lightinthebox.com/returns',
        string $body = "# Returns\n\nReturn eligible items within 30 days.\n",
        string $directory = 'policy-compliance',
    ): array {
        $contentHash = hash('sha256', $body);
        $documentId = $this->documentId($canonicalKey, $contentHash);
        $title = trim((string) preg_replace('/\A#\s+([^\n]+).*/s', '$1', $body));

        return [
            'document_id' => $documentId,
            'asset_key' => 'knowledge:'.$documentId,
            'canonical_document_key' => $canonicalKey,
            'version_label' => 'source-'.$contentHash,
            'lifecycle_status' => 'active',
            'brand_key' => 'lightinthebox',
            'site_key' => 'lightinthebox-main',
            'directory_path' => $directory,
            'language_code' => 'en',
            'authority_level' => 'first_party',
            'risk_level' => $directory === 'policy-compliance' ? 'medium' : 'low',
            'review_status' => 'reviewed',
            'generation_eligible' => true,
            'citation_required' => true,
            'title' => $title,
            'source_name' => 'LightInTheBox official knowledge base',
            'source_url' => str_replace('url:', '', $canonicalKey),
            'source_type' => 'official_web_page',
            'source_file_path' => 'LightInTheBox_PT_Official_KB/'.$directory.'/'.$documentId.'.md',
            'source_file_hash' => hash('sha256', 'source-'.$documentId),
            'normalized_content_hash' => $contentHash,
            'ingestion_method' => 'read_only_folder',
            'retrieved_at' => '2026-08-19',
            'effective_date' => '2026-08-01',
            'review_due_at' => '2027-08-01',
            'warnings' => [],
            'cleaned_file_path' => 'cleaned/'.$directory.'/'.$documentId.'.md',
            'keyword_node_ids' => [$this->keywordNodeId],
            'keyword_cluster_ids' => [$this->keywordClusterId],
            '_body' => $body,
        ];
    }

    /** @return array<string, mixed> */
    private function exclusionRecord(): array
    {
        $canonicalKey = 'path:excluded';
        $contentHash = hash('sha256', "conteudo excluido\n");

        return [
            'document_id' => $this->documentId($canonicalKey, $contentHash),
            'canonical_document_key' => $canonicalKey,
            'source_file_path' => 'LightInTheBox_PT_Official_KB/01_Company_Info/Excluded.md',
            'source_file_hash' => hash('sha256', 'excluded markdown'),
            'normalized_content_hash' => $contentHash,
            'language_code' => 'pt',
            'reason' => 'non_english_initial_corpus',
            'source_kind' => 'canonical_md',
            'warnings' => [],
        ];
    }

    /** @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function manifestDuplicate(array $record): array
    {
        return [
            'kind' => 'exact',
            'source_file_path' => str_replace('.md', '.txt', str_replace('Official_KB/', 'Official_KB_TXT/', $record['source_file_path'])),
            'source_file_hash' => hash('sha256', 'txt-'.$record['document_id']),
            'normalized_content_hash' => $record['normalized_content_hash'],
            'duplicate_of_source_file_path' => $record['source_file_path'],
            'duplicate_of_document_id' => $record['document_id'],
            'reason' => 'markdown_over_txt',
        ];
    }

    /** @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function exclusionDuplicate(array $record): array
    {
        return [
            'kind' => 'exact',
            'source_file_path' => 'LightInTheBox_PT_Official_KB_TXT/01_Company_Info/Excluded.txt',
            'source_file_hash' => hash('sha256', 'excluded txt mirror'),
            'normalized_content_hash' => $record['normalized_content_hash'],
            'duplicate_of_source_file_path' => $record['source_file_path'],
            'duplicate_of_document_id' => $record['document_id'],
            'reason' => 'markdown_over_txt',
        ];
    }

    /** @return array{list<array<string,mixed>>,array<string,mixed>} */
    private function dualRoleAuditFixture(): array
    {
        $target = $this->exclusionRecord();
        $sourceCanonicalKey = 'path:excluded-txt-mirror';
        $source = [
            'document_id' => $this->documentId($sourceCanonicalKey, $target['normalized_content_hash']),
            'canonical_document_key' => $sourceCanonicalKey,
            'source_file_path' => 'LightInTheBox_PT_Official_KB_TXT/01_Company_Info/Excluded.txt',
            'source_file_hash' => hash('sha256', 'excluded txt mirror'),
            'normalized_content_hash' => $target['normalized_content_hash'],
            'language_code' => 'pt',
            'reason' => 'non_english_initial_corpus',
            'source_kind' => 'txt_mirror',
            'warnings' => [],
        ];
        $duplicate = [
            'kind' => 'exact',
            'source_file_path' => $source['source_file_path'],
            'source_file_hash' => $source['source_file_hash'],
            'normalized_content_hash' => $source['normalized_content_hash'],
            'duplicate_of_source_file_path' => $target['source_file_path'],
            'duplicate_of_document_id' => $target['document_id'],
            'reason' => 'markdown_over_txt',
        ];

        return [[$target, $source], $duplicate];
    }

    /**
     * @param  array<string,mixed>  $first
     * @param  array<string,mixed>  $second
     * @return array<string,mixed>
     */
    private function nearReviewRecord(array $first, array $second): array
    {
        $paths = [$first['source_file_path'], $second['source_file_path']];
        sort($paths, SORT_STRING);

        return [
            'kind' => 'near',
            'action' => 'review',
            'source_file_paths' => $paths,
            'reason' => 'near_duplicate_requires_review',
            'similarity' => 0.95,
        ];
    }

    /** @param array<string,mixed> $record */
    private function renderCleanedDocument(array $record, string $body): string
    {
        $fields = [
            'document_id', 'asset_key', 'canonical_document_key', 'version_label',
            'lifecycle_status', 'brand_key', 'site_key', 'directory_path',
            'language_code', 'authority_level', 'risk_level', 'review_status',
            'generation_eligible', 'citation_required', 'title', 'source_name',
            'source_url', 'source_type', 'source_file_path', 'source_file_hash',
            'normalized_content_hash', 'ingestion_method', 'retrieved_at',
            'effective_date', 'review_due_at', 'warnings',
        ];
        $lines = ['---'];
        foreach ($fields as $field) {
            $lines[] = $field.': '.json_encode($record[$field], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
        $lines[] = '---';
        $lines[] = '';
        $lines[] = rtrim($body, "\n");
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function writeSummary(?string $sourceSetHash = null): void
    {
        $manifest = $this->readJsonLines($this->root.'/manifest.jsonl');
        $duplicates = $this->readJsonLines($this->root.'/duplicate_audit.jsonl');
        $exclusions = $this->readJsonLines($this->root.'/exclusion_audit.jsonl');
        $near = $this->readJsonLines($this->root.'/near_duplicate_review.jsonl');
        $sourcePaths = [];
        $sourceHashes = [];
        foreach ([...$manifest, ...$exclusions, ...$duplicates] as $record) {
            $sourcePaths[$record['source_file_path']] = true;
            $sourceHashes[$record['source_file_path']] ??= $record['source_file_hash'];
        }
        foreach ($near as $record) {
            foreach ($record['source_file_paths'] as $path) {
                $sourcePaths[$path] = true;
            }
        }
        $canonicalSourceCount = count(array_filter(
            array_keys($sourcePaths),
            static fn (string $path): bool => strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) === 'md',
        ));
        $txtMirrorCount = count($sourcePaths) - $canonicalSourceCount;
        ksort($sourceHashes, SORT_STRING);
        $computedSourceSetHash = hash('sha256', json_encode(
            $sourceHashes,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
        $summary = [
            'schema_version' => 1,
            'brand_key' => 'lightinthebox',
            'site_key' => 'lightinthebox-main',
            'source_set_hash' => $sourceSetHash ?? $computedSourceSetHash,
            'counts' => [
                'canonical_source_files' => $canonicalSourceCount,
                'txt_mirror_files' => $txtMirrorCount,
                'source_files_hashed' => count($sourcePaths),
                'manifest_documents' => count($manifest),
                'active_documents' => count(array_filter($manifest, fn (array $record): bool => $record['lifecycle_status'] === 'active')),
                'superseded_documents' => count(array_filter($manifest, fn (array $record): bool => $record['lifecycle_status'] === 'superseded')),
                'duplicate_records' => count($duplicates),
                'near_review_records' => count($near),
                'excluded_sources' => count($exclusions),
            ],
            'validation' => [
                'source_hashes_unchanged' => true,
                'zero_active_exact_hash_duplicates' => true,
                'no_unknown_owner' => true,
                'english_only_manifest' => true,
                'policy_eligibility_rules' => true,
                'valid_duplicate_targets' => true,
                'cleaned_manifest_paths_unique' => true,
            ],
            'artifact_hashes' => [],
        ];
        foreach (['manifest.jsonl', 'duplicate_audit.jsonl', 'exclusion_audit.jsonl', 'near_duplicate_review.jsonl'] as $name) {
            $summary['artifact_hashes'][$name] = hash_file('sha256', $this->root.'/'.$name);
        }
        File::put($this->root.'/run_summary.json', json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return list<array<string,mixed>> */
    private function readJsonLines(string $path): array
    {
        $content = trim(File::get($path));
        if ($content === '') {
            return [];
        }

        return array_map(
            static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            explode("\n", $content),
        );
    }

    private function documentId(string $canonicalKey, string $contentHash): string
    {
        return substr(hash('sha256', implode("\0", [
            'lightinthebox',
            'lightinthebox-main',
            $canonicalKey,
            $contentHash,
        ])), 0, 32);
    }

    /** @return array{KnowledgeBrand,KnowledgeSite,KnowledgeDirectory} */
    private function ownership(): array
    {
        $brand = KnowledgeBrand::query()->firstOrCreate(['key' => 'lightinthebox'], ['name' => 'LightInTheBox']);
        $site = KnowledgeSite::query()->firstOrCreate([
            'brand_id' => $brand->id,
            'key' => 'lightinthebox-main',
        ], [
            'canonical_host' => 'www.lightinthebox.com',
            'role' => 'commerce-primary',
        ]);
        $directory = KnowledgeDirectory::query()->firstOrCreate([
            'brand_id' => $brand->id,
            'path' => 'policy-compliance',
        ], [
            'key' => 'policy-compliance',
            'name' => 'Policy & Compliance',
        ]);

        return [$brand, $site, $directory];
    }

    /** @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function existingDocumentPayload(
        array $record,
        KnowledgeBrand $brand,
        KnowledgeSite $site,
        KnowledgeDirectory $directory,
    ): array {
        return [
            'asset_key' => $record['asset_key'],
            'name' => $record['title'],
            'content' => $record['_body'],
            'file_type' => 'markdown',
            'brand_id' => $brand->id,
            'site_id' => $site->id,
            'primary_directory_id' => $directory->id,
            'canonical_document_key' => $record['canonical_document_key'],
            'language_code' => $record['language_code'],
            'authority_level' => $record['authority_level'],
            'version_label' => $record['version_label'],
            'lifecycle_status' => $record['lifecycle_status'],
            'generation_eligible' => $record['generation_eligible'],
            'citation_required' => $record['citation_required'],
            'normalized_content_hash' => $record['normalized_content_hash'],
            'source_file_path' => $record['source_file_path'],
            'source_file_hash' => $record['source_file_hash'],
            'source_name' => $record['source_name'],
        ];
    }

    /** @param list<array<string, mixed>> $records */
    private function writeJsonLines(string $path, array $records): void
    {
        File::put($path, implode("\n", array_map(
            static fn (array $record): string => json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $records,
        )).($records === [] ? '' : "\n"));
    }

    private function assertNoImportWrites(): void
    {
        $this->assertDatabaseCount('knowledge_brands', 0);
        $this->assertDatabaseCount('knowledge_sites', 0);
        $this->assertDatabaseCount('knowledge_directories', 0);
        $this->assertDatabaseCount('knowledge_bases', 0);
        $this->assertDatabaseCount('knowledge_import_batches', 0);
        $this->assertDatabaseCount('knowledge_import_rows', 0);
        $this->assertDatabaseCount('knowledge_duplicates', 0);
        Queue::assertNothingPushed();
    }
}
