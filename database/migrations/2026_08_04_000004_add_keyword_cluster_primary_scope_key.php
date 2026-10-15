<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'keyword_clusters_primary_scope_unique';

    public function up(): void
    {
        $hasConflictingActiveClaims = DB::table('keyword_clusters')
            ->select(['primary_keyword_id', 'language_code', 'market_scope'])
            ->where('status', 'active')
            ->groupBy(['primary_keyword_id', 'language_code', 'market_scope'])
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($hasConflictingActiveClaims) {
            throw new RuntimeException('keyword_clusters.primary_scope_backfill_conflict');
        }

        Schema::table('keyword_clusters', function (Blueprint $table): void {
            $table->string('primary_scope_key', 64)->nullable();
            $table->unique('primary_scope_key', self::INDEX);
        });

        DB::table('keyword_clusters')
            ->where('status', 'active')
            ->orderBy('id')
            ->eachById(function (object $cluster): void {
                DB::table('keyword_clusters')->where('id', $cluster->id)->update([
                    'primary_scope_key' => hash('sha256', implode('|', [
                        $cluster->primary_keyword_id,
                        $cluster->language_code,
                        $cluster->market_scope,
                    ])),
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('keyword_clusters', function (Blueprint $table): void {
            $table->dropUnique(self::INDEX);
            $table->dropColumn('primary_scope_key');
        });
    }
};
