<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ACTIVE_CONTENT_HASH_INDEX = 'knowledge_bases_active_content_hash_unique';

    public function up(): void
    {
        Schema::table('knowledge_bases', function (Blueprint $table): void {
            $table->string('canonical_document_key', 190)->nullable();
            $table->string('language_code', 35)->nullable();
            $table->string('authority_level', 30)->nullable();
            $table->timestamp('retrieved_at')->nullable();
            $table->date('review_due_at')->nullable();
            $table->string('version_label', 100)->nullable();
            $table->string('lifecycle_status', 30)->default('active');
            $table->boolean('generation_eligible')->default(false);
            $table->boolean('citation_required')->default(true);
            $table->string('normalized_content_hash', 64)->nullable();
            $table->string('source_file_path', 1000)->nullable();
            $table->string('source_file_hash', 64)->nullable();

            $table->index(['brand_id', 'site_id', 'canonical_document_key'], 'knowledge_bases_canonical_document_index');
            $table->index(['brand_id', 'language_code', 'lifecycle_status'], 'knowledge_bases_governance_scope_index');
        });

        $this->createActiveContentHashIndex();

        Schema::create('knowledge_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('brand_id');
            $table->unsignedBigInteger('site_id');
            $table->string('source_type', 50);
            $table->string('status', 30)->default('staged');
            $table->string('configuration_hash', 64);
            $table->string('source_checksum', 64);
            $table->unsignedInteger('total_count')->default(0);
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);
            $table->json('error_log')->nullable();
            $table->json('rollback_metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('reverted_at')->nullable();
            $table->timestamps();

            $table->foreign('brand_id', 'knowledge_import_batches_brand_id_foreign')
                ->references('id')
                ->on('knowledge_brands')
                ->restrictOnDelete();
            $table->foreign(['brand_id', 'site_id'], 'knowledge_import_batches_brand_site_scope_foreign')
                ->references(['brand_id', 'id'])
                ->on('knowledge_sites')
                ->restrictOnDelete();
            $table->index(['brand_id', 'site_id', 'status'], 'knowledge_import_batches_scope_status_index');
            $table->index('source_checksum', 'knowledge_import_batches_source_checksum_index');
        });

        Schema::create('knowledge_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('knowledge_import_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('staged_payload');
            $table->string('outcome', 30)->default('staged');
            $table->foreignId('canonical_document_id')->nullable()->constrained('knowledge_bases')->nullOnDelete();
            $table->string('duplicate_kind', 30)->nullable();
            $table->decimal('duplicate_score', 5, 4)->nullable();
            $table->text('duplicate_reason')->nullable();
            $table->text('error_message')->nullable();
            $table->json('rollback_metadata')->nullable();
            $table->timestamps();

            $table->unique(['batch_id', 'row_number'], 'knowledge_import_rows_batch_row_unique');
            $table->index(['batch_id', 'outcome'], 'knowledge_import_rows_batch_outcome_index');
            $table->index('canonical_document_id', 'knowledge_import_rows_canonical_document_index');
        });

        Schema::create('knowledge_duplicates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_batch_id')->nullable()->constrained('knowledge_import_batches')->nullOnDelete();
            $table->foreignId('import_row_id')->nullable()->constrained('knowledge_import_rows')->nullOnDelete();
            $table->foreignId('duplicate_document_id')->nullable()->constrained('knowledge_bases')->nullOnDelete();
            $table->foreignId('canonical_document_id')->nullable()->constrained('knowledge_bases')->nullOnDelete();
            $table->string('duplicate_kind', 30);
            $table->decimal('duplicate_score', 5, 4)->nullable();
            $table->text('duplicate_reason')->nullable();
            $table->json('rollback_metadata')->nullable();
            $table->timestamps();

            $table->index(['canonical_document_id', 'duplicate_kind'], 'knowledge_duplicates_canonical_kind_index');
            $table->index('duplicate_document_id', 'knowledge_duplicates_duplicate_document_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_duplicates');
        Schema::dropIfExists('knowledge_import_rows');
        Schema::dropIfExists('knowledge_import_batches');

        $this->dropActiveContentHashIndex();

        Schema::table('knowledge_bases', function (Blueprint $table): void {
            $table->dropIndex('knowledge_bases_canonical_document_index');
            $table->dropIndex('knowledge_bases_governance_scope_index');
            $table->dropColumn([
                'canonical_document_key',
                'language_code',
                'authority_level',
                'retrieved_at',
                'review_due_at',
                'version_label',
                'lifecycle_status',
                'generation_eligible',
                'citation_required',
                'normalized_content_hash',
                'source_file_path',
                'source_file_hash',
            ]);
        });
    }

    private function createActiveContentHashIndex(): void
    {
        DB::statement(<<<'SQL'
CREATE UNIQUE INDEX knowledge_bases_active_content_hash_unique
ON knowledge_bases (brand_id, language_code, normalized_content_hash)
WHERE lifecycle_status = 'active' AND normalized_content_hash IS NOT NULL
SQL);
    }

    private function dropActiveContentHashIndex(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::ACTIVE_CONTENT_HASH_INDEX);
    }
};
