<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_generation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_brief_id')->constrained('content_briefs')->restrictOnDelete();
            $table->unsignedBigInteger('strategy_version_id');
            $table->unsignedBigInteger('blueprint_version_id');
            $table->foreignId('preset_version_id')->constrained('prompt_preset_versions')->restrictOnDelete();
            $table->foreignId('ai_model_id')->constrained('ai_models')->restrictOnDelete();
            $table->foreignId('article_id')->nullable()->constrained('articles')->nullOnDelete();
            $table->longText('compiled_prompt');
            $table->jsonb('variables_snapshot')->nullable();
            $table->jsonb('model_snapshot')->nullable();
            $table->jsonb('generation_parameters')->nullable();
            $table->longText('raw_output')->nullable();
            $table->longText('adopted_output')->nullable();
            $table->string('status', 40)->default('queued');
            $table->string('idempotency_key', 150);
            $table->text('error_message')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->foreignId('adopted_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('adopted_at')->nullable();
            $table->timestamps();
            $table->unique('idempotency_key');
            $table->foreign(['strategy_version_id', 'content_brief_id'], 'content_generation_runs_strategy_brief_foreign')
                ->references(['id', 'content_brief_id'])
                ->on('content_strategy_versions')
                ->restrictOnDelete();
            $table->foreign(['blueprint_version_id', 'content_brief_id'], 'content_generation_runs_blueprint_brief_foreign')
                ->references(['id', 'content_brief_id'])
                ->on('content_blueprint_versions')
                ->restrictOnDelete();
            $table->index(['status', 'created_at']);
        });

        Schema::create('content_generation_run_modules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_generation_run_id')->constrained('content_generation_runs')->restrictOnDelete();
            $table->foreignId('prompt_module_version_id')->constrained('prompt_module_versions')->restrictOnDelete();
            $table->unsignedInteger('sort_order');
            $table->boolean('is_required')->default(false);
            $table->longText('rendered_content')->nullable();
            $table->jsonb('module_snapshot');
            $table->jsonb('variables_snapshot')->nullable();
            $table->timestamps();
            $table->unique(['content_generation_run_id', 'prompt_module_version_id']);
            $table->unique(['content_generation_run_id', 'sort_order']);
        });

        Schema::create('content_generation_run_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_generation_run_id')->constrained('content_generation_runs')->restrictOnDelete();
            $table->foreignId('knowledge_chunk_id')->nullable()->constrained('knowledge_chunks')->restrictOnDelete();
            $table->string('evidence_key', 150);
            $table->jsonb('evidence_snapshot');
            $table->boolean('is_required')->default(false);
            $table->timestamps();
            $table->unique(['content_generation_run_id', 'evidence_key']);
        });

        Schema::create('content_generation_run_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_generation_run_id')->constrained('content_generation_runs')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('step_type', 40);
            $table->string('status', 40)->default('queued');
            $table->longText('prompt')->nullable();
            $table->longText('output')->nullable();
            $table->jsonb('input_snapshot')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->decimal('cost', 12, 6)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['content_generation_run_id', 'sequence']);
            $table->index(['content_generation_run_id', 'status']);
        });

        Schema::create('content_quality_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_generation_run_id')->constrained('content_generation_runs')->restrictOnDelete();
            $table->foreignId('content_generation_run_step_id')->nullable()->constrained('content_generation_run_steps')->restrictOnDelete();
            $table->string('report_type', 40);
            $table->string('status', 40);
            $table->boolean('passed');
            $table->decimal('score', 5, 2)->nullable();
            $table->jsonb('deterministic_results')->nullable();
            $table->jsonb('semantic_results')->nullable();
            $table->jsonb('issues')->nullable();
            $table->jsonb('revision_instructions')->nullable();
            $table->timestamps();
            $table->index(['content_generation_run_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        $this->createIntegrityGuards();
    }

    public function down(): void
    {
        $this->dropIntegrityGuards();
        Schema::dropIfExists('content_quality_reports');
        Schema::dropIfExists('content_generation_run_steps');
        Schema::dropIfExists('content_generation_run_evidence');
        Schema::dropIfExists('content_generation_run_modules');
        Schema::dropIfExists('content_generation_runs');
    }

    private function createIntegrityGuards(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_content_generation_run_immutable() RETURNS trigger AS $$
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
CREATE OR REPLACE FUNCTION prevent_content_generation_run_delete() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'content generation runs cannot be deleted';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER content_generation_runs_guard_delete
BEFORE DELETE ON content_generation_runs
FOR EACH ROW EXECUTE FUNCTION prevent_content_generation_run_delete();
SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER content_generation_runs_guard_update
BEFORE UPDATE ON content_generation_runs
FOR EACH ROW WHEN NEW.content_brief_id IS NOT OLD.content_brief_id OR NEW.strategy_version_id IS NOT OLD.strategy_version_id OR NEW.blueprint_version_id IS NOT OLD.blueprint_version_id OR NEW.preset_version_id IS NOT OLD.preset_version_id OR NEW.ai_model_id IS NOT OLD.ai_model_id OR NEW.compiled_prompt IS NOT OLD.compiled_prompt OR NEW.variables_snapshot IS NOT OLD.variables_snapshot OR NEW.model_snapshot IS NOT OLD.model_snapshot OR NEW.generation_parameters IS NOT OLD.generation_parameters OR NEW.idempotency_key IS NOT OLD.idempotency_key OR NEW.created_by_admin_id IS NOT OLD.created_by_admin_id
BEGIN SELECT RAISE(ABORT, 'content generation run lineage and input snapshots are immutable'); END;
CREATE TRIGGER content_generation_runs_guard_delete
BEFORE DELETE ON content_generation_runs
FOR EACH ROW BEGIN SELECT RAISE(ABORT, 'content generation runs cannot be deleted'); END;
SQL);
        }
    }

    private function dropIntegrityGuards(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS content_generation_runs_guard_delete ON content_generation_runs;
DROP TRIGGER IF EXISTS content_generation_runs_guard_update ON content_generation_runs;
DROP FUNCTION IF EXISTS prevent_content_generation_run_delete();
DROP FUNCTION IF EXISTS guard_content_generation_run_immutable();
SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS content_generation_runs_guard_delete');
            DB::unprepared('DROP TRIGGER IF EXISTS content_generation_runs_guard_update');
        }
    }
};
