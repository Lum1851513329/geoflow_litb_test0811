<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KeywordImportBatch extends Model
{
    protected $fillable = ['library_id', 'original_filename', 'stored_path', 'file_sha256', 'source_type', 'status', 'mapping_json', 'metadata_json', 'period_start', 'period_end', 'total_count', 'processed_count', 'imported_count', 'duplicate_count', 'rejected_count', 'retained_shared_keyword_count', 'error_summary', 'created_by', 'started_at', 'completed_at', 'reverted_at'];

    protected function casts(): array
    {
        return ['library_id' => 'integer', 'mapping_json' => 'array', 'metadata_json' => 'array', 'period_start' => 'date', 'period_end' => 'date', 'total_count' => 'integer', 'processed_count' => 'integer', 'imported_count' => 'integer', 'duplicate_count' => 'integer', 'rejected_count' => 'integer', 'retained_shared_keyword_count' => 'integer', 'created_by' => 'integer', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'reverted_at' => 'datetime'];
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(KeywordLibrary::class, 'library_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(KeywordImportRow::class, 'batch_id');
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(KeywordMetric::class, 'import_batch_id');
    }
}
