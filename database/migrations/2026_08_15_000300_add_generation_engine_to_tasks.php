<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tasks')) {
            return;
        }

        Schema::table('tasks', function (Blueprint $table): void {
            if (! Schema::hasColumn('tasks', 'generation_engine')) {
                $table->string('generation_engine', 20)->default('legacy')->after('prompt_id');
            }
            if (! Schema::hasColumn('tasks', 'prompt_preset_id')) {
                $table->foreignId('prompt_preset_id')->nullable()->after('generation_engine')->constrained('prompt_presets')->nullOnDelete();
            }
        });

        DB::table('tasks')->whereNull('generation_engine')->update(['generation_engine' => 'legacy']);

        // `tasks.prompt_id` is NOT NULL in the real Postgres schema
        // (`database/migrations/2026_04_18_120000_geoflow_legacy_schema.php:245`) — a
        // quality_gated Task has no single free-text Prompt (it uses `prompt_preset_id`
        // instead), so the column must become nullable for such Tasks to be creatable.
        //
        // This is Postgres-specific raw SQL. On SQLite (used for the test suite —
        // see `database/migrations/2026_04_18_120002_sqlite_geoflow_minimal_for_testing.php`),
        // `tasks.prompt_id` is already declared nullable, so there is nothing to relax
        // and this statement must be skipped entirely (SQLite has no equivalent
        // `ALTER COLUMN ... DROP NOT NULL` syntax). This mirrors the driver-guard
        // pattern used by
        // `database/migrations/2026_04_25_140000_make_articles_task_id_set_null_on_task_delete.php`.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE tasks ALTER COLUMN prompt_id DROP NOT NULL');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('tasks')) {
            return;
        }

        Schema::table('tasks', function (Blueprint $table): void {
            if (Schema::hasColumn('tasks', 'prompt_preset_id')) {
                $table->dropConstrainedForeignId('prompt_preset_id');
            }
            if (Schema::hasColumn('tasks', 'generation_engine')) {
                $table->dropColumn('generation_engine');
            }
        });

        // Deliberately does not restore prompt_id's NOT NULL constraint — any
        // quality_gated Task created while this migration was applied would have
        // a null prompt_id, and re-adding NOT NULL here would fail outright, or
        // silently corrupt data if backfilled with a fake Prompt reference. Rolling
        // back this feature is expected to also mean deleting or migrating those
        // Tasks first, not something this migration should attempt automatically.
    }
};
