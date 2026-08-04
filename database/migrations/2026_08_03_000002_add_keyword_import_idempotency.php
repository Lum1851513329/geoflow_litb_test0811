<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keyword_import_batches', function (Blueprint $table): void {
            $table->string('import_config_hash', 64)->default('legacy')->after('file_sha256');
            $table->string('config_scope', 64)->default('legacy')->after('import_config_hash');
        });

        DB::table('keyword_import_batches')->orderBy('id')->eachById(function (object $batch): void {
            DB::table('keyword_import_batches')->where('id', $batch->id)->update([
                'import_config_hash' => $this->legacyConfigHash($batch),
                'config_scope' => 'legacy-'.$batch->id,
            ]);
        });

        Schema::table('keyword_import_batches', function (Blueprint $table): void {
            $table->unique(['file_sha256', 'config_scope'], 'keyword_import_batches_file_config_unique');
        });

        Schema::table('keyword_metrics', function (Blueprint $table): void {
            $table->foreignId('import_row_id')
                ->nullable()
                ->after('import_batch_id')
                ->constrained('keyword_import_rows')
                ->nullOnDelete();
            $table->unique('import_row_id');
        });
    }

    public function down(): void
    {
        Schema::table('keyword_metrics', function (Blueprint $table): void {
            $table->dropUnique(['import_row_id']);
            $table->dropConstrainedForeignId('import_row_id');
        });

        Schema::table('keyword_import_batches', function (Blueprint $table): void {
            $table->dropUnique('keyword_import_batches_file_config_unique');
            $table->dropColumn(['import_config_hash', 'config_scope']);
        });
    }

    private function legacyConfigHash(object $batch): string
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
