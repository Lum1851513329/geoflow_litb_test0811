<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class KeywordClusterKeyword extends Pivot
{
    protected $table = 'keyword_cluster_keywords';

    public $incrementing = true;

    protected $fillable = ['cluster_id', 'keyword_id', 'role', 'weight', 'sort_order'];

    protected function casts(): array
    {
        return ['cluster_id' => 'integer', 'keyword_id' => 'integer', 'weight' => 'decimal:4', 'sort_order' => 'integer'];
    }

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(KeywordCluster::class, 'cluster_id');
    }

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class, 'keyword_id');
    }
}
