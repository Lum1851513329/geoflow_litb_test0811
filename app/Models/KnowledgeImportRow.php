<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeImportRow extends Model
{
    protected $fillable = [
        'batch_id',
        'row_number',
        'staged_payload',
        'outcome',
        'canonical_document_id',
        'duplicate_kind',
        'duplicate_score',
        'duplicate_reason',
        'error_message',
        'rollback_metadata',
    ];

    protected function casts(): array
    {
        return [
            'batch_id' => 'integer',
            'row_number' => 'integer',
            'staged_payload' => 'array',
            'canonical_document_id' => 'integer',
            'duplicate_score' => 'decimal:4',
            'rollback_metadata' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(KnowledgeImportBatch::class, 'batch_id');
    }

    public function canonicalDocument(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class, 'canonical_document_id');
    }

    public function duplicateAudits(): HasMany
    {
        return $this->hasMany(KnowledgeDuplicate::class, 'import_row_id');
    }
}
