<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->dropGuards();
        DB::getDriverName() === 'pgsql' ? $this->createPostgresGuards() : $this->createSqliteGuards();
    }

    public function down(): void
    {
        $this->dropGuards();
        $this->restorePreviousRunGuard();
    }

    private function createPostgresGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE FUNCTION guard_content_generation_run_immutable() RETURNS trigger AS $$
BEGIN
    IF NEW.content_brief_id IS DISTINCT FROM OLD.content_brief_id
        OR NEW.strategy_version_id IS DISTINCT FROM OLD.strategy_version_id
        OR NEW.blueprint_version_id IS DISTINCT FROM OLD.blueprint_version_id
        OR NEW.preset_version_id IS DISTINCT FROM OLD.preset_version_id
        OR NEW.ai_model_id IS DISTINCT FROM OLD.ai_model_id
        OR (OLD.compiled_prompt IS NOT NULL AND NEW.compiled_prompt IS DISTINCT FROM OLD.compiled_prompt)
        OR (OLD.encrypted_model_payload IS NOT NULL AND NEW.encrypted_model_payload IS DISTINCT FROM OLD.encrypted_model_payload)
        OR (OLD.variables_snapshot IS NOT NULL AND NEW.variables_snapshot IS DISTINCT FROM OLD.variables_snapshot)
        OR (OLD.model_snapshot IS NOT NULL AND NEW.model_snapshot IS DISTINCT FROM OLD.model_snapshot)
        OR (OLD.generation_parameters IS NOT NULL AND NEW.generation_parameters IS DISTINCT FROM OLD.generation_parameters)
        OR (OLD.status NOT IN ('pending', 'queued') AND (NEW.compiled_prompt IS DISTINCT FROM OLD.compiled_prompt OR NEW.encrypted_model_payload IS DISTINCT FROM OLD.encrypted_model_payload OR NEW.variables_snapshot IS DISTINCT FROM OLD.variables_snapshot OR NEW.model_snapshot IS DISTINCT FROM OLD.model_snapshot OR NEW.generation_parameters IS DISTINCT FROM OLD.generation_parameters))
        OR NEW.idempotency_key IS DISTINCT FROM OLD.idempotency_key
        OR NEW.request_fingerprint IS DISTINCT FROM OLD.request_fingerprint
        OR NEW.created_by_admin_id IS DISTINCT FROM OLD.created_by_admin_id
        OR (OLD.status IN ('ready_for_review', 'needs_evidence', 'model_quality_failed', 'manual_review_required', 'completed', 'adopted') AND NEW.raw_output IS DISTINCT FROM OLD.raw_output)
        OR (OLD.adopted_output IS NOT NULL AND NEW.adopted_output IS DISTINCT FROM OLD.adopted_output)
        OR (OLD.adopted_output IS NULL AND NEW.adopted_output IS NOT NULL AND NOT (OLD.status = 'ready_for_review' AND NEW.status = 'adopted')) THEN
        RAISE EXCEPTION 'content generation run audit fields are immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER content_generation_runs_guard_update BEFORE UPDATE ON content_generation_runs FOR EACH ROW EXECUTE FUNCTION guard_content_generation_run_immutable();

CREATE FUNCTION prevent_content_audit_mutation() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'content generation audit rows are immutable';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER content_generation_run_modules_guard_update BEFORE UPDATE OR DELETE ON content_generation_run_modules FOR EACH ROW EXECUTE FUNCTION prevent_content_audit_mutation();
CREATE TRIGGER content_generation_run_evidence_guard_update BEFORE UPDATE OR DELETE ON content_generation_run_evidence FOR EACH ROW EXECUTE FUNCTION prevent_content_audit_mutation();
CREATE TRIGGER content_quality_reports_guard_update BEFORE UPDATE OR DELETE ON content_quality_reports FOR EACH ROW EXECUTE FUNCTION prevent_content_audit_mutation();

