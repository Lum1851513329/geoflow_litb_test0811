<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dropRunUpdateGuard();
        Schema::table('content_generation_runs', function (Blueprint $table): void {
            $table->longText('compiled_prompt')->nullable()->change();
            $table->string('status', 40)->default('pending')->change();
        });
        $this->createOneTimeSnapshotGuard();
        $this->restoreDeleteGuard();
    }

    public function down(): void
    {
        $this->dropRunUpdateGuard();
        DB::table('content_generation_runs')->whereNull('compiled_prompt')->update(['compiled_prompt' => '']);
        Schema::table('content_generation_runs', function (Blueprint $table): void {
            $table->longText('compiled_prompt')->nullable(false)->change();
            $table->string('status', 40)->default('queued')->change();
        });
        $this->createOriginalSnapshotGuard();
        $this->restoreDeleteGuard();
    }

    private function dropRunUpdateGuard(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS content_generation_runs_guard_update ON content_generation_runs');
            DB::unprepared('DROP FUNCTION IF EXISTS guard_content_generation_run_immutable()');

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS content_generation_runs_guard_update');
        }
    }

    private function createOneTimeSnapshotGuard(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE FUNCTION guard_content_generation_run_immutable() RETURNS trigger AS $$
BEGIN
    IF NEW.content_brief_id IS DISTINCT FROM OLD.content_brief_id
        OR NEW.strategy_version_id IS DISTINCT FROM OLD.strategy_version_id
        OR NEW.blueprint_version_id IS DISTINCT FROM OLD.blueprint_version_id
        OR NEW.preset_version_id IS DISTINCT FROM OLD.preset_version_id
        OR NEW.ai_model_id IS DISTINCT FROM OLD.ai_model_id
        OR (OLD.compiled_prompt IS NOT NULL AND NEW.compiled_prompt IS DISTINCT FROM OLD.compiled_prompt)
        OR (OLD.variables_snapshot IS NOT NULL AND NEW.variables_snapshot IS DISTINCT FROM OLD.variables_snapshot)
        OR (OLD.model_snapshot IS NOT NULL AND NEW.model_snapshot IS DISTINCT FROM OLD.model_snapshot)
        OR (OLD.generation_parameters IS NOT NULL AND NEW.generation_parameters IS DISTINCT FROM OLD.generation_parameters)
        OR (OLD.status NOT IN ('pending', 'queued') AND (
            NEW.compiled_prompt IS DISTINCT FROM OLD.compiled_prompt
            OR NEW.variables_snapshot IS DISTINCT FROM OLD.variables_snapshot
            OR NEW.model_snapshot IS DISTINCT FROM OLD.model_snapshot
            OR NEW.generation_parameters IS DISTINCT FROM OLD.generation_parameters
        ))
        OR NEW.idempotency_key IS DISTINCT FROM OLD.idempotency_key
        OR NEW.created_by_admin_id IS DISTINCT FROM OLD.created_by_admin_id THEN
        RAISE EXCEPTION 'content generation run lineage and input snapshots are immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER content_generation_runs_guard_update
BEFORE UPDATE ON content_generation_runs
FOR EACH ROW EXECUTE FUNCTION guard_content_generation_run_immutable();
SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER content_generation_runs_guard_update
BEFORE UPDATE ON content_generation_runs
FOR EACH ROW WHEN NEW.content_brief_id IS NOT OLD.content_brief_id OR NEW.strategy_version_id IS NOT OLD.strategy_version_id OR NEW.blueprint_version_id IS NOT OLD.blueprint_version_id OR NEW.preset_version_id IS NOT OLD.preset_version_id OR NEW.ai_model_id IS NOT OLD.ai_model_id OR (OLD.compiled_prompt IS NOT NULL AND NEW.compiled_prompt IS NOT OLD.compiled_prompt) OR (OLD.variables_snapshot IS NOT NULL AND NEW.variables_snapshot IS NOT OLD.variables_snapshot) OR (OLD.model_snapshot IS NOT NULL AND NEW.model_snapshot IS NOT OLD.model_snapshot) OR (OLD.generation_parameters IS NOT NULL AND NEW.generation_parameters IS NOT OLD.generation_parameters) OR (OLD.status NOT IN ('pending', 'queued') AND (NEW.compiled_prompt IS NOT OLD.compiled_prompt OR NEW.variables_snapshot IS NOT OLD.variables_snapshot OR NEW.model_snapshot IS NOT OLD.model_snapshot OR NEW.generation_parameters IS NOT OLD.generation_parameters)) OR NEW.idempotency_key IS NOT OLD.idempotency_key OR NEW.created_by_admin_id IS NOT OLD.created_by_admin_id
BEGIN SELECT RAISE(ABORT, 'content generation run lineage and input snapshots are immutable'); END;
SQL);
        }
    }

    private function createOriginalSnapshotGuard(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE FUNCTION guard_content_generation_run_immutable() RETURNS trigger AS $$
BEGIN
    IF NEW.content_brief_id IS DISTINCT FROM OLD.content_brief_id
        OR NEW.strategy_version_id IS DISTINCT FROM OLD.strategy_version_id
        OR NEW.blueprint_version_id IS DISTINCT FROM OLD.blueprint_version_id
        OR NEW.preset_version_id IS DISTINCT FROM OLD.preset_version_id
        OR NEW.ai_model_id IS DISTINCT FROM OLD.ai_model_id
        OR NEW.compiled_prompt IS DISTINCT FROM OLD.compiled_prompt
        OR NEW.variables_snapshot IS DISTINCT FROM OLD.variables_snapshot
        OR NEW.model_snapshot IS DISTINCT FROM OLD.model_snapshot
        OR NEW.generation_parameters IS DISTINCT FROM OLD.generation_parameters
        OR NEW.idempotency_key IS DISTINCT FROM OLD.idempotency_key
        OR NEW.created_by_admin_id IS DISTINCT FROM OLD.created_by_admin_id THEN
        RAISE EXCEPTION 'content generation run lineage and input snapshots are immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER content_generation_runs_guard_update
BEFORE UPDATE ON content_generation_runs
FOR EACH ROW EXECUTE FUNCTION guard_content_generation_run_immutable();
SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER content_generation_runs_guard_update
BEFORE UPDATE ON content_generation_runs
FOR EACH ROW WHEN NEW.content_brief_id IS NOT OLD.content_brief_id OR NEW.strategy_version_id IS NOT OLD.strategy_version_id OR NEW.blueprint_version_id IS NOT OLD.blueprint_version_id OR NEW.preset_version_id IS NOT OLD.preset_version_id OR NEW.ai_model_id IS NOT OLD.ai_model_id OR NEW.compiled_prompt IS NOT OLD.compiled_prompt OR NEW.variables_snapshot IS NOT OLD.variables_snapshot OR NEW.model_snapshot IS NOT OLD.model_snapshot OR NEW.generation_parameters IS NOT OLD.generation_parameters OR NEW.idempotency_key IS NOT OLD.idempotency_key OR NEW.created_by_admin_id IS NOT OLD.created_by_admin_id
BEGIN SELECT RAISE(ABORT, 'content generation run lineage and input snapshots are immutable'); END;
SQL);
        }
    }

    private function restoreDeleteGuard(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS content_generation_runs_guard_delete ON content_generation_runs;
CREATE TRIGGER content_generation_runs_guard_delete
BEFORE DELETE ON content_generation_runs
FOR EACH ROW EXECUTE FUNCTION prevent_content_generation_run_delete();
SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS content_generation_runs_guard_delete;
CREATE TRIGGER content_generation_runs_guard_delete
BEFORE DELETE ON content_generation_runs
FOR EACH ROW BEGIN SELECT RAISE(ABORT, 'content generation runs cannot be deleted'); END;
SQL);
        }
    }
};
