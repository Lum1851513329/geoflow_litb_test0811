<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_keyword_nodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('knowledge_base_id')->constrained('knowledge_bases')->cascadeOnDelete();
            $table->foreignId('keyword_node_id')->constrained('keyword_nodes')->cascadeOnDelete();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('source', 50)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['knowledge_base_id', 'keyword_node_id'], 'knowledge_keyword_nodes_document_node_unique');
            $table->index(['keyword_node_id', 'knowledge_base_id'], 'knowledge_keyword_nodes_node_document_index');
        });

        Schema::create('knowledge_keyword_clusters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('knowledge_base_id')->constrained('knowledge_bases')->cascadeOnDelete();
            $table->foreignId('keyword_cluster_id')->constrained('keyword_clusters')->cascadeOnDelete();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->string('source', 50)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['knowledge_base_id', 'keyword_cluster_id'], 'knowledge_keyword_clusters_document_cluster_unique');
            $table->index(['keyword_cluster_id', 'knowledge_base_id'], 'knowledge_keyword_clusters_cluster_document_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_keyword_clusters');
        Schema::dropIfExists('knowledge_keyword_nodes');
    }
};
