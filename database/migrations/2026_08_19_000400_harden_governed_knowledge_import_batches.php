<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_NAME = 'knowledge_import_batches_scope_config_source_unique';

    public function up(): void
    {
        Schema::table('knowledge_import_batches', function (Blueprint $table): void {
            $table->unique(
                ['brand_id', 'site_id', 'configuration_hash', 'source_checksum'],
                self::UNIQUE_NAME,
            );
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_import_batches', function (Blueprint $table): void {
            $table->dropUnique(self::UNIQUE_NAME);
        });
    }
};
