<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class KeywordNodeKeyword extends Pivot
{
    protected $table = 'keyword_node_keyword';

    public $incrementing = true;

    protected $fillable = ['node_id', 'keyword_id', 'is_primary', 'confidence', 'source'];

    protected function casts(): array
    {
        return ['node_id' => 'integer', 'keyword_id' => 'integer', 'is_primary' => 'boolean', 'confidence' => 'decimal:4'];
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(KeywordNode::class, 'node_id');
    }

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class, 'keyword_id');
    }
}
