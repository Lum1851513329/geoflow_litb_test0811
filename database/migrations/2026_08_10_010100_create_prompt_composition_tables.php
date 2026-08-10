<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prompt_modules', function (Blueprint $table): void {
            $table->id();
            $table->string('module_key', 150)->unique();
            $table->string('display_name', 150);
            $table->string('module_type', 50);
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->jsonb('applicability')->nullable();
            $table->boolean('is_mandatory')->default(false);
            $table->integer('default_priority')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->foreignId('owned_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->timestamps();
            $table->index(['module_type', 'is_enabled']);
        });

        Schema::create('prompt_module_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prompt_module_id')->constrained('prompt_modules')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->longText('content');
            $table->jsonb('variable_contract');
            $table->jsonb('output_contract')->nullable();
            $table->jsonb('parameter_defaults')->nullable();
            $table->string('content_sha256', 64)->nullable();
            $table->string('lifecycle_status', 30)->default('draft');
            $table->text('change_note')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['prompt_module_id', 'version']);
            $table->unique(['prompt_module_id', 'content_sha256']);
            $table->index(['prompt_module_id', 'lifecycle_status']);
        });

        Schema::table('prompt_modules', function (Blueprint $table): void {
            $table->foreignId('current_version_id')->nullable()->constrained('prompt_module_versions')->restrictOnDelete();
        });

        Schema::create('prompt_presets', function (Blueprint $table): void {
            $table->id();
            $table->string('preset_key', 150)->unique();
            $table->string('display_name', 150);
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->foreignId('owned_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('prompt_preset_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prompt_preset_id')->constrained('prompt_presets')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->jsonb('matching_rules');
            $table->jsonb('generation_policy');
            $table->jsonb('conflict_policy')->nullable();
            $table->string('lifecycle_status', 30)->default('draft');
            $table->text('change_note')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->restrictOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['prompt_preset_id', 'version']);
            $table->index(['prompt_preset_id', 'lifecycle_status']);
        });

        Schema::table('prompt_presets', function (Blueprint $table): void {
            $table->foreignId('current_version_id')->nullable()->constrained('prompt_preset_versions')->restrictOnDelete();
        });

        Schema::create('prompt_preset_module_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prompt_preset_version_id')->constrained('prompt_preset_versions')->restrictOnDelete();
            $table->foreignId('prompt_module_version_id')->constrained('prompt_module_versions')->restrictOnDelete();
            $table->unsignedInteger('sort_order');
            $table->boolean('is_required')->default(false);
            $table->string('selection_source', 30)->default('preset');
            $table->jsonb('configuration')->nullable();
            $table->timestamps();
            $table->unique(['prompt_preset_version_id', 'prompt_module_version_id']);
            $table->unique(['prompt_preset_version_id', 'sort_order']);
        });

        $this->createIntegrityGuards();
    }

    public function down(): void
    {
        $this->dropIntegrityGuards();
        Schema::dropIfExists('prompt_preset_module_versions');
        Schema::table('prompt_presets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_version_id');
        });
        Schema::dropIfExists('prompt_preset_versions');
        Schema::dropIfExists('prompt_presets');
        Schema::table('prompt_modules', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_version_id');
        });
        Schema::dropIfExists('prompt_module_versions');
        Schema::dropIfExists('prompt_modules');
    }

    private function createIntegrityGuards(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE FUNCTION enforce_prompt_module_version_sequence() RETURNS trigger AS $$
DECLARE expected_version integer;
BEGIN
    PERFORM 1 FROM prompt_modules WHERE id = NEW.prompt_module_id FOR UPDATE;
    SELECT COALESCE(MAX(version), 0) + 1 INTO expected_version
    FROM prompt_module_versions WHERE prompt_module_id = NEW.prompt_module_id;
    IF NEW.version <> expected_version THEN
        RAISE EXCEPTION 'prompt_module_versions.version must be the next version for its module';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER prompt_module_versions_enforce_sequence
BEFORE INSERT ON prompt_module_versions
FOR EACH ROW EXECUTE FUNCTION enforce_prompt_module_version_sequence();
CREATE FUNCTION guard_prompt_module_version_immutable() RETURNS trigger AS $$
BEGIN
    IF NEW.prompt_module_id IS DISTINCT FROM OLD.prompt_module_id
        OR NEW.version IS DISTINCT FROM OLD.version
        OR NEW.content IS DISTINCT FROM OLD.content
        OR NEW.variable_contract IS DISTINCT FROM OLD.variable_contract
        OR NEW.output_contract IS DISTINCT FROM OLD.output_contract
        OR NEW.parameter_defaults IS DISTINCT FROM OLD.parameter_defaults
        OR NEW.content_sha256 IS DISTINCT FROM OLD.content_sha256
        OR NEW.created_by_admin_id IS DISTINCT FROM OLD.created_by_admin_id THEN
        RAISE EXCEPTION 'prompt module version snapshots are immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER prompt_module_versions_guard_update
BEFORE UPDATE ON prompt_module_versions
FOR EACH ROW EXECUTE FUNCTION guard_prompt_module_version_immutable();
CREATE FUNCTION prevent_prompt_module_version_delete() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'prompt module versions cannot be deleted';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER prompt_module_versions_guard_delete
BEFORE DELETE ON prompt_module_versions
FOR EACH ROW EXECUTE FUNCTION prevent_prompt_module_version_delete();
CREATE FUNCTION enforce_prompt_preset_version_sequence() RETURNS trigger AS $$
DECLARE expected_version integer;
BEGIN
    PERFORM 1 FROM prompt_presets WHERE id = NEW.prompt_preset_id FOR UPDATE;
    SELECT COALESCE(MAX(version), 0) + 1 INTO expected_version
    FROM prompt_preset_versions WHERE prompt_preset_id = NEW.prompt_preset_id;
    IF NEW.version <> expected_version THEN
        RAISE EXCEPTION 'prompt_preset_versions.version must be the next version for its preset';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER prompt_preset_versions_enforce_sequence
BEFORE INSERT ON prompt_preset_versions
FOR EACH ROW EXECUTE FUNCTION enforce_prompt_preset_version_sequence();
CREATE FUNCTION guard_prompt_preset_version_immutable() RETURNS trigger AS $$
BEGIN
    IF NEW.prompt_preset_id IS DISTINCT FROM OLD.prompt_preset_id
        OR NEW.version IS DISTINCT FROM OLD.version
        OR NEW.matching_rules IS DISTINCT FROM OLD.matching_rules
        OR NEW.generation_policy IS DISTINCT FROM OLD.generation_policy
        OR NEW.conflict_policy IS DISTINCT FROM OLD.conflict_policy
        OR NEW.created_by_admin_id IS DISTINCT FROM OLD.created_by_admin_id THEN
        RAISE EXCEPTION 'prompt preset version snapshots are immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER prompt_preset_versions_guard_update
BEFORE UPDATE ON prompt_preset_versions
FOR EACH ROW EXECUTE FUNCTION guard_prompt_preset_version_immutable();
CREATE FUNCTION prevent_prompt_preset_version_delete() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'prompt preset versions cannot be deleted';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER prompt_preset_versions_guard_delete
BEFORE DELETE ON prompt_preset_versions
FOR EACH ROW EXECUTE FUNCTION prevent_prompt_preset_version_delete();
SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
CREATE TRIGGER prompt_module_versions_enforce_sequence
BEFORE INSERT ON prompt_module_versions
FOR EACH ROW WHEN NEW.version <> COALESCE((SELECT MAX(version) + 1 FROM prompt_module_versions WHERE prompt_module_id = NEW.prompt_module_id), 1)
BEGIN SELECT RAISE(ABORT, 'prompt_module_versions.version must be the next version for its module'); END;
CREATE TRIGGER prompt_module_versions_guard_update
BEFORE UPDATE ON prompt_module_versions
FOR EACH ROW WHEN NEW.prompt_module_id IS NOT OLD.prompt_module_id OR NEW.version IS NOT OLD.version OR NEW.content IS NOT OLD.content OR NEW.variable_contract IS NOT OLD.variable_contract OR NEW.output_contract IS NOT OLD.output_contract OR NEW.parameter_defaults IS NOT OLD.parameter_defaults OR NEW.content_sha256 IS NOT OLD.content_sha256 OR NEW.created_by_admin_id IS NOT OLD.created_by_admin_id
BEGIN SELECT RAISE(ABORT, 'prompt module version snapshots are immutable'); END;
CREATE TRIGGER prompt_module_versions_guard_delete
BEFORE DELETE ON prompt_module_versions
FOR EACH ROW BEGIN SELECT RAISE(ABORT, 'prompt module versions cannot be deleted'); END;
CREATE TRIGGER prompt_preset_versions_enforce_sequence
BEFORE INSERT ON prompt_preset_versions
FOR EACH ROW WHEN NEW.version <> COALESCE((SELECT MAX(version) + 1 FROM prompt_preset_versions WHERE prompt_preset_id = NEW.prompt_preset_id), 1)
BEGIN SELECT RAISE(ABORT, 'prompt_preset_versions.version must be the next version for its preset'); END;
CREATE TRIGGER prompt_preset_versions_guard_update
BEFORE UPDATE ON prompt_preset_versions
FOR EACH ROW WHEN NEW.prompt_preset_id IS NOT OLD.prompt_preset_id OR NEW.version IS NOT OLD.version OR NEW.matching_rules IS NOT OLD.matching_rules OR NEW.generation_policy IS NOT OLD.generation_policy OR NEW.conflict_policy IS NOT OLD.conflict_policy OR NEW.created_by_admin_id IS NOT OLD.created_by_admin_id
BEGIN SELECT RAISE(ABORT, 'prompt preset version snapshots are immutable'); END;
CREATE TRIGGER prompt_preset_versions_guard_delete
BEFORE DELETE ON prompt_preset_versions
FOR EACH ROW BEGIN SELECT RAISE(ABORT, 'prompt preset versions cannot be deleted'); END;
SQL);
        }
    }

    private function dropIntegrityGuards(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS prompt_preset_versions_guard_delete ON prompt_preset_versions;
DROP TRIGGER IF EXISTS prompt_preset_versions_guard_update ON prompt_preset_versions;
DROP TRIGGER IF EXISTS prompt_preset_versions_enforce_sequence ON prompt_preset_versions;
DROP TRIGGER IF EXISTS prompt_module_versions_guard_delete ON prompt_module_versions;
DROP TRIGGER IF EXISTS prompt_module_versions_guard_update ON prompt_module_versions;
DROP TRIGGER IF EXISTS prompt_module_versions_enforce_sequence ON prompt_module_versions;
DROP FUNCTION IF EXISTS prevent_prompt_preset_version_delete();
DROP FUNCTION IF EXISTS guard_prompt_preset_version_immutable();
DROP FUNCTION IF EXISTS enforce_prompt_preset_version_sequence();
DROP FUNCTION IF EXISTS prevent_prompt_module_version_delete();
DROP FUNCTION IF EXISTS guard_prompt_module_version_immutable();
DROP FUNCTION IF EXISTS enforce_prompt_module_version_sequence();
SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            foreach ([
                'prompt_preset_versions_guard_delete',
                'prompt_preset_versions_guard_update',
                'prompt_preset_versions_enforce_sequence',
                'prompt_module_versions_guard_delete',
                'prompt_module_versions_guard_update',
                'prompt_module_versions_enforce_sequence',
            ] as $trigger) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            }
        }
    }
};
