<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_bases', function (Blueprint $table): void {
            $table->unique(['brand_id', 'site_id', 'id'], 'knowledge_bases_scope_id_unique');
        });

        Schema::table('knowledge_import_batches', function (Blueprint $table): void {
            $table->unique(['brand_id', 'site_id', 'id'], 'knowledge_import_batches_scope_id_unique');
        });

        Schema::table('knowledge_import_rows', function (Blueprint $table): void {
            $table->unsignedBigInteger('brand_id');
            $table->unsignedBigInteger('site_id');

            $table->foreign(['brand_id', 'site_id', 'batch_id'], 'knowledge_import_rows_batch_scope_foreign')
                ->references(['brand_id', 'site_id', 'id'])
                ->on('knowledge_import_batches')
                ->cascadeOnDelete();
            $table->foreign(['brand_id', 'site_id', 'canonical_document_id'], 'knowledge_import_rows_canonical_scope_foreign')
                ->references(['brand_id', 'site_id', 'id'])
                ->on('knowledge_bases');
            $table->unique(['brand_id', 'site_id', 'batch_id', 'id'], 'knowledge_import_rows_scope_id_unique');
        });

        Schema::table('knowledge_duplicates', function (Blueprint $table): void {
            $table->unsignedBigInteger('brand_id');
            $table->unsignedBigInteger('site_id');

            $table->foreign(['brand_id', 'site_id', 'import_batch_id'], 'knowledge_duplicates_batch_scope_foreign')
                ->references(['brand_id', 'site_id', 'id'])
                ->on('knowledge_import_batches');
            $table->foreign(['brand_id', 'site_id', 'import_batch_id', 'import_row_id'], 'knowledge_duplicates_import_row_scope_foreign')
                ->references(['brand_id', 'site_id', 'batch_id', 'id'])
                ->on('knowledge_import_rows');
            $table->foreign(['brand_id', 'site_id', 'duplicate_document_id'], 'knowledge_duplicates_duplicate_document_scope_foreign')
                ->references(['brand_id', 'site_id', 'id'])
                ->on('knowledge_bases');
            $table->foreign(['brand_id', 'site_id', 'canonical_document_id'], 'knowledge_duplicates_canonical_document_scope_foreign')
                ->references(['brand_id', 'site_id', 'id'])
                ->on('knowledge_bases');
        });

        $this->addImportRowBatchCheck();
    }

    public function down(): void
    {
        $this->dropImportRowBatchCheck();

        Schema::table('knowledge_duplicates', function (Blueprint $table): void {
            $table->dropForeign('knowledge_duplicates_canonical_document_scope_foreign');
            $table->dropForeign('knowledge_duplicates_duplicate_document_scope_foreign');
            $table->dropForeign('knowledge_duplicates_import_row_scope_foreign');
            $table->dropForeign('knowledge_duplicates_batch_scope_foreign');
            $table->dropColumn(['brand_id', 'site_id']);
        });

        Schema::table('knowledge_import_rows', function (Blueprint $table): void {
            $table->dropForeign('knowledge_import_rows_canonical_scope_foreign');
            $table->dropForeign('knowledge_import_rows_batch_scope_foreign');
            $table->dropUnique('knowledge_import_rows_scope_id_unique');
            $table->dropColumn(['brand_id', 'site_id']);
        });

        Schema::table('knowledge_import_batches', function (Blueprint $table): void {
            $table->dropUnique('knowledge_import_batches_scope_id_unique');
        });

        Schema::table('knowledge_bases', function (Blueprint $table): void {
            $table->dropUnique('knowledge_bases_scope_id_unique');
        });
    }

    private function addImportRowBatchCheck(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->createSqliteImportRowBatchTrigger('insert', 'INSERT');
            $this->createSqliteImportRowBatchTrigger(
                'update',
                'UPDATE OF import_batch_id, import_row_id',
            );

            return;
        }

        DB::statement(<<<'SQL'
ALTER TABLE knowledge_duplicates
ADD CONSTRAINT knowledge_duplicates_import_row_requires_batch_check
CHECK (import_row_id IS NULL OR import_batch_id IS NOT NULL)
SQL);
    }

    private function dropImportRowBatchCheck(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS knowledge_duplicates_import_row_requires_batch_insert_check');
            DB::statement('DROP TRIGGER IF EXISTS knowledge_duplicates_import_row_requires_batch_update_check');

            return;
        }

        DB::statement('ALTER TABLE knowledge_duplicates DROP CONSTRAINT knowledge_duplicates_import_row_requires_batch_check');
    }

    private function createSqliteImportRowBatchTrigger(string $suffix, string $event): void
    {
        DB::unprepared(<<<SQL
CREATE TRIGGER knowledge_duplicates_import_row_requires_batch_{$suffix}_check
BEFORE {$event} ON knowledge_duplicates
FOR EACH ROW
WHEN NEW.import_row_id IS NOT NULL AND NEW.import_batch_id IS NULL
BEGIN
    SELECT RAISE(ABORT, 'knowledge duplicate import rows require an import batch');
END
SQL);
    }
};
