<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_briefs', function (Blueprint $table): void {
            $table->id();
            $table->string('topic', 300);
            $table->string('market_code', 10);
            $table->string('language_code', 10);
            $table->text('audience');
            $table->text('core_question');
            $table->text('business_goal');
            $table->string('content_type', 50);
            $table->timestamp('planned_publish_at')->nullable();
            $table->foreignId('keyword_cluster_id')->nullable()->constrained('keyword_clusters')->restrictOnDelete();
            $table->foreignId('primary_keyword_id')->nullable()->constrained('keywords')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('target_url', 1000)->nullable();
            $table->jsonb('required_mentions')->nullable();
            $table->jsonb('prohibited_information')->nullable();
            $table->jsonb('campaign_window')->nullable();
            $table->jsonb('reference_urls')->nullable();
            $table->string('cta_strength', 30)->nullable();
            $table->string('status', 30)->default('draft');
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('content_strategy_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_brief_id')->constrained('content_briefs')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->jsonb('strategy');
            $table->jsonb('suggestion_snapshot')->nullable();
            $table->string('lifecycle_status', 30)->default('draft');
            $table->text('change_note')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->foreignId('confirmed_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['content_brief_id', 'version']);
            $table->index(['content_brief_id', 'lifecycle_status']);
        });

        Schema::create('content_blueprint_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_brief_id')->constrained('content_briefs')->restrictOnDelete();
            $table->foreignId('content_strategy_version_id')->constrained('content_strategy_versions')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->jsonb('blueprint');
            $table->jsonb('validation_report')->nullable();
            $table->string('lifecycle_status', 30)->default('draft');
            $table->text('change_note')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->foreignId('confirmed_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['content_brief_id', 'version']);
            $table->index(['content_brief_id', 'lifecycle_status']);
            $table->index('content_strategy_version_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_blueprint_versions');
        Schema::dropIfExists('content_strategy_versions');
        Schema::dropIfExists('content_briefs');
    }
};
