<?php

namespace Tests\Feature;

use App\Jobs\PrepareKnowledgeChunkSyncJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
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
            'excluded-document',
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
        $sourceSetHash = hash('sha256', 'complete governed source set');
        File::put($this->root.'/run_summary.json', json_encode([
            'schema_version' => 1,
            'brand_key' => 'lightinthebox',
            'site_key' => 'lightinthebox-main',
            'source_set_hash' => $sourceSetHash,
            'artifact_hashes' => [
                'manifest.jsonl' => hash_file('sha256', $this->root.'/manifest.jsonl'),
                'duplicate_audit.jsonl' => hash_file('sha256', $this->root.'/duplicate_audit.jsonl'),
                'exclusion_audit.jsonl' => hash_file('sha256', $this->root.'/exclusion_audit.jsonl'),
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $this->artisan('geoflow:knowledge:import-governed', [
            'manifest' => $manifest,
            '--keyword-library' => 42,
        ])->assertSuccessful();

        $this->assertSame($sourceSetHash, DB::table('knowledge_import_batches')->sole()->source_checksum);
    }

    public function test_invalid_ownership_in_a_later_manifest_row_leaves_the_whole_database_unchanged(): void
    {
        $records = [$this->documentRecord(), [
            ...$this->documentRecord('foreign-document', 'cleaned/brand-company/foreign-document.md'),
            'brand_key' => 'another-brand',
            'asset_key' => 'knowledge:foreign-document',
            'normalized_content_hash' => hash('sha256', 'foreign normalized content'),
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

    /**
     * @param  list<array<string, mixed>>|null  $records
     * @param  list<array<string, mixed>>|null  $duplicates
     */
    private function writePackage(?array $records = null, ?array $duplicates = null): string
    {
        $records ??= [$this->documentRecord()];
        foreach ($records as &$record) {
            $path = $this->root.'/'.$record['cleaned_file_path'];
            File::ensureDirectoryExists(dirname($path));
            $content = "---\ndocument_id: \"{$record['document_id']}\"\n---\n\n# Returns\n\nReturn eligible items within 30 days.\n";
            File::put($path, $content);
            $record['cleaned_file_hash'] = hash('sha256', $content);
        }
        unset($record);

        $duplicates ??= [
            [
                'kind' => 'exact',
                'source_file_path' => 'LightInTheBox_PT_Official_KB_TXT/03_Company_Policies/Returns.txt',
                'source_file_hash' => hash('sha256', 'txt mirror'),
                'normalized_content_hash' => $records[0]['normalized_content_hash'],
                'duplicate_of_source_file_path' => $records[0]['source_file_path'],
                'duplicate_of_document_id' => $records[0]['document_id'],
                'reason' => 'markdown_over_txt',
            ],
            [
                'kind' => 'exact',
                'source_file_path' => 'LightInTheBox_PT_Official_KB_TXT/01_Company_Info/Excluded.txt',
                'source_file_hash' => hash('sha256', 'excluded txt mirror'),
                'normalized_content_hash' => hash('sha256', 'excluded normalized'),
                'duplicate_of_source_file_path' => 'LightInTheBox_PT_Official_KB/01_Company_Info/Excluded.md',
                'duplicate_of_document_id' => 'excluded-document',
                'reason' => 'markdown_over_txt',
            ],
        ];

        $this->writeJsonLines($this->root.'/manifest.jsonl', $records);
        $this->writeJsonLines($this->root.'/duplicate_audit.jsonl', $duplicates);
        $this->writeJsonLines($this->root.'/exclusion_audit.jsonl', [[
            'document_id' => 'excluded-document',
            'canonical_document_key' => 'path:excluded',
            'source_file_path' => 'LightInTheBox_PT_Official_KB/01_Company_Info/Excluded.md',
            'source_file_hash' => hash('sha256', 'excluded markdown'),
            'normalized_content_hash' => hash('sha256', 'excluded normalized'),
            'language_code' => 'pt',
            'reason' => 'non_english_initial_corpus',
            'source_kind' => 'canonical_md',
            'warnings' => [],
        ]]);

        return $this->root.'/manifest.jsonl';
    }

    /** @return array<string, mixed> */
    private function documentRecord(
        string $documentId = 'returns-document',
        string $cleanedPath = 'cleaned/policy-compliance/returns-document.md',
    ): array {
        return [
            'document_id' => $documentId,
            'asset_key' => 'knowledge:'.$documentId,
            'canonical_document_key' => 'url:https://www.lightinthebox.com/returns',
            'version_label' => 'source-20260819',
            'lifecycle_status' => 'active',
            'brand_key' => 'lightinthebox',
            'site_key' => 'lightinthebox-main',
            'directory_path' => 'policy-compliance',
            'language_code' => 'en',
            'authority_level' => 'first_party',
            'risk_level' => 'high',
            'review_status' => 'reviewed',
            'generation_eligible' => true,
            'citation_required' => true,
            'title' => 'Returns',
            'source_name' => 'LightInTheBox official knowledge base',
            'source_url' => 'https://www.lightinthebox.com/returns',
            'source_type' => 'official_web_page',
            'source_file_path' => 'LightInTheBox_PT_Official_KB/03_Company_Policies/Returns.md',
            'source_file_hash' => hash('sha256', 'returns source'),
            'normalized_content_hash' => hash('sha256', '# Returns\n\nReturn eligible items within 30 days.\n'),
            'ingestion_method' => 'read_only_folder',
            'retrieved_at' => '2026-08-19',
            'effective_date' => '2026-08-01',
            'review_due_at' => '2027-08-01',
            'warnings' => [],
            'cleaned_file_path' => $cleanedPath,
            'keyword_node_ids' => [$this->keywordNodeId],
            'keyword_cluster_ids' => [$this->keywordClusterId],
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
