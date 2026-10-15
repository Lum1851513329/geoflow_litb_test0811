<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ContentGenerationRunEvidence extends Model
{
    protected $fillable = [
        'content_generation_run_id', 'knowledge_chunk_id', 'evidence_key',
        'evidence_snapshot', 'is_required',
    ];

    protected function casts(): array
    {
        return [
            'content_generation_run_id' => 'integer',
            'knowledge_chunk_id' => 'integer',
            'evidence_snapshot' => 'array',
            'is_required' => 'boolean',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ContentGenerationRun::class, 'content_generation_run_id');
    }

    public function knowledgeChunk(): BelongsTo
    {
        return $this->belongsTo(KnowledgeChunk::class, 'knowledge_chunk_id');
    }
}
