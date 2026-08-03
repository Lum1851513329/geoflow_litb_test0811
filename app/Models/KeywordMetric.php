<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KeywordMetric extends Model
{
    protected $fillable = ['keyword_id', 'import_batch_id', 'source_type', 'period_start', 'period_end', 'market_code', 'language_code', 'device', 'clicks', 'impressions', 'ctr', 'average_position', 'cost', 'conversions', 'source_payload'];

    protected function casts(): array
    {
        return ['keyword_id' => 'integer', 'import_batch_id' => 'integer', 'period_start' => 'date', 'period_end' => 'date', 'clicks' => 'integer', 'impressions' => 'integer', 'ctr' => 'decimal:6', 'average_position' => 'decimal:4', 'cost' => 'decimal:4', 'conversions' => 'decimal:4', 'source_payload' => 'array'];
    }

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class, 'keyword_id');
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(KeywordImportBatch::class, 'import_batch_id');
    }
}
