<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_generation_runs', function (Blueprint $table): void {
            $table->timestamp('dispatch_enqueued_at')->nullable()->after('request_fingerprint');
            $table->index(['status', 'dispatch_enqueued_at'], 'content_generation_runs_dispatch_recovery_index');
        });
    }

    public function down(): void
    {
        Schema::table('content_generation_runs', function (Blueprint $table): void {
            $table->dropIndex('content_generation_runs_dispatch_recovery_index');
            $table->dropColumn('dispatch_enqueued_at');
        });
    }
};
