<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KeywordOpportunityScore extends Model
{
    protected $fillable = ['cluster_id', 'score_version', 'period_start', 'period_end', 'total_score', 'components_json', 'penalties_json', 'inputs_json', 'weights_json'];

    protected function casts(): array
    {
        return ['cluster_id' => 'integer', 'period_start' => 'date', 'period_end' => 'date', 'total_score' => 'decimal:2', 'components_json' => 'array', 'penalties_json' => 'array', 'inputs_json' => 'array', 'weights_json' => 'array'];
    }

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(KeywordCluster::class, 'cluster_id');
    }
}