CREATE FUNCTION guard_content_generation_step_update() RETURNS trigger AS $$
BEGIN
    IF NEW.content_generation_run_id IS DISTINCT FROM OLD.content_generation_run_id
        OR NEW.sequence IS DISTINCT FROM OLD.sequence
        OR NEW.step_type IS DISTINCT FROM OLD.step_type
        OR NEW.prompt IS DISTINCT FROM OLD.prompt
        OR NEW.input_snapshot IS DISTINCT FROM OLD.input_snapshot
        OR OLD.status IN ('completed', 'failed', 'skipped')
        OR NOT ((OLD.status = 'queued' AND NEW.status IN ('running', 'completed', 'failed', 'skipped')) OR (OLD.status = 'running' AND NEW.status IN ('completed', 'failed', 'skipped'))) THEN
        RAISE EXCEPTION 'content generation step history is immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER content_generation_run_steps_guard_update BEFORE UPDATE ON content_generation_run_steps FOR EACH ROW EXECUTE FUNCTION guard_content_generation_step_update();
CREATE TRIGGER content_generation_run_steps_guard_delete BEFORE DELETE ON content_generation_run_steps FOR EACH ROW EXECUTE FUNCTION prevent_content_audit_mutation();

CREATE FUNCTION guard_published_preset_binding() RETURNS trigger AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM prompt_preset_versions WHERE id = OLD.prompt_preset_version_id AND lifecycle_status IN ('production', 'deprecated', 'archived')) THEN
        RAISE EXCEPTION 'published prompt preset bindings are immutable';
    END IF;
    RETURN OLD;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER prompt_preset_bindings_guard_mutation BEFORE UPDATE OR DELETE ON prompt_preset_module_versions FOR EACH ROW EXECUTE FUNCTION guard_published_preset_binding();
SQL);
    }

    private function createSqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER content_generation_runs_guard_update BEFORE UPDATE ON content_generation_runs
FOR EACH ROW WHEN NEW.content_brief_id IS NOT OLD.content_brief_id OR NEW.strategy_version_id IS NOT OLD.strategy_version_id OR NEW.blueprint_version_id IS NOT OLD.blueprint_version_id OR NEW.preset_version_id IS NOT OLD.preset_version_id OR NEW.ai_model_id IS NOT OLD.ai_model_id OR (OLD.compiled_prompt IS NOT NULL AND NEW.compiled_prompt IS NOT OLD.compiled_prompt) OR (OLD.encrypted_model_payload IS NOT NULL AND NEW.encrypted_model_payload IS NOT OLD.encrypted_model_payload) OR (OLD.variables_snapshot IS NOT NULL AND NEW.variables_snapshot IS NOT OLD.variables_snapshot) OR (OLD.model_snapshot IS NOT NULL AND NEW.model_snapshot IS NOT OLD.model_snapshot) OR (OLD.generation_parameters IS NOT NULL AND NEW.generation_parameters IS NOT OLD.generation_parameters) OR (OLD.status NOT IN ('pending', 'queued') AND (NEW.compiled_prompt IS NOT OLD.compiled_prompt OR NEW.encrypted_model_payload IS NOT OLD.encrypted_model_payload OR NEW.variables_snapshot IS NOT OLD.variables_snapshot OR NEW.model_snapshot IS NOT OLD.model_snapshot OR NEW.generation_parameters IS NOT OLD.generation_parameters)) OR NEW.idempotency_key IS NOT OLD.idempotency_key OR NEW.request_fingerprint IS NOT OLD.request_fingerprint OR NEW.created_by_admin_id IS NOT OLD.created_by_admin_id OR (OLD.status IN ('ready_for_review', 'needs_evidence', 'model_quality_failed', 'manual_review_required', 'completed', 'adopted') AND NEW.raw_output IS NOT OLD.raw_output) OR (OLD.adopted_output IS NOT NULL AND NEW.adopted_output IS NOT OLD.adopted_output) OR (OLD.adopted_output IS NULL AND NEW.adopted_output IS NOT NULL AND NOT (OLD.status = 'ready_for_review' AND NEW.status = 'adopted'))
BEGIN SELECT RAISE(ABORT, 'content generation run audit fields are immutable'); END;

