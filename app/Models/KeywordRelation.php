<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KeywordRelation extends Model
{
    protected $fillable = ['source_keyword_id', 'target_keyword_id', 'relation_type', 'source', 'confidence', 'confirmation_status', 'confirmed_by', 'confirmed_at'];

    protected function casts(): array
    {
        return ['source_keyword_id' => 'integer', 'target_keyword_id' => 'integer', 'confidence' => 'decimal:4', 'confirmed_by' => 'integer', 'confirmed_at' => 'datetime'];
    }

    public function sourceKeyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class, 'source_keyword_id');
    }

    public function targetKeyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class, 'target_keyword_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'confirmed_by');
    }
}
