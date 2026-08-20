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
 * This migration is purely additive and idempotent: every operation is
 * guarded by an existence check, so it is a safe no-op in any environment
 * (CI, fresh installs) where 2026_08_19_000400...'s current content already
 * applied in full.
 */
return new class extends Migration
{
    private const CLAIM_INDEX = 'knowledge_import_rows_dispatch_claim_index';

    public function up(): void
    {
        if (! Schema::hasColumn('knowledge_import_rows', 'dispatch_claim_token')) {
            Schema::table('knowledge_import_rows', function (Blueprint $table): void {
                $table->string('dispatch_claim_token', 36)->nullable();
            });
        }

        if (! Schema::hasColumn('knowledge_import_rows', 'dispatch_claimed_at')) {
            Schema::table('knowledge_import_rows', function (Blueprint $table): void {
                $table->timestamp('dispatch_claimed_at')->nullable();
            });
        }

        if (! Schema::hasColumn('knowledge_import_rows', 'dispatch_attempt_count')) {
            Schema::table('knowledge_import_rows', function (Blueprint $table): void {
                $table->unsignedInteger('dispatch_attempt_count')->default(0);
            });
        }

        if (! $this->hasIndex(self::CLAIM_INDEX)) {
            Schema::table('knowledge_import_rows', function (Blueprint $table): void {
                $table->index(['outcome', 'dispatch_claimed_at'], self::CLAIM_INDEX);
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndex(self::CLAIM_INDEX)) {
            Schema::table('knowledge_import_rows', function (Blueprint $table): void {
                $table->dropIndex(self::CLAIM_INDEX);
            });
        }

        foreach (['dispatch_attempt_count', 'dispatch_claimed_at', 'dispatch_claim_token'] as $column) {
            if (Schema::hasColumn('knowledge_import_rows', $column)) {
                Schema::table('knowledge_import_rows', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
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
