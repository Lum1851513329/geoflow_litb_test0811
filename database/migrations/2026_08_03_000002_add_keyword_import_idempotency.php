<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keyword_import_batches', function (Blueprint $table): void {
            $table->string('import_config_hash', 64)->nullable()->after('file_sha256');
            $table->unique(['file_sha256', 'import_config_hash'], 'keyword_import_batches_file_config_unique');
        });

        Schema::table('keyword_metrics', function (Blueprint $table): void {
            $table->foreignId('import_row_id')
                ->nullable()
                ->after('import_batch_id')
                ->constrained('keyword_import_rows')
                ->nullOnDelete();
            $table->unique('import_row_id');
        });
    }

    public function down(): void
    {
        Schema::table('keyword_metrics', function (Blueprint $table): void {
            $table->dropUnique(['import_row_id']);
            $table->dropConstrainedForeignId('import_row_id');
        });

        Schema::table('keyword_import_batches', function (Blueprint $table): void {
            $table->dropUnique('keyword_import_batches_file_config_unique');
            $table->dropColumn('import_config_hash');
        });
    }
};
