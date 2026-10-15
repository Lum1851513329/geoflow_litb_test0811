<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('content_generation_runs', 'external_evidence_policy_snapshot')) {
            Schema::table('content_generation_runs', function (Blueprint $table): void {
                $table->jsonb('external_evidence_policy_snapshot')->nullable();
            });
        }
    }

    public function down(): void
    {
        // The original policy migration owns this column. A repair rollback must
        // not remove a column that may have existed before this migration ran.
    }
};
