<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class KnowledgeKeywordCluster extends Pivot
{
    protected $table = 'knowledge_keyword_clusters';

    public $incrementing = true;

    protected $fillable = [
        'knowledge_base_id',
        'keyword_cluster_id',
        'confidence',
        'source',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'knowledge_base_id' => 'integer',
            'keyword_cluster_id' => 'integer',
            'confidence' => 'decimal:4',
            'is_primary' => 'boolean',
        ];
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class, 'knowledge_base_id');
    }

    public function keywordCluster(): BelongsTo
    {
        return $this->belongsTo(KeywordCluster::class, 'keyword_cluster_id');
    }
}
