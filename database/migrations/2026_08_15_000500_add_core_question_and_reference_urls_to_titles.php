<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('titles')) {
            return;
        }

        Schema::table('titles', function (Blueprint $table): void {
            if (! Schema::hasColumn('titles', 'core_question')) {
                $table->text('core_question')->nullable()->after('keyword');
            }
            if (! Schema::hasColumn('titles', 'reference_urls')) {
                $table->json('reference_urls')->nullable()->after('core_question');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('titles')) {
            return;
        }

        Schema::table('titles', function (Blueprint $table): void {
            if (Schema::hasColumn('titles', 'reference_urls')) {
                $table->dropColumn('reference_urls');
            }
            if (Schema::hasColumn('titles', 'core_question')) {
                $table->dropColumn('core_question');
            }
        });
    }
};
