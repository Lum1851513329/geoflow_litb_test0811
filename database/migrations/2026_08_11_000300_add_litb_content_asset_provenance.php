<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['knowledge_bases', 'keyword_libraries', 'content_briefs'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('asset_key', 190)->nullable()->unique();
                $table->json('asset_provenance')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['content_briefs', 'keyword_libraries', 'knowledge_bases'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropUnique(['asset_key']);
                $table->dropColumn(['asset_key', 'asset_provenance']);
            });
        }
    }
};
