<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['tasks', 'content_briefs'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('knowledge_mode', 32)->default('legacy_manual');
                $table->unsignedBigInteger('knowledge_brand_id')->nullable()->index();
                $table->unsignedBigInteger('knowledge_site_id')->nullable();
                $table->unsignedBigInteger('knowledge_directory_id')->nullable();
            });
        }
        Schema::table('content_generation_runs', fn (Blueprint $table) => $table->json('knowledge_retrieval_snapshot')->nullable());
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE FUNCTION guard_generation_knowledge_snapshot() RETURNS trigger AS $$
BEGIN
    IF NEW.knowledge_retrieval_snapshot::text IS DISTINCT FROM OLD.knowledge_retrieval_snapshot::text
        AND (OLD.knowledge_retrieval_snapshot IS NOT NULL OR OLD.status NOT IN ('pending', 'queued')) THEN
        RAISE EXCEPTION 'knowledge retrieval snapshot is immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER generation_knowledge_snapshot_guard BEFORE UPDATE ON content_generation_runs
FOR EACH ROW EXECUTE FUNCTION guard_generation_knowledge_snapshot();
SQL);
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER generation_knowledge_snapshot_guard BEFORE UPDATE ON content_generation_runs
FOR EACH ROW WHEN NEW.knowledge_retrieval_snapshot IS NOT OLD.knowledge_retrieval_snapshot
AND (OLD.knowledge_retrieval_snapshot IS NOT NULL OR OLD.status NOT IN ('pending', 'queued'))
BEGIN SELECT RAISE(ABORT, 'knowledge retrieval snapshot is immutable'); END;
SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS generation_knowledge_snapshot_guard ON content_generation_runs; DROP FUNCTION IF EXISTS guard_generation_knowledge_snapshot();');
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS generation_knowledge_snapshot_guard;');
        }
        Schema::table('content_generation_runs', fn (Blueprint $table) => $table->dropColumn('knowledge_retrieval_snapshot'));
        foreach (['tasks', 'content_briefs'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['knowledge_brand_id']);
                $table->dropColumn(['knowledge_mode', 'knowledge_brand_id', 'knowledge_site_id', 'knowledge_directory_id']);
            });
        }
    }
};
