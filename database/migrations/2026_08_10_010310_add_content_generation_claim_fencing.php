<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TARGETED_REVISION_INDEX = 'content_generation_run_one_targeted_revision';

    public function up(): void
    {
        Schema::table('content_generation_runs', function (Blueprint $table): void {
            $table->string('claim_token', 64)->nullable()->after('idempotency_key');
            $table->unsignedBigInteger('lease_version')->default(0)->after('claim_token');
            $table->timestamp('lease_expires_at')->nullable()->after('lease_version');
            $table->index('lease_expires_at');
        });

        DB::statement(sprintf(
            "CREATE UNIQUE INDEX %s ON content_generation_run_steps (content_generation_run_id) WHERE step_type = 'targeted_revision'",
            self::TARGETED_REVISION_INDEX,
        ));
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::TARGETED_REVISION_INDEX);

        Schema::table('content_generation_runs', function (Blueprint $table): void {
            $table->dropIndex(['lease_expires_at']);
            $table->dropColumn(['claim_token', 'lease_version', 'lease_expires_at']);
        });
    }
};
