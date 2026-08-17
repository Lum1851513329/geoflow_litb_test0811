<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('prompt_preset_versions')) {
            return;
        }

        if (! Schema::hasColumn('prompt_preset_versions', 'blueprint_template')) {
            Schema::table('prompt_preset_versions', function (Blueprint $table): void {
                $table->jsonb('blueprint_template')->nullable()->after('conflict_policy');
            });
        }
        if (! Schema::hasColumn('prompt_preset_versions', 'default_brief_fields')) {
            Schema::table('prompt_preset_versions', function (Blueprint $table): void {
                $table->jsonb('default_brief_fields')->nullable()->after('blueprint_template');
            });
        }

        $this->recreateGuardTriggers();
    }

    public function down(): void
    {
        if (! Schema::hasTable('prompt_preset_versions')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS prompt_preset_versions_guard_update ON prompt_preset_versions');
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS prompt_preset_versions_guard_update');
        }

        Schema::table('prompt_preset_versions', function (Blueprint $table): void {
            if (Schema::hasColumn('prompt_preset_versions', 'default_brief_fields')) {
                $table->dropColumn('default_brief_fields');
            }
            if (Schema::hasColumn('prompt_preset_versions', 'blueprint_template')) {
                $table->dropColumn('blueprint_template');
            }
        });

        $this->recreateGuardTriggersWithoutNewColumns();
    }

    private function recreateGuardTriggers(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS prompt_preset_versions_guard_update ON prompt_preset_versions');
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_prompt_preset_version_immutable() RETURNS trigger AS $$
BEGIN
    IF NEW.prompt_preset_id IS DISTINCT FROM OLD.prompt_preset_id
        OR NEW.version IS DISTINCT FROM OLD.version
        OR NEW.matching_rules IS DISTINCT FROM OLD.matching_rules
        OR NEW.generation_policy IS DISTINCT FROM OLD.generation_policy
        OR NEW.conflict_policy IS DISTINCT FROM OLD.conflict_policy
        OR NEW.blueprint_template IS DISTINCT FROM OLD.blueprint_template
        OR NEW.default_brief_fields IS DISTINCT FROM OLD.default_brief_fields
        OR NEW.created_by_admin_id IS DISTINCT FROM OLD.created_by_admin_id THEN
        RAISE EXCEPTION 'prompt preset version snapshots are immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER prompt_preset_versions_guard_update
BEFORE UPDATE ON prompt_preset_versions
FOR EACH ROW EXECUTE FUNCTION guard_prompt_preset_version_immutable();
SQL);
        } elseif ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS prompt_preset_versions_guard_update');
            DB::unprepared(<<<'SQL'
CREATE TRIGGER prompt_preset_versions_guard_update
BEFORE UPDATE ON prompt_preset_versions
FOR EACH ROW WHEN NEW.prompt_preset_id IS NOT OLD.prompt_preset_id OR NEW.version IS NOT OLD.version OR NEW.matching_rules IS NOT OLD.matching_rules OR NEW.generation_policy IS NOT OLD.generation_policy OR NEW.conflict_policy IS NOT OLD.conflict_policy OR NEW.blueprint_template IS NOT OLD.blueprint_template OR NEW.default_brief_fields IS NOT OLD.default_brief_fields OR NEW.created_by_admin_id IS NOT OLD.created_by_admin_id
BEGIN SELECT RAISE(ABORT, 'prompt preset version snapshots are immutable'); END;
SQL);
        }
    }

    private function recreateGuardTriggersWithoutNewColumns(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS prompt_preset_versions_guard_update ON prompt_preset_versions');
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_prompt_preset_version_immutable() RETURNS trigger AS $$
BEGIN
    IF NEW.prompt_preset_id IS DISTINCT FROM OLD.prompt_preset_id
        OR NEW.version IS DISTINCT FROM OLD.version
        OR NEW.matching_rules IS DISTINCT FROM OLD.matching_rules
        OR NEW.generation_policy IS DISTINCT FROM OLD.generation_policy
        OR NEW.conflict_policy IS DISTINCT FROM OLD.conflict_policy
        OR NEW.created_by_admin_id IS DISTINCT FROM OLD.created_by_admin_id THEN
        RAISE EXCEPTION 'prompt preset version snapshots are immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER prompt_preset_versions_guard_update
BEFORE UPDATE ON prompt_preset_versions
FOR EACH ROW EXECUTE FUNCTION guard_prompt_preset_version_immutable();
SQL);
        } elseif ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS prompt_preset_versions_guard_update');
            DB::unprepared(<<<'SQL'
CREATE TRIGGER prompt_preset_versions_guard_update
BEFORE UPDATE ON prompt_preset_versions
FOR EACH ROW WHEN NEW.prompt_preset_id IS NOT OLD.prompt_preset_id OR NEW.version IS NOT OLD.version OR NEW.matching_rules IS NOT OLD.matching_rules OR NEW.generation_policy IS NOT OLD.generation_policy OR NEW.conflict_policy IS NOT OLD.conflict_policy OR NEW.created_by_admin_id IS NOT OLD.created_by_admin_id
BEGIN SELECT RAISE(ABORT, 'prompt preset version snapshots are immutable'); END;
SQL);
        }
    }
};
