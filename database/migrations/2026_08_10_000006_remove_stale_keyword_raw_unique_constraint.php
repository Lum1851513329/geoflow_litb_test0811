<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE keywords DROP CONSTRAINT IF EXISTS keywords_library_id_keyword_key');
            DB::statement('ALTER TABLE keywords DROP CONSTRAINT IF EXISTS keywords_library_id_keyword_unique');
        }

        DB::statement('DROP INDEX IF EXISTS keywords_library_id_keyword_key');
        DB::statement('DROP INDEX IF EXISTS keywords_library_id_keyword_unique');
        DB::statement('DROP INDEX IF EXISTS keywords_legacy_raw_unique');
        DB::statement(
            'CREATE UNIQUE INDEX keywords_legacy_raw_unique '
            .'ON keywords (library_id, keyword) WHERE normalized_keyword IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS keywords_legacy_raw_unique');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE keywords ADD CONSTRAINT keywords_library_id_keyword_key '
                .'UNIQUE (library_id, keyword)'
            );

            return;
        }

        DB::statement('CREATE UNIQUE INDEX keywords_library_id_keyword_key ON keywords (library_id, keyword)');
    }
};
