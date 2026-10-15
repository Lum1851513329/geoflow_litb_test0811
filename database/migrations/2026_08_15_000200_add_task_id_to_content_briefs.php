<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('content_briefs') && ! Schema::hasColumn('content_briefs', 'task_id')) {
            Schema::table('content_briefs', function (Blueprint $table): void {
                $table->foreignId('task_id')->nullable()->after('created_by_admin_id')->constrained('tasks')->nullOnDelete();
                $table->index('task_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('content_briefs') && Schema::hasColumn('content_briefs', 'task_id')) {
            Schema::table('content_briefs', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('task_id');
            });
        }
    }
};
