<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KeywordImportRow extends Model
{
    protected $fillable = ['batch_id', 'sheet_name', 'row_number', 'source_payload', 'keyword_id', 'normalized_keyword', 'status', 'error_message'];

    protected function casts(): array
    {
        return ['batch_id' => 'integer', 'row_number' => 'integer', 'source_payload' => 'array', 'keyword_id' => 'integer'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(KeywordImportBatch::class, 'batch_id');
    }

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class, 'keyword_id');
    }
}
