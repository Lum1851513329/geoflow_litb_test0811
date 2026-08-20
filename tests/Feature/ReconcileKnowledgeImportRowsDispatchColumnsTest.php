<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Covers the 2026_08_20_000100_reconcile_knowledge_import_rows_dispatch_columns
 * migration, added to fix environment-specific drift where
 * 2026_08_19_000400_harden_governed_knowledge_import_batches.php was edited
 * (adding the knowledge_import_rows dispatch-claim block) after some
 * environments had already run its earlier content and recorded the
 * migration as applied.
 *
 * RefreshDatabase runs the full migration set against a fresh sqlite
 * :memory: schema, so by the time this test runs,
 * 2026_08_19_000400...'s current (already-fixed) content has already
 * created the columns and index. Re-running the reconciliation migration
 * here therefore exercises exactly the "already applied, must no-op
 * safely" path -- the only case coverable in the test suite, since the
 * "columns missing" drift only exists in the one already-drifted dev
 * database this migration was written to repair.
 */
final class ReconcileKnowledgeImportRowsDispatchColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_columns_and_index_exist_with_expected_shape_after_migration(): void
    {
        $this->assertTrue(Schema::hasColumns('knowledge_import_rows', [
            'dispatch_claim_token',
            'dispatch_claimed_at',
            'dispatch_attempt_count',
        ]));

        $this->assertTrue($this->hasIndex('knowledge_import_rows_dispatch_claim_index'));

        ['brand_id' => $brandId, 'site_id' => $siteId, 'batch_id' => $batchId] = $this->seedBatch();

        DB::table('knowledge_import_rows')->insert([
            'brand_id' => $brandId,
            'site_id' => $siteId,
            'batch_id' => $batchId,
            'row_number' => 1,
            'staged_payload' => json_encode(['source_file' => 'company.txt'], JSON_THROW_ON_ERROR),
            'outcome' => 'imported',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('knowledge_import_rows')->first();

        $this->assertNull($row->dispatch_claim_token);
        $this->assertNull($row->dispatch_claimed_at);
        $this->assertSame(0, (int) $row->dispatch_attempt_count);

        $claimToken = (string) str()->uuid();
        DB::table('knowledge_import_rows')
            ->where('id', $row->id)
            ->update([
                'dispatch_claim_token' => $claimToken,
                'dispatch_claimed_at' => now(),
                'dispatch_attempt_count' => 1,
            ]);

        $claimed = DB::table('knowledge_import_rows')->first();

        $this->assertSame($claimToken, $claimed->dispatch_claim_token);
        $this->assertNotNull($claimed->dispatch_claimed_at);
        $this->assertSame(1, (int) $claimed->dispatch_attempt_count);
    }

    public function test_running_the_migration_again_against_an_already_reconciled_schema_is_a_safe_no_op(): void
    {
        $migration = require database_path(
            'migrations/2026_08_20_000100_reconcile_knowledge_import_rows_dispatch_columns.php',
        );

        // The schema already has these columns/index courtesy of
        // 2026_08_19_000400...'s current content, applied earlier during
        // RefreshDatabase's migration run. Running up() again must not
        // throw (e.g. "column already exists" / "duplicate index").
        $migration->up();

        $this->assertTrue(Schema::hasColumns('knowledge_import_rows', [
            'dispatch_claim_token',
            'dispatch_claimed_at',
            'dispatch_attempt_count',
        ]));
        $this->assertTrue($this->hasIndex('knowledge_import_rows_dispatch_claim_index'));

        // down() should also be idempotent: it drops what it finds, and a
        // second call must not throw ("column doesn't exist").
        $migration->down();

        $this->assertFalse(Schema::hasColumn('knowledge_import_rows', 'dispatch_claim_token'));
        $this->assertFalse(Schema::hasColumn('knowledge_import_rows', 'dispatch_claimed_at'));
        $this->assertFalse(Schema::hasColumn('knowledge_import_rows', 'dispatch_attempt_count'));
        $this->assertFalse($this->hasIndex('knowledge_import_rows_dispatch_claim_index'));

        $migration->down();

        // Restore the columns so later assertions / teardown see the
        // schema the rest of the suite expects.
        $migration->up();

        $this->assertTrue(Schema::hasColumns('knowledge_import_rows', [
            'dispatch_claim_token',
            'dispatch_claimed_at',
            'dispatch_attempt_count',
        ]));
        $this->assertTrue($this->hasIndex('knowledge_import_rows_dispatch_claim_index'));
    }

    private function hasIndex(string $name): bool
    {
        foreach (Schema::getIndexes('knowledge_import_rows') as $index) {
            if (($index['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{brand_id: int, site_id: int, batch_id: int}
     */
    private function seedBatch(): array
    {
        $brandId = DB::table('knowledge_brands')->insertGetId([
            'key' => 'reconcile-test-brand',
            'name' => 'Reconcile Test Brand',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $siteId = DB::table('knowledge_sites')->insertGetId([
            'brand_id' => $brandId,
            'key' => 'reconcile-test-site',
            'canonical_host' => 'reconcile-test.example',
            'role' => 'commerce-primary',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $batchId = DB::table('knowledge_import_batches')->insertGetId([
            'brand_id' => $brandId,
            'site_id' => $siteId,
            'source_type' => 'folder',
            'status' => 'completed',
            'configuration_hash' => str_repeat('a', 64),
            'source_checksum' => str_repeat('b', 64),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['brand_id' => $brandId, 'site_id' => $siteId, 'batch_id' => $batchId];
    }
}
