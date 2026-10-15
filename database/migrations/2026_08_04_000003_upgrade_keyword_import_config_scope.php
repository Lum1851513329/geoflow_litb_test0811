<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OLD_INDEX = 'keyword_import_batches_file_config_unique';

    public function up(): void
    {
        if (! Schema::hasColumn('keyword_import_batches', 'import_config_hash')) {
            Schema::table('keyword_import_batches', function (Blueprint $table): void {
                $table->string('import_config_hash', 64)->nullable()->after('file_sha256');
            });
        }
        if (! Schema::hasColumn('keyword_import_batches', 'config_scope')) {
            Schema::table('keyword_import_batches', function (Blueprint $table): void {
                $table->string('config_scope', 64)->default('legacy')->after('import_config_hash');
            });
        }
        if ($this->hasIndex(self::OLD_INDEX)) {
            Schema::table('keyword_import_batches', function (Blueprint $table): void {
                $table->dropUnique(self::OLD_INDEX);
            });
        }

        DB::table('keyword_import_batches')->orderBy('id')->eachById(function (object $batch): void {
            $hash = $this->configHash($batch);
            DB::table('keyword_import_batches')->where('id', $batch->id)->update([
                'import_config_hash' => $hash,
                'config_scope' => $batch->import_config_hash === null ? 'legacy-'.$batch->id : $hash,
            ]);
        });

        if (! $this->hasIndex(self::OLD_INDEX)) {
            Schema::table('keyword_import_batches', function (Blueprint $table): void {
                $table->unique(['file_sha256', 'config_scope'], self::OLD_INDEX);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('keyword_import_batches', 'config_scope')) {
            if ($this->hasIndex(self::OLD_INDEX)) {
                Schema::table('keyword_import_batches', function (Blueprint $table): void {
                    $table->dropUnique(self::OLD_INDEX);
                });
            }

            DB::table('keyword_import_batches')
                ->where('config_scope', 'like', 'legacy-%')
                ->update(['import_config_hash' => null]);
            Schema::table('keyword_import_batches', function (Blueprint $table): void {
                $table->dropColumn('config_scope');
            });
        }

        if (! $this->hasIndex(self::OLD_INDEX)) {
            Schema::table('keyword_import_batches', function (Blueprint $table): void {
                $table->unique(['file_sha256', 'import_config_hash'], self::OLD_INDEX);
            });
        }
    }

    private function hasIndex(string $name): bool
    {
        foreach (Schema::getIndexes('keyword_import_batches') as $index) {
            if (($index['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }

    private function configHash(object $batch): string
    {
        $config = [
            'library_id' => $batch->library_id === null ? null : (int) $batch->library_id,
            'source_type' => (string) $batch->source_type,
            'mapping_json' => $this->decodeJson($batch->mapping_json),
            'metadata_json' => $this->decodeJson($batch->metadata_json),
            'period_start' => $this->dateString($batch->period_start),
            'period_end' => $this->dateString($batch->period_end),
        ];

        return hash('sha256', json_encode($this->canonicalize($config), JSON_THROW_ON_ERROR));
    }

    /** @return array<mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function dateString(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return substr((string) $value, 0, 10);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item) => $this->canonicalize($item), $value);
        }
        ksort($value);

        return array_map(fn (mixed $item) => $this->canonicalize($item), $value);
    }
};
