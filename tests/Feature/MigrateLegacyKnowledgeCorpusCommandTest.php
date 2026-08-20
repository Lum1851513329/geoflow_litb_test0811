<?php

namespace Tests\Feature;

use App\Models\KnowledgeBase;
use App\Models\KnowledgeBrand;
use App\Models\KnowledgeDirectory;
use App\Models\KnowledgeImportBatch;
use App\Models\KnowledgeImportRow;
use App\Models\KnowledgeSite;
use App\Models\Task;
use App\Services\GeoFlow\KnowledgeGovernance\LegacyKnowledgeMigrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MigrateLegacyKnowledgeCorpusCommandTest extends TestCase
{
    use RefreshDatabase;

    private const MATCHING_HASH_SENTINEL = '__use_matching_source_hash__';

    public function test_dry_run_reports_the_plan_without_writing_to_the_database(): void
    {
        [$batch, $canonical, $legacy] = $this->seedMatchedPair();
        $task = Task::query()->create([
            'name' => 'Referenced task',
            'status' => 'paused',
            'knowledge_base_id' => $legacy->id,
        ]);

        $this->artisan('geoflow:knowledge:migrate-legacy', [
            '--batch' => $batch->id,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame((int) $legacy->id, (int) $task->fresh()->knowledge_base_id);
        $this->assertDatabaseHas('knowledge_bases', ['id' => $legacy->id, 'lifecycle_status' => 'active']);
        $this->assertNull(KnowledgeImportBatch::query()->whereKey($batch->id)->value('rollback_metadata'));
    }

    public function test_dry_run_plan_contains_the_json_rollback_mapping_with_reference_counts(): void
    {
        [$batch, $canonical, $legacy] = $this->seedMatchedPair();
        Task::query()->create([
            'name' => 'Referenced task',
            'status' => 'paused',
            'knowledge_base_id' => $legacy->id,
        ]);

        $payload = $this->app->make(LegacyKnowledgeMigrationService::class)->plan((int) $batch->id);

        $this->assertSame('dry_run', $payload['status']);
        $this->assertCount(1, $payload['mapped']);
        $this->assertSame((int) $legacy->id, $payload['mapped'][0]['legacy_knowledge_base_id']);
        $this->assertSame((int) $canonical->id, $payload['mapped'][0]['canonical_knowledge_base_id']);
        $this->assertSame(1, $payload['mapped'][0]['reference_counts']['tasks.knowledge_base_id']);
        // A JSON-encodable, plan-shaped payload is exactly what the command prints to stdout.
        $this->assertJson(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_commit_requires_backup_verified_and_makes_no_writes_without_it(): void
    {
        [$batch, $canonical, $legacy] = $this->seedMatchedPair();

        $this->artisan('geoflow:knowledge:migrate-legacy', [
            '--batch' => $batch->id,
        ])->assertFailed();

        $this->assertDatabaseHas('knowledge_bases', ['id' => $legacy->id]);
        $this->assertNull(KnowledgeImportBatch::query()->whereKey($batch->id)->value('rollback_metadata'));
    }

    public function test_commit_migrates_tasks_reference_and_deletes_the_unreferenced_legacy_duplicate(): void
    {
        [$batch, $canonical, $legacy] = $this->seedMatchedPair();
        $task = Task::query()->create([
            'name' => 'Referenced task',
            'status' => 'paused',
            'knowledge_base_id' => $legacy->id,
        ]);

        $this->artisan('geoflow:knowledge:migrate-legacy', [
            '--batch' => $batch->id,
            '--backup-verified' => true,
        ])->assertSuccessful();

        $this->assertSame((int) $canonical->id, (int) $task->fresh()->knowledge_base_id);
        $this->assertDatabaseMissing('knowledge_bases', ['id' => $legacy->id]);
        $decoded = KnowledgeImportBatch::query()->whereKey($batch->id)->value('rollback_metadata');
        $this->assertSame((int) $legacy->id, $decoded['legacy_reference_migration']['mapped'][0]['legacy_knowledge_base_id']);
        $this->assertSame('deleted', $decoded['legacy_reference_migration']['mapped'][0]['action']);
    }

    public function test_commit_reassigns_the_task_knowledge_bases_pivot_and_preserves_pivot_uniqueness(): void
    {
        [$batch, $canonical, $legacy] = $this->seedMatchedPair();
        $taskWithOnlyLegacyLink = Task::query()->create(['name' => 'Task A', 'status' => 'paused']);
        $taskWithBothLinks = Task::query()->create(['name' => 'Task B', 'status' => 'paused']);

        DB::table('task_knowledge_bases')->insert([
            'task_id' => $taskWithOnlyLegacyLink->id,
            'knowledge_base_id' => $legacy->id,
            'sort_order' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Task B already links to the canonical document AND still carries a stale legacy pivot
        // row (e.g. from before the governed import existed). Reassigning the stale row naively
        // would violate the (task_id, knowledge_base_id) unique constraint; it must be deduped away.
        DB::table('task_knowledge_bases')->insert([
            ['task_id' => $taskWithBothLinks->id, 'knowledge_base_id' => $canonical->id, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['task_id' => $taskWithBothLinks->id, 'knowledge_base_id' => $legacy->id, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->artisan('geoflow:knowledge:migrate-legacy', [
            '--batch' => $batch->id,
            '--backup-verified' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('task_knowledge_bases', [
            'task_id' => $taskWithOnlyLegacyLink->id,
            'knowledge_base_id' => $canonical->id,
        ]);
        $this->assertDatabaseMissing('task_knowledge_bases', ['knowledge_base_id' => $legacy->id]);
        $this->assertSame(
            1,
            DB::table('task_knowledge_bases')->where('task_id', $taskWithBothLinks->id)->count(),
        );
        $this->assertDatabaseMissing('knowledge_bases', ['id' => $legacy->id]);
    }

    public function test_discovers_other_reference_tables_generically_and_reassigns_them(): void
    {
        [$batch, $canonical, $legacy] = $this->seedMatchedPair();
        $clusterId = $this->createKeywordCluster($legacy->id);

        $payload = $this->app->make(LegacyKnowledgeMigrationService::class)->plan((int) $batch->id);
        $discoveredTables = array_column($payload['reference_tables'], 'table');
        $this->assertContains('keyword_clusters', $discoveredTables);
        $this->assertContains('task_knowledge_bases', $discoveredTables);
        $this->assertContains('tasks', $discoveredTables);

        $this->artisan('geoflow:knowledge:migrate-legacy', [
            '--batch' => $batch->id,
            '--backup-verified' => true,
        ])->assertSuccessful();

        $this->assertSame((int) $canonical->id, (int) DB::table('keyword_clusters')->where('id', $clusterId)->value('knowledge_base_id'));
        $this->assertDatabaseMissing('knowledge_bases', ['id' => $legacy->id]);
    }

    public function test_archives_instead_of_deleting_when_a_discovered_reference_cannot_be_safely_reassigned(): void
    {
        [$batch, $canonical, $legacy] = $this->seedMatchedPair();
        $clusterId = $this->createKeywordCluster($legacy->id);
        $task = Task::query()->create([
            'name' => 'Referenced task',
            'status' => 'paused',
            'knowledge_base_id' => $legacy->id,
        ]);
        // Simulate an unforeseen conflict when reassigning the generically-discovered
        // keyword_clusters reference (e.g. a business rule this service cannot know about).
        DB::unprepared(<<<'SQL'
CREATE TRIGGER legacy_migration_force_unsafe_reassignment
BEFORE UPDATE OF knowledge_base_id ON keyword_clusters
FOR EACH ROW WHEN NEW.knowledge_base_id != OLD.knowledge_base_id
BEGIN
    SELECT RAISE(ABORT, 'simulated unsafe reassignment conflict');
END
SQL);

        $this->artisan('geoflow:knowledge:migrate-legacy', [
            '--batch' => $batch->id,
            '--backup-verified' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('knowledge_bases', ['id' => $legacy->id, 'lifecycle_status' => 'archived']);
        // Because the whole per-row reassignment attempt is one nested transaction, the task
        // reassignment that WOULD have succeeded is rolled back too, leaving the archived row's
        // other references exactly as they were rather than partially migrated.
        $this->assertSame((int) $legacy->id, (int) $task->fresh()->knowledge_base_id);
        $this->assertSame((int) $legacy->id, (int) DB::table('keyword_clusters')->where('id', $clusterId)->value('knowledge_base_id'));

        $decoded = KnowledgeImportBatch::query()->whereKey($batch->id)->value('rollback_metadata');
        $this->assertSame('archived', $decoded['legacy_reference_migration']['mapped'][0]['action']);
        $this->assertStringContainsString(
            'simulated unsafe reassignment conflict',
            $decoded['legacy_reference_migration']['mapped'][0]['reassignment_error'],
        );
    }

    public function test_legacy_row_without_confident_identity_is_left_untouched_and_reported_unmapped(): void
    {
        [$batch, $canonical, $legacy] = $this->seedMatchedPair(legacyHash: null);

        $payload = $this->app->make(LegacyKnowledgeMigrationService::class)->plan((int) $batch->id);
        $this->assertSame([], $payload['mapped']);
        $this->assertCount(1, $payload['unmapped']);
        $this->assertSame((int) $legacy->id, $payload['unmapped'][0]['legacy_knowledge_base_id']);
        $this->assertSame('legacy_row_missing_source_file_hash', $payload['unmapped'][0]['reason']);

        $this->artisan('geoflow:knowledge:migrate-legacy', [
            '--batch' => $batch->id,
            '--backup-verified' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('knowledge_bases', ['id' => $legacy->id, 'lifecycle_status' => 'active']);
    }

    public function test_legacy_row_whose_content_hash_diverges_from_the_path_match_is_left_untouched(): void
    {
        [$batch, $canonical, $legacy] = $this->seedMatchedPair(legacyHash: hash('sha256', 'different bytes'));

        $this->artisan('geoflow:knowledge:migrate-legacy', [
            '--batch' => $batch->id,
            '--backup-verified' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('knowledge_bases', ['id' => $legacy->id, 'lifecycle_status' => 'active']);
    }

    public function test_rerunning_commit_after_a_successful_migration_is_a_safe_no_op(): void
    {
        [$batch, $canonical, $legacy] = $this->seedMatchedPair();
        Task::query()->create([
            'name' => 'Referenced task',
            'status' => 'paused',
            'knowledge_base_id' => $legacy->id,
        ]);

        $arguments = ['--batch' => $batch->id, '--backup-verified' => true];
        $this->artisan('geoflow:knowledge:migrate-legacy', $arguments)->assertSuccessful();
        $this->artisan('geoflow:knowledge:migrate-legacy', $arguments)->assertSuccessful();

        $this->assertDatabaseMissing('knowledge_bases', ['id' => $legacy->id]);
        $this->assertDatabaseCount('knowledge_bases', 1);
    }

    /** @return array{KnowledgeImportBatch,KnowledgeBase,KnowledgeBase} */
    private function seedMatchedPair(?string $legacyHash = self::MATCHING_HASH_SENTINEL): array
    {
        $brand = KnowledgeBrand::query()->create(['key' => 'lightinthebox', 'name' => 'LightInTheBox']);
        $site = KnowledgeSite::query()->create([
            'brand_id' => $brand->id,
            'key' => 'lightinthebox-main',
            'canonical_host' => 'www.lightinthebox.com',
            'role' => 'commerce-primary',
        ]);
        $directory = KnowledgeDirectory::query()->create([
            'brand_id' => $brand->id,
            'key' => 'policy-compliance',
            'path' => 'policy-compliance',
            'name' => 'Policy & Compliance',
        ]);

        $sourcePath = 'LightInTheBox_PT_Official_KB/policy-compliance/returns.md';
        $sourceHash = hash('sha256', 'source-bytes-returns');

        $canonical = KnowledgeBase::query()->create([
            'asset_key' => 'knowledge:'.str_repeat('b', 32),
            'name' => 'Returns',
            'content' => "# Returns\n\nReturn eligible items within 30 days.\n",
            'file_type' => 'markdown',
            'brand_id' => $brand->id,
            'site_id' => $site->id,
            'primary_directory_id' => $directory->id,
            'canonical_document_key' => 'url:https://www.lightinthebox.com/returns',
            'language_code' => 'en',
            'lifecycle_status' => 'active',
            'source_file_path' => $sourcePath,
            'source_file_hash' => $sourceHash,
            'normalized_content_hash' => hash('sha256', "# Returns\n\nReturn eligible items within 30 days.\n"),
        ]);

        $legacy = KnowledgeBase::query()->create([
            'asset_key' => 'knowledge:'.str_repeat('a', 32),
            'name' => 'Old Returns',
            'content' => 'Legacy pre-governance content.',
            'file_type' => 'markdown',
            'source_file_path' => $sourcePath,
            'source_file_hash' => $legacyHash === self::MATCHING_HASH_SENTINEL ? $sourceHash : $legacyHash,
        ]);

        $batch = KnowledgeImportBatch::query()->create([
            'brand_id' => $brand->id,
            'site_id' => $site->id,
            'source_type' => 'governed_manifest',
            'status' => 'completed',
            'configuration_hash' => hash('sha256', 'config'),
            'source_checksum' => hash('sha256', 'source-set'),
        ]);

        KnowledgeImportRow::query()->create([
            'brand_id' => $brand->id,
            'site_id' => $site->id,
            'batch_id' => $batch->id,
            'row_number' => 1,
            'staged_payload' => [
                'document_id' => str_repeat('b', 32),
                'source_file_path' => $sourcePath,
                'source_file_hash' => $sourceHash,
            ],
            'outcome' => 'imported',
            'canonical_document_id' => $canonical->id,
        ]);

        return [$batch, $canonical, $legacy];
    }

    private function createKeywordCluster(int $knowledgeBaseId): int
    {
        $libraryId = DB::table('keyword_libraries')->insertGetId([
            'name' => 'Test keyword library',
            'description' => '',
            'keyword_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $keywordId = DB::table('keywords')->insertGetId([
            'library_id' => $libraryId,
            'keyword' => 'return policy '.uniqid('', true),
            'normalized_keyword' => 'return policy '.uniqid('', true),
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

        return DB::table('keyword_clusters')->insertGetId([
            'name' => 'Return policy',
            'slug' => 'return-policy-'.uniqid('', true),
            'taxonomy_node_id' => null,
            'primary_keyword_id' => $keywordId,
            'language_code' => 'en',
            'market_scope' => 'GLOBAL',
            'search_intent' => 'informational',
            'knowledge_base_id' => $knowledgeBaseId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
