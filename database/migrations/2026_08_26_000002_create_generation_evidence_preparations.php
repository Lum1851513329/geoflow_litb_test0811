<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generation_evidence_preparations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_generation_run_id')->unique()->constrained('content_generation_runs')->restrictOnDelete();
            $table->string('input_digest', 64);
            $table->jsonb('input_json');
            $table->string('phase', 40)->default('pending');
            $table->jsonb('external_evidence')->nullable();
            $table->jsonb('attempt_log')->nullable();
            $table->jsonb('result_json')->nullable();
            $table->string('lease_token', 64)->nullable();
            $table->unsignedBigInteger('lease_version')->default(0);
            $table->unsignedSmallInteger('pages_fetched')->default(0);
            $table->unsignedSmallInteger('search_rounds')->default(0);
            $table->timestamp('deadline_at')->nullable();
            $table->timestamp('sealed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generation_evidence_preparations');
    }
};