CREATE TRIGGER content_generation_run_modules_guard_update BEFORE UPDATE ON content_generation_run_modules BEGIN SELECT RAISE(ABORT, 'content generation audit rows are immutable'); END;
CREATE TRIGGER content_generation_run_modules_guard_delete BEFORE DELETE ON content_generation_run_modules BEGIN SELECT RAISE(ABORT, 'content generation audit rows are immutable'); END;
CREATE TRIGGER content_generation_run_evidence_guard_update BEFORE UPDATE ON content_generation_run_evidence BEGIN SELECT RAISE(ABORT, 'content generation audit rows are immutable'); END;
CREATE TRIGGER content_generation_run_evidence_guard_delete BEFORE DELETE ON content_generation_run_evidence BEGIN SELECT RAISE(ABORT, 'content generation audit rows are immutable'); END;
CREATE TRIGGER content_quality_reports_guard_update BEFORE UPDATE ON content_quality_reports BEGIN SELECT RAISE(ABORT, 'content generation audit rows are immutable'); END;
CREATE TRIGGER content_quality_reports_guard_delete BEFORE DELETE ON content_quality_reports BEGIN SELECT RAISE(ABORT, 'content generation audit rows are immutable'); END;

CREATE TRIGGER content_generation_run_steps_guard_update BEFORE UPDATE ON content_generation_run_steps
FOR EACH ROW WHEN NEW.content_generation_run_id IS NOT OLD.content_generation_run_id OR NEW.sequence IS NOT OLD.sequence OR NEW.step_type IS NOT OLD.step_type OR NEW.prompt IS NOT OLD.prompt OR NEW.input_snapshot IS NOT OLD.input_snapshot OR OLD.status IN ('completed', 'failed', 'skipped') OR NOT ((OLD.status = 'queued' AND NEW.status IN ('running', 'completed', 'failed', 'skipped')) OR (OLD.status = 'running' AND NEW.status IN ('completed', 'failed', 'skipped')))
BEGIN SELECT RAISE(ABORT, 'content generation step history is immutable'); END;
CREATE TRIGGER content_generation_run_steps_guard_delete BEFORE DELETE ON content_generation_run_steps BEGIN SELECT RAISE(ABORT, 'content generation audit rows are immutable'); END;

