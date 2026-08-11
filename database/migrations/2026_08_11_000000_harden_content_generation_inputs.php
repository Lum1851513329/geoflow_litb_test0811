<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_generation_runs', function (Blueprint $table): void {
            $table->longText('encrypted_model_payload')->nullable()->after('compiled_prompt');
            $table->char('request_fingerprint', 64)->nullable()->after('idempotency_key');
        });
        Schema::table('ai_models', function (Blueprint $table): void {
            $table->unsignedInteger('context_window_tokens')->nullable()->after('max_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('content_generation_runs', function (Blueprint $table): void {
            $table->dropColumn(['encrypted_model_payload', 'request_fingerprint']);
        });
        Schema::table('ai_models', function (Blueprint $table): void {
            $table->dropColumn('context_window_tokens');
        });
    }
};
