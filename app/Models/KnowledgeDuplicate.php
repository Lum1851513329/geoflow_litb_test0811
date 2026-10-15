<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeDuplicate extends Model
{
    protected $fillable = [
        'brand_id',
        'site_id',
        'import_batch_id',
        'import_row_id',
        'duplicate_document_id',
        'canonical_document_id',
        'duplicate_kind',
        'duplicate_score',
        'duplicate_reason',
        'rollback_metadata',
    ];

    protected function casts(): array
    {
        return [
            'brand_id' => 'integer',
            'site_id' => 'integer',
            'import_batch_id' => 'integer',
            'import_row_id' => 'integer',
            'duplicate_document_id' => 'integer',
            'canonical_document_id' => 'integer',
            'duplicate_score' => 'decimal:4',
            'rollback_metadata' => 'array',
        ];
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(KnowledgeImportBatch::class, 'import_batch_id');
    }

    public function importRow(): BelongsTo
    {
        return $this->belongsTo(KnowledgeImportRow::class, 'import_row_id');
    }

    public function duplicateDocument(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class, 'duplicate_document_id');
    }

    public function canonicalDocument(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class, 'canonical_document_id');
    }
}
