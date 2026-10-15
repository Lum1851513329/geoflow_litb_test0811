<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keywords', function (Blueprint $table) {
            $table->string('normalized_keyword', 200)->nullable();
            $table->string('language_code', 10)->default('en');
            $table->string('market_code', 10)->nullable();
            $table->string('market_scope', 10)->default('GLOBAL');
            $table->string('keyword_type', 30)->default('category');
            $table->string('search_intent', 30)->default('informational');
            $table->string('brand_relation', 30)->default('non_brand');
            $table->string('status', 20)->default('active');
            $table->foreignId('canonical_keyword_id')->nullable()->constrained('keywords')->nullOnDelete();
            $table->decimal('latest_opportunity_score', 8, 2)->nullable();
        });

        Schema::table('keywords', function (Blueprint $table) {
            $table->unique(
                ['library_id', 'normalized_keyword', 'language_code', 'market_scope'],
                'keywords_normalized_locale_unique'
            );
            $table->index(['library_id', 'status', 'language_code'], 'keywords_library_status_locale_index');
            $table->index('canonical_keyword_id');
        });

        Schema::create('keyword_taxonomies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 150)->unique();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('keyword_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('taxonomy_id')->constrained('keyword_taxonomies')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('keyword_nodes')->restrictOnDelete();
            $table->string('name', 150);
            $table->string('slug', 150);
            $table->string('path', 1000);
            $table->unsignedInteger('depth')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['taxonomy_id', 'path']);
            $table->index(['taxonomy_id', 'parent_id', 'sort_order']);
        });

        Schema::create('keyword_node_keyword', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_id')->constrained('keyword_nodes')->cascadeOnDelete();
            $table->foreignId('keyword_id')->constrained('keywords')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('source', 50)->nullable();
            $table->timestamps();
            $table->unique(['node_id', 'keyword_id']);
            $table->index(['keyword_id', 'is_primary']);
        });

        Schema::create('keyword_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_id')->nullable()->constrained('keyword_libraries')->nullOnDelete();
            $table->string('original_filename', 255);
            $table->string('stored_path', 500);
            $table->string('file_sha256', 64);
            $table->string('source_type', 50);
            $table->string('status', 30);
            $table->json('mapping_json');
            $table->json('metadata_json')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->unsignedInteger('total_count')->default(0);
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);
            $table->unsignedInteger('retained_shared_keyword_count')->default(0);
            $table->text('error_summary')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('reverted_at')->nullable();
            $table->timestamps();
            $table->index(['library_id', 'status']);
            $table->index('file_sha256');
        });

        Schema::create('keyword_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('keyword_import_batches')->cascadeOnDelete();
            $table->string('sheet_name', 255);
            $table->unsignedInteger('row_number');
            $table->json('source_payload');
            $table->foreignId('keyword_id')->nullable()->constrained('keywords')->nullOnDelete();
            $table->string('normalized_keyword', 200)->nullable();
            $table->string('status', 30);
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->unique(['batch_id', 'sheet_name', 'row_number']);
            $table->index(['batch_id', 'status']);
        });

        Schema::create('keyword_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('keyword_id')->constrained('keywords')->restrictOnDelete();
            $table->foreignId('import_batch_id')->nullable()->constrained('keyword_import_batches')->nullOnDelete();
            $table->string('source_type', 50);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('market_code', 10)->nullable();
            $table->string('language_code', 10)->nullable();
            $table->string('device', 30)->nullable();
            $table->unsignedBigInteger('clicks')->nullable();
            $table->unsignedBigInteger('impressions')->nullable();
            $table->decimal('ctr', 10, 6)->nullable();
            $table->decimal('average_position', 10, 4)->nullable();
            $table->decimal('cost', 14, 4)->nullable();
            $table->decimal('conversions', 14, 4)->nullable();
            $table->json('source_payload')->nullable();
            $table->timestamps();
            $table->index(['keyword_id', 'period_start', 'period_end', 'source_type'], 'keyword_metrics_period_source_index');
        });

        Schema::create('keyword_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_keyword_id')->constrained('keywords')->restrictOnDelete();
            $table->foreignId('target_keyword_id')->constrained('keywords')->restrictOnDelete();
            $table->string('relation_type', 50);
            $table->string('source', 50)->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('confirmation_status', 30)->default('suggested');
            $table->foreignId('confirmed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['source_keyword_id', 'target_keyword_id', 'relation_type']);
            $table->index(['target_keyword_id', 'relation_type']);
        });

        Schema::create('keyword_clusters', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('slug', 200);
            $table->foreignId('taxonomy_node_id')->nullable()->constrained('keyword_nodes')->nullOnDelete();
            $table->foreignId('primary_keyword_id')->constrained('keywords')->restrictOnDelete();
            $table->string('language_code', 10);
            $table->string('market_code', 10)->nullable();
            $table->string('market_scope', 10)->default('GLOBAL');
            $table->string('search_intent', 30);
            $table->string('content_type', 50)->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('coverage_status', 30)->default('uncovered');
            $table->foreignId('knowledge_base_id')->nullable()->constrained('knowledge_bases')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('target_url', 1000)->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->decimal('latest_opportunity_score', 8, 2)->nullable();
            $table->timestamps();
            $table->unique(['slug', 'language_code', 'market_scope'], 'keyword_clusters_slug_locale_unique');
            $table->index(['taxonomy_node_id', 'status']);
            $table->index(['primary_keyword_id', 'status']);
        });

        Schema::create('keyword_cluster_keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cluster_id')->constrained('keyword_clusters')->cascadeOnDelete();
            $table->foreignId('keyword_id')->constrained('keywords')->restrictOnDelete();
            $table->string('role', 30);
            $table->decimal('weight', 8, 4)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['cluster_id', 'keyword_id']);
            $table->index(['keyword_id', 'role']);
        });

        Schema::create('keyword_opportunity_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cluster_id')->constrained('keyword_clusters')->cascadeOnDelete();
            $table->string('score_version', 50);
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('total_score', 8, 2);
            $table->json('components_json')->nullable();
            $table->json('penalties_json')->nullable();
            $table->json('inputs_json')->nullable();
            $table->json('weights_json')->nullable();
            $table->timestamps();
            $table->unique(['cluster_id', 'score_version', 'period_start', 'period_end']);
            $table->index(['cluster_id', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_opportunity_scores');
        Schema::dropIfExists('keyword_cluster_keywords');
        Schema::dropIfExists('keyword_clusters');
        Schema::dropIfExists('keyword_relations');
        Schema::dropIfExists('keyword_metrics');
        Schema::dropIfExists('keyword_import_rows');
        Schema::dropIfExists('keyword_import_batches');
        Schema::dropIfExists('keyword_node_keyword');
        Schema::dropIfExists('keyword_nodes');
        Schema::dropIfExists('keyword_taxonomies');

        Schema::table('keywords', function (Blueprint $table) {
            $table->dropIndex('keywords_library_status_locale_index');
            $table->dropIndex(['canonical_keyword_id']);
            $table->dropUnique('keywords_normalized_locale_unique');
            $table->dropConstrainedForeignId('canonical_keyword_id');
            $table->dropColumn([
                'normalized_keyword',
                'language_code',
                'market_code',
                'market_scope',
                'keyword_type',
                'search_intent',
                'brand_relation',
                'status',
                'latest_opportunity_score',
            ]);
        });
    }
};
