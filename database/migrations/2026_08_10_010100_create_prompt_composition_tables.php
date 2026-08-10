<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prompt_modules', function (Blueprint $table): void {
            $table->id();
            $table->string('module_key', 150)->unique();
            $table->string('display_name', 150);
            $table->string('module_type', 50);
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->jsonb('applicability')->nullable();
            $table->boolean('is_mandatory')->default(false);
            $table->integer('default_priority')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->foreignId('owned_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->timestamps();
            $table->index(['module_type', 'is_enabled']);
        });

        Schema::create('prompt_module_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prompt_module_id')->constrained('prompt_modules')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->longText('content');
            $table->jsonb('variable_contract');
            $table->jsonb('output_contract')->nullable();
            $table->jsonb('parameter_defaults')->nullable();
            $table->string('content_sha256', 64)->nullable();
            $table->string('lifecycle_status', 30)->default('draft');
            $table->text('change_note')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['prompt_module_id', 'version']);
            $table->unique(['prompt_module_id', 'content_sha256']);
            $table->index(['prompt_module_id', 'lifecycle_status']);
        });

        Schema::table('prompt_modules', function (Blueprint $table): void {
            $table->foreignId('current_version_id')->nullable()->constrained('prompt_module_versions')->restrictOnDelete();
        });

        Schema::create('prompt_presets', function (Blueprint $table): void {
            $table->id();
            $table->string('preset_key', 150)->unique();
            $table->string('display_name', 150);
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->foreignId('owned_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('prompt_preset_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prompt_preset_id')->constrained('prompt_presets')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->jsonb('matching_rules');
            $table->jsonb('generation_policy');
            $table->jsonb('conflict_policy')->nullable();
            $table->string('lifecycle_status', 30)->default('draft');
            $table->text('change_note')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['prompt_preset_id', 'version']);
            $table->index(['prompt_preset_id', 'lifecycle_status']);
        });

        Schema::table('prompt_presets', function (Blueprint $table): void {
            $table->foreignId('current_version_id')->nullable()->constrained('prompt_preset_versions')->restrictOnDelete();
        });

        Schema::create('prompt_preset_module_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prompt_preset_version_id')->constrained('prompt_preset_versions')->restrictOnDelete();
            $table->foreignId('prompt_module_version_id')->constrained('prompt_module_versions')->restrictOnDelete();
            $table->unsignedInteger('sort_order');
            $table->boolean('is_required')->default(false);
            $table->string('selection_source', 30)->default('preset');
            $table->jsonb('configuration')->nullable();
            $table->timestamps();
            $table->unique(['prompt_preset_version_id', 'prompt_module_version_id']);
            $table->unique(['prompt_preset_version_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prompt_preset_module_versions');
        Schema::table('prompt_presets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_version_id');
        });
        Schema::dropIfExists('prompt_preset_versions');
        Schema::dropIfExists('prompt_presets');
        Schema::table('prompt_modules', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_version_id');
        });
        Schema::dropIfExists('prompt_module_versions');
        Schema::dropIfExists('prompt_modules');
    }
};