CREATE TRIGGER prompt_preset_bindings_guard_update BEFORE UPDATE ON prompt_preset_module_versions
FOR EACH ROW WHEN EXISTS (SELECT 1 FROM prompt_preset_versions WHERE id = OLD.prompt_preset_version_id AND lifecycle_status IN ('production', 'deprecated', 'archived'))
BEGIN SELECT RAISE(ABORT, 'published prompt preset bindings are immutable'); END;
CREATE TRIGGER prompt_preset_bindings_guard_delete BEFORE DELETE ON prompt_preset_module_versions
FOR EACH ROW WHEN EXISTS (SELECT 1 FROM prompt_preset_versions WHERE id = OLD.prompt_preset_version_id AND lifecycle_status IN ('production', 'deprecated', 'archived'))
BEGIN SELECT RAISE(ABORT, 'published prompt preset bindings are immutable'); END;
SQL);
    }

    private function dropGuards(): void
    {
        $tables = [
            'content_generation_runs' => ['content_generation_runs_guard_update'],
            'content_generation_run_modules' => ['content_generation_run_modules_guard_update', 'content_generation_run_modules_guard_delete'],
            'content_generation_run_evidence' => ['content_generation_run_evidence_guard_update', 'content_generation_run_evidence_guard_delete'],
            'content_quality_reports' => ['content_quality_reports_guard_update', 'content_quality_reports_guard_delete'],
            'content_generation_run_steps' => ['content_generation_run_steps_guard_update', 'content_generation_run_steps_guard_delete'],
            'prompt_preset_module_versions' => ['prompt_preset_bindings_guard_mutation', 'prompt_preset_bindings_guard_update', 'prompt_preset_bindings_guard_delete'],
        ];
        foreach ($tables as $table => $triggers) {
            foreach ($triggers as $trigger) {
                DB::unprepared(DB::getDriverName() === 'pgsql'
                    ? "DROP TRIGGER IF EXISTS {$trigger} ON {$table}"
                    : "DROP TRIGGER IF EXISTS {$trigger}");
            }
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS guard_content_generation_run_immutable()');
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_content_audit_mutation()');
            DB::unprepared('DROP FUNCTION IF EXISTS guard_content_generation_step_update()');
            DB::unprepared('DROP FUNCTION IF EXISTS guard_published_preset_binding()');
        }
    }

    private function restorePreviousRunGuard(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE FUNCTION guard_content_generation_run_immutable() RETURNS trigger AS $$
BEGIN
    IF NEW.content_brief_id IS DISTINCT FROM OLD.content_brief_id OR NEW.strategy_version_id IS DISTINCT FROM OLD.strategy_version_id OR NEW.blueprint_version_id IS DISTINCT FROM OLD.blueprint_version_id OR NEW.preset_version_id IS DISTINCT FROM OLD.preset_version_id OR NEW.ai_model_id IS DISTINCT FROM OLD.ai_model_id OR (OLD.compiled_prompt IS NOT NULL AND NEW.compiled_prompt IS DISTINCT FROM OLD.compiled_prompt) OR (OLD.variables_snapshot IS NOT NULL AND NEW.variables_snapshot IS DISTINCT FROM OLD.variables_snapshot) OR (OLD.model_snapshot IS NOT NULL AND NEW.model_snapshot IS DISTINCT FROM OLD.model_snapshot) OR (OLD.generation_parameters IS NOT NULL AND NEW.generation_parameters IS DISTINCT FROM OLD.generation_parameters) OR (OLD.status NOT IN ('pending', 'queued') AND (NEW.compiled_prompt IS DISTINCT FROM OLD.compiled_prompt OR NEW.variables_snapshot IS DISTINCT FROM OLD.variables_snapshot OR NEW.model_snapshot IS DISTINCT FROM OLD.model_snapshot OR NEW.generation_parameters IS DISTINCT FROM OLD.generation_parameters)) OR NEW.idempotency_key IS DISTINCT FROM OLD.idempotency_key OR NEW.created_by_admin_id IS DISTINCT FROM OLD.created_by_admin_id THEN
        RAISE EXCEPTION 'content generation run lineage and input snapshots are immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER content_generation_runs_guard_update BEFORE UPDATE ON content_generation_runs FOR EACH ROW EXECUTE FUNCTION guard_content_generation_run_immutable();
SQL);

            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TRIGGER content_generation_runs_guard_update BEFORE UPDATE ON content_generation_runs
FOR EACH ROW WHEN NEW.content_brief_id IS NOT OLD.content_brief_id OR NEW.strategy_version_id IS NOT OLD.strategy_version_id OR NEW.blueprint_version_id IS NOT OLD.blueprint_version_id OR NEW.preset_version_id IS NOT OLD.preset_version_id OR NEW.ai_model_id IS NOT OLD.ai_model_id OR (OLD.compiled_prompt IS NOT NULL AND NEW.compiled_prompt IS NOT OLD.compiled_prompt) OR (OLD.variables_snapshot IS NOT NULL AND NEW.variables_snapshot IS NOT OLD.variables_snapshot) OR (OLD.model_snapshot IS NOT NULL AND NEW.model_snapshot IS NOT OLD.model_snapshot) OR (OLD.generation_parameters IS NOT NULL AND NEW.generation_parameters IS NOT OLD.generation_parameters) OR (OLD.status NOT IN ('pending', 'queued') AND (NEW.compiled_prompt IS NOT OLD.compiled_prompt OR NEW.variables_snapshot IS NOT OLD.variables_snapshot OR NEW.model_snapshot IS NOT OLD.model_snapshot OR NEW.generation_parameters IS NOT OLD.generation_parameters)) OR NEW.idempotency_key IS NOT OLD.idempotency_key OR NEW.created_by_admin_id IS NOT OLD.created_by_admin_id
BEGIN SELECT RAISE(ABORT, 'content generation run lineage and input snapshots are immutable'); END;
SQL);
    }
};
