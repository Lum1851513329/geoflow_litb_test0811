<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('external_evidence_policy', 30)->default('off');
            $table->jsonb('external_general_domains')->nullable();
        });
        Schema::table('content_briefs', function (Blueprint $table): void {
            $table->string('external_evidence_policy', 30)->default('off');
            $table->jsonb('external_general_domains')->nullable();
        });
        Schema::table('content_generation_runs', function (Blueprint $table): void {
            $table->jsonb('external_evidence_policy_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('content_generation_runs', fn (Blueprint $table) => $table->dropColumn('external_evidence_policy_snapshot'));
        Schema::table('content_briefs', fn (Blueprint $table) => $table->dropColumn(['external_evidence_policy', 'external_general_domains']));
        Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn(['external_evidence_policy', 'external_general_domains']));
    }
};
