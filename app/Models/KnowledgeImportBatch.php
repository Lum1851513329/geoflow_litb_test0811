<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeImportBatch extends Model
{
    protected $fillable = [
        'brand_id',
        'site_id',
        'source_type',
        'status',
        'configuration_hash',
        'source_checksum',
        'total_count',
        'processed_count',
        'imported_count',
        'duplicate_count',
        'rejected_count',
        'error_log',
        'rollback_metadata',
        'started_at',
        'completed_at',
        'reverted_at',
    ];

    protected function casts(): array
    {
        return [
            'brand_id' => 'integer',
            'site_id' => 'integer',
            'total_count' => 'integer',
            'processed_count' => 'integer',
            'imported_count' => 'integer',
            'duplicate_count' => 'integer',
            'rejected_count' => 'integer',
            'error_log' => 'array',
            'rollback_metadata' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'reverted_at' => 'datetime',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBrand::class, 'brand_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSite::class, 'site_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(KnowledgeImportRow::class, 'batch_id');
    }

    public function duplicates(): HasMany
    {
        return $this->hasMany(KnowledgeDuplicate::class, 'import_batch_id');
    }
}
