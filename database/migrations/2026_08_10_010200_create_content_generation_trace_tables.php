<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_generation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_brief_id')->constrained('content_briefs')->restrictOnDelete();
            $table->foreignId('strategy_version_id')->constrained('content_strategy_versions')->restrictOnDelete();
            $table->foreignId('blueprint_version_id')->constrained('content_blueprint_versions')->restrictOnDelete();
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
    }

    public function down(): void
    {
        Schema::dropIfExists('content_quality_reports');
        Schema::dropIfExists('content_generation_run_steps');
        Schema::dropIfExists('content_generation_run_evidence');
        Schema::dropIfExists('content_generation_run_modules');
        Schema::dropIfExists('content_generation_runs');
    }
};
