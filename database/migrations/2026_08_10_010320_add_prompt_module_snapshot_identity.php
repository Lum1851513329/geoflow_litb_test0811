<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $migrate = function (): void {
            $this->dropModuleVersionGuards();
            Schema::table('prompt_module_versions', function (Blueprint $table): void {
                $table->string('snapshot_sha256', 64)->nullable()->after('content_sha256');
            });
            Schema::table('prompt_module_versions', function (Blueprint $table): void {
                $table->dropUnique(['prompt_module_id', 'content_sha256']);
                $table->index(['prompt_module_id', 'content_sha256']);
            });
            $this->backfillDigests();
            Schema::table('prompt_module_versions', function (Blueprint $table): void {
                $table->unique(['prompt_module_id', 'snapshot_sha256']);
            });
            $this->createModuleVersionGuards(true);
        };

        if (DB::getDriverName() === 'sqlite') {
            Schema::withoutForeignKeyConstraints($migrate);

            return;
        }

        $migrate();
    }

    public function down(): void
    {
        $duplicate = DB::table('prompt_module_versions')
            ->select(['prompt_module_id', 'content_sha256'])
            ->selectRaw('COUNT(*) AS duplicate_count')
            ->groupBy('prompt_module_id', 'content_sha256')
            ->havingRaw('COUNT(*) > 1')
            ->first();
        if ($duplicate !== null) {
            throw new RuntimeException(
                'Cannot remove prompt module snapshot identity while a module has multiple versions with the same content digest.',
            );
        }

        $rollback = function (): void {
            $this->dropModuleVersionGuards();
            Schema::table('prompt_module_versions', function (Blueprint $table): void {
                $table->dropUnique(['prompt_module_id', 'snapshot_sha256']);
                $table->dropIndex(['prompt_module_id', 'content_sha256']);
                $table->unique(['prompt_module_id', 'content_sha256']);
            });
            Schema::table('prompt_module_versions', function (Blueprint $table): void {
                $table->dropColumn('snapshot_sha256');
            });
            $this->createModuleVersionGuards(false);
        };

        if (DB::getDriverName() === 'sqlite') {
            Schema::withoutForeignKeyConstraints($rollback);

            return;
        }

        $rollback();
    }

    private function backfillDigests(): void
    {
        DB::table('prompt_module_versions')
            ->orderBy('id')
            ->chunkById(100, function ($versions): void {
                foreach ($versions as $version) {
                    $content = (string) $version->content;
                    DB::table('prompt_module_versions')->where('id', $version->id)->update([
                        'content_sha256' => hash('sha256', $content),
                        'snapshot_sha256' => $this->snapshotDigest(
                            $content,
                            $this->decodeJson($version->variable_contract),
                            $this->decodeJson($version->output_contract),
                            $this->decodeJson($version->parameter_defaults),
                        ),
                    ]);
                }
            });
    }

    private function decodeJson(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }

    private function snapshotDigest(
        string $content,
        mixed $variableContract,
        mixed $outputContract,
        mixed $parameterDefaults,
    ): string {
        return hash('sha256', $this->canonicalJson([
            'content' => $content,
            'variable_contract' => $variableContract,
            'output_contract' => $outputContract,
            'parameter_defaults' => $parameterDefaults,
        ]));
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode(
            $this->canonicalize($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function dropModuleVersionGuards(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS prompt_module_versions_guard_delete ON prompt_module_versions;
DROP TRIGGER IF EXISTS prompt_module_versions_guard_update ON prompt_module_versions;
DROP TRIGGER IF EXISTS prompt_module_versions_enforce_sequence ON prompt_module_versions;
DROP FUNCTION IF EXISTS prevent_prompt_module_version_delete();
DROP FUNCTION IF EXISTS guard_prompt_module_version_immutable();
DROP FUNCTION IF EXISTS enforce_prompt_module_version_sequence();
SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            foreach ([
                'prompt_module_versions_guard_delete',
                'prompt_module_versions_guard_update',
                'prompt_module_versions_enforce_sequence',
            ] as $trigger) {
                DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            }
        }
    }

    private function createModuleVersionGuards(bool $includeSnapshot): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $snapshotGuard = $includeSnapshot
                ? "\n        OR NEW.snapshot_sha256 IS DISTINCT FROM OLD.snapshot_sha256"
                : '';
            DB::unprepared(<<<SQL
CREATE FUNCTION enforce_prompt_module_version_sequence() RETURNS trigger AS \$\$
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
\$\$ LANGUAGE plpgsql;
CREATE TRIGGER prompt_module_versions_enforce_sequence
BEFORE INSERT ON prompt_module_versions
FOR EACH ROW EXECUTE FUNCTION enforce_prompt_module_version_sequence();
CREATE FUNCTION guard_prompt_module_version_immutable() RETURNS trigger AS \$\$
BEGIN
    IF NEW.prompt_module_id IS DISTINCT FROM OLD.prompt_module_id
        OR NEW.version IS DISTINCT FROM OLD.version
        OR NEW.content IS DISTINCT FROM OLD.content
        OR NEW.variable_contract IS DISTINCT FROM OLD.variable_contract
        OR NEW.output_contract IS DISTINCT FROM OLD.output_contract
        OR NEW.parameter_defaults IS DISTINCT FROM OLD.parameter_defaults
        OR NEW.content_sha256 IS DISTINCT FROM OLD.content_sha256{$snapshotGuard}
        OR NEW.created_by_admin_id IS DISTINCT FROM OLD.created_by_admin_id THEN
        RAISE EXCEPTION 'prompt module version snapshots are immutable';
    END IF;
    RETURN NEW;
END;
\$\$ LANGUAGE plpgsql;
CREATE TRIGGER prompt_module_versions_guard_update
BEFORE UPDATE ON prompt_module_versions
FOR EACH ROW EXECUTE FUNCTION guard_prompt_module_version_immutable();
CREATE FUNCTION prevent_prompt_module_version_delete() RETURNS trigger AS \$\$
BEGIN
    RAISE EXCEPTION 'prompt module versions cannot be deleted';
END;
\$\$ LANGUAGE plpgsql;
CREATE TRIGGER prompt_module_versions_guard_delete
BEFORE DELETE ON prompt_module_versions
FOR EACH ROW EXECUTE FUNCTION prevent_prompt_module_version_delete();
SQL);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $snapshotGuard = $includeSnapshot ? ' OR NEW.snapshot_sha256 IS NOT OLD.snapshot_sha256' : '';
            DB::unprepared(<<<SQL
CREATE TRIGGER prompt_module_versions_enforce_sequence
BEFORE INSERT ON prompt_module_versions
FOR EACH ROW WHEN NEW.version <> COALESCE((SELECT MAX(version) + 1 FROM prompt_module_versions WHERE prompt_module_id = NEW.prompt_module_id), 1)
BEGIN SELECT RAISE(ABORT, 'prompt_module_versions.version must be the next version for its module'); END;
CREATE TRIGGER prompt_module_versions_guard_update
BEFORE UPDATE ON prompt_module_versions
FOR EACH ROW WHEN NEW.prompt_module_id IS NOT OLD.prompt_module_id OR NEW.version IS NOT OLD.version OR NEW.content IS NOT OLD.content OR NEW.variable_contract IS NOT OLD.variable_contract OR NEW.output_contract IS NOT OLD.output_contract OR NEW.parameter_defaults IS NOT OLD.parameter_defaults OR NEW.content_sha256 IS NOT OLD.content_sha256{$snapshotGuard} OR NEW.created_by_admin_id IS NOT OLD.created_by_admin_id
BEGIN SELECT RAISE(ABORT, 'prompt module version snapshots are immutable'); END;
CREATE TRIGGER prompt_module_versions_guard_delete
BEFORE DELETE ON prompt_module_versions
FOR EACH ROW BEGIN SELECT RAISE(ABORT, 'prompt module versions cannot be deleted'); END;
SQL);
        }
    }
};
