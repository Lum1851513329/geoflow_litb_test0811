<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reconciliation migration for environment-specific drift.
 *
 * 2026_08_19_000400_harden_governed_knowledge_import_batches.php was edited
 * (during review) to add a `knowledge_import_rows` dispatch-claim column
 * block after some environments had already run an earlier version of that
 * file. Laravel tracks applied migrations by filename only, so those
 * environments never re-ran the updated content and are missing the
 * `dispatch_claim_token`, `dispatch_claimed_at`, and `dispatch_attempt_count`
 * columns (and the `knowledge_import_rows_dispatch_claim_index` index) on
 * `knowledge_import_rows`, even though the migration is marked "Ran".
 *
 * up() is purely additive and idempotent: every operation is guarded by an
 * existence check, so it is a safe no-op in any environment (CI, fresh
 * installs) where 2026_08_19_000400...'s current content already applied in
 * full. down() is intentionally a no-op -- see its own doc comment.
 */
return new class extends Migration
{
    private const CLAIM_INDEX = 'knowledge_import_rows_dispatch_claim_index';

    public function up(): void
    {
        Schema::table('knowledge_import_rows', function (Blueprint $table): void {
            if (! Schema::hasColumn('knowledge_import_rows', 'dispatch_claim_token')) {
                $table->string('dispatch_claim_token', 36)->nullable();
            }
            if (! Schema::hasColumn('knowledge_import_rows', 'dispatch_claimed_at')) {
                $table->timestamp('dispatch_claimed_at')->nullable();
            }
            if (! Schema::hasColumn('knowledge_import_rows', 'dispatch_attempt_count')) {
                $table->unsignedInteger('dispatch_attempt_count')->default(0);
            }
        });

        if (! $this->hasIndex(self::CLAIM_INDEX)) {
            Schema::table('knowledge_import_rows', function (Blueprint $table): void {
                $table->index(['outcome', 'dispatch_claimed_at'], self::CLAIM_INDEX);
            });
        }
    }

    /**
     * Intentionally a no-op.
     *
     * This migration only exists to backfill drift in an environment whose
     * `knowledge_import_rows` table is missing objects that
     * 2026_08_19_000400_harden_governed_knowledge_import_batches.php's
     * CURRENT content already creates everywhere else. At rollback time we
     * cannot tell "this migration's up() created these objects" apart from
     * "2026_08_19_000400...'s up() already created them" -- both leave the
     * same columns/index present, with no provenance recorded anywhere.
     *
     * In every normal environment (CI, fresh installs, any machine that is
     * not the one already-drifted dev database), 2026_08_19_000400... and
     * this migration land in the same `migrate` batch, and
     * `migrate:rollback` reverses a batch in order: this migration's
     * down() would run first, then 2026_08_19_000400...'s own down()
     * (which unconditionally drops the same index and columns). If this
     * down() dropped anything, that second call would fail with a
     * Postgres "does not exist" error on a completely ordinary
     * migrate -> rollback cycle.
     *
     * So reversal is deliberately left to 2026_08_19_000400...'s own
     * down(), which already owns dropping these objects. Doing nothing
     * here is the honest, safe answer.
     */
    public function down(): void
    {
        // Intentionally empty -- see doc comment above.
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
};
