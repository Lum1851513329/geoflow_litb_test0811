<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_briefs', function (Blueprint $table): void {
            $table->id();
            $table->string('topic', 300);
            $table->string('market_code', 10);
            $table->string('language_code', 10);
            $table->text('audience');
            $table->text('core_question');
            $table->text('business_goal');
            $table->string('content_type', 50);
            $table->timestamp('planned_publish_at')->nullable();
            $table->foreignId('keyword_cluster_id')->nullable()->constrained('keyword_clusters')->restrictOnDelete();
            $table->foreignId('primary_keyword_id')->nullable()->constrained('keywords')->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('target_url', 1000)->nullable();
            $table->jsonb('required_mentions')->nullable();
            $table->jsonb('prohibited_information')->nullable();
            $table->jsonb('campaign_window')->nullable();
            $table->jsonb('reference_urls')->nullable();
            $table->string('cta_strength', 30)->nullable();
            $table->string('status', 30)->default('draft');
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('content_strategy_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_brief_id')->constrained('content_briefs')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->jsonb('strategy');
            $table->jsonb('suggestion_snapshot')->nullable();
            $table->string('lifecycle_status', 30)->default('draft');
            $table->text('change_note')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->foreignId('confirmed_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['content_brief_id', 'version']);
            $table->unique(['id', 'content_brief_id'], 'content_strategy_versions_id_brief_unique');
            $table->index(['content_brief_id', 'lifecycle_status']);
        });

        Schema::create('content_blueprint_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_brief_id')->constrained('content_briefs')->restrictOnDelete();
            $table->unsignedBigInteger('content_strategy_version_id');
            $table->unsignedInteger('version');
            $table->jsonb('blueprint');
            $table->jsonb('validation_report')->nullable();
            $table->string('lifecycle_status', 30)->default('draft');
            $table->text('change_note')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->foreignId('confirmed_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['content_brief_id', 'version']);
            $table->unique(['id', 'content_brief_id'], 'content_blueprint_versions_id_brief_unique');
            $table->foreign(['content_strategy_version_id', 'content_brief_id'], 'content_blueprint_versions_strategy_brief_foreign')
                ->references(['id', 'content_brief_id'])
                ->on('content_strategy_versions')
                ->restrictOnDelete();
            $table->index(['content_brief_id', 'lifecycle_status']);
            $table->index('content_strategy_version_id');
        });

        $this->createIntegrityGuards();
    }

    public function down(): void
    {
        $this->dropIntegrityGuards();
        Schema::dropIfExists('content_blueprint_versions');
        Schema::dropIfExists('content_strategy_versions');
        Schema::dropIfExists('content_briefs');
    }

    private function createIntegrityGuards(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE FUNCTION enforce_content_strategy_version_sequence() RETURNS trigger AS $$
DECLARE expected_version integer;
BEGIN
    PERFORM 1 FROM content_briefs WHERE id = NEW.content_brief_id FOR UPDATE;
    SELECT COALESCE(MAX(version), 0) + 1 INTO expected_version
    FROM content_strategy_versions WHERE content_brief_id = NEW.content_brief_id;
    IF NEW.version <> expected_version THEN
        RAISE EXCEPTION 'content_strategy_versions.version must be the next version for its brief';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER content_strategy_versions_enforce_sequence
BEFORE INSERT ON content_strategy_versions
FOR EACH ROW EXECUTE FUNCTION enforce_content_strategy_version_sequence();
CREATE FUNCTION guard_content_strategy_version_immutable() RETURNS trigger AS $$
BEGIN
    IF NEW.content_brief_id IS DISTINCT FROM OLD.content_brief_id
        OR NEW.version IS DISTINCT FROM OLD.version
        OR NEW.strategy IS DISTINCT FROM OLD.strategy
        OR NEW.suggestion_snapshot IS DISTINCT FROM OLD.suggestion_snapshot
        OR NEW.created_by_admin_id IS DISTINCT FROM OLD.created_by_admin_id THEN
        RAISE EXCEPTION 'content strategy version snapshots are immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER content_strategy_versions_guard_update
BEFORE UPDATE ON content_strategy_versions
FOR EACH ROW EXECUTE FUNCTION guard_content_strategy_version_immutable();
CREATE FUNCTION prevent_content_strategy_version_delete() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'content strategy versions cannot be deleted';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER content_strategy_versions_guard_delete
BEFORE DELETE ON content_strategy_versions
FOR EACH ROW EXECUTE FUNCTION prevent_content_strategy_version_delete();
CREATE FUNCTION enforce_content_blueprint_version_sequence() RETURNS trigger AS $$
DECLARE expected_version integer;
BEGIN
    PERFORM 1 FROM content_briefs WHERE id = NEW.content_brief_id FOR UPDATE;
    SELECT COALESCE(MAX(version), 0) + 1 INTO expected_version
    FROM content_blueprint_versions WHERE content_brief_id = NEW.content_brief_id;
    IF NEW.version <> expected_version THEN
        RAISE EXCEPTION 'content_blueprint_versions.version must be the next version for its brief';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER content_blueprint_versions_enforce_sequence
BEFORE INSERT ON content_blueprint_versions
FOR EACH ROW EXECUTE FUNCTION enforce_content_blueprint_version_sequence();
CREATE FUNCTION guard_content_blueprint_version_immutable() RETURNS trigger AS $$
BEGIN
    IF NEW.content_brief_id IS DISTINCT FROM OLD.content_brief_id
        OR NEW.content_strategy_version_id IS DISTINCT FROM OLD.content_strategy_version_id
        OR NEW.version IS DISTINCT FROM OLD.version
        OR NEW.blueprint IS DISTINCT FROM OLD.blueprint
        OR NEW.validation_report IS DISTINCT FROM OLD.validation_report
        OR NEW.created_by_admin_id IS DISTINCT FROM OLD.created_by_admin_id THEN
        RAISE EXCEPTION 'content blueprint version snapshots are immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER content_blueprint_versions_guard_update
BEFORE UPDATE ON content_blueprint_versions
FOR EACH ROW EXECUTE FUNCTION guard_content_blueprint_version_immutable();
CREATE FUNCTION prevent_content_blueprint_version_delete() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'content blueprint versions cannot be deleted';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER content_blueprint_versions_guard_delete
BEFORE DELETE ON content_blueprint_versions
FOR EACH ROW EXECUTE FUNCTION prevent_content_blueprint_version_delete();
SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER content_strategy_versions_enforce_sequence
BEFORE INSERT ON content_strategy_versions
FOR EACH ROW WHEN NEW.version <> COALESCE((SELECT MAX(version) + 1 FROM content_strategy_versions WHERE content_brief_id = NEW.content_brief_id), 1)
BEGIN SELECT RAISE(ABORT, 'content_strategy_versions.version must be the next version for its brief'); END;
CREATE TRIGGER content_strategy_versions_guard_update
BEFORE UPDATE ON content_strategy_versions
FOR EACH ROW WHEN NEW.content_brief_id IS NOT OLD.content_brief_id OR NEW.version IS NOT OLD.version OR NEW.strategy IS NOT OLD.strategy OR NEW.suggestion_snapshot IS NOT OLD.suggestion_snapshot OR NEW.created_by_admin_id IS NOT OLD.created_by_admin_id
BEGIN SELECT RAISE(ABORT, 'content strategy version snapshots are immutable'); END;
CREATE TRIGGER content_strategy_versions_guard_delete
BEFORE DELETE ON content_strategy_versions
FOR EACH ROW BEGIN SELECT RAISE(ABORT, 'content strategy versions cannot be deleted'); END;
CREATE TRIGGER content_blueprint_versions_enforce_sequence
BEFORE INSERT ON content_blueprint_versions
FOR EACH ROW WHEN NEW.version <> COALESCE((SELECT MAX(version) + 1 FROM content_blueprint_versions WHERE content_brief_id = NEW.content_brief_id), 1)
BEGIN SELECT RAISE(ABORT, 'content_blueprint_versions.version must be the next version for its brief'); END;
CREATE TRIGGER content_blueprint_versions_guard_update
BEFORE UPDATE ON content_blueprint_versions
FOR EACH ROW WHEN NEW.content_brief_id IS NOT OLD.content_brief_id OR NEW.content_strategy_version_id IS NOT OLD.content_strategy_version_id OR NEW.version IS NOT OLD.version OR NEW.blueprint IS NOT OLD.blueprint OR NEW.validation_report IS NOT OLD.validation_report OR NEW.created_by_admin_id IS NOT OLD.created_by_admin_id
BEGIN SELECT RAISE(ABORT, 'content blueprint version snapshots are immutable'); END;
CREATE TRIGGER content_blueprint_versions_guard_delete
BEFORE DELETE ON content_blueprint_versions
FOR EACH ROW BEGIN SELECT RAISE(ABORT, 'content blueprint versions cannot be deleted'); END;
SQL);
        }
    }

    private function dropIntegrityGuards(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS content_blueprint_versions_guard_delete ON content_blueprint_versions;
DROP TRIGGER IF EXISTS content_blueprint_versions_guard_update ON content_blueprint_versions;
DROP TRIGGER IF EXISTS content_blueprint_versions_enforce_sequence ON content_blueprint_versions;
DROP TRIGGER IF EXISTS content_strategy_versions_guard_delete ON content_strategy_versions;
DROP TRIGGER IF EXISTS content_strategy_versions_guard_update ON content_strategy_versions;
DROP TRIGGER IF EXISTS content_strategy_versions_enforce_sequence ON content_strategy_versions;
DROP FUNCTION IF EXISTS prevent_content_blueprint_version_delete();
DROP FUNCTION IF EXISTS guard_content_blueprint_version_immutable();
DROP FUNCTION IF EXISTS enforce_content_blueprint_version_sequence();
DROP FUNCTION IF EXISTS prevent_content_strategy_version_delete();
DROP FUNCTION IF EXISTS guard_content_strategy_version_immutable();
DROP FUNCTION IF EXISTS enforce_content_strategy_version_sequence();
SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            foreach ([
                'content_blueprint_versions_guard_delete',
                'content_blueprint_versions_guard_update',
                'content_blueprint_versions_enforce_sequence',
                'content_strategy_versions_guard_delete',
                'content_strategy_versions_guard_update',
                'content_strategy_versions_enforce_sequence',
            ] as $trigger) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            }
        }
    }
};
