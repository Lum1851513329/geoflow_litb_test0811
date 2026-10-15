<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class GenerationEvidencePreparation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'content_generation_run_id' => 'integer',
            'input_json' => 'array',
            'external_evidence' => 'array',
            'attempt_log' => 'array',
            'result_json' => 'array',
            'lease_version' => 'integer',
            'pages_fetched' => 'integer',
            'search_rounds' => 'integer',
            'deadline_at' => 'datetime',
            'sealed_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ContentGenerationRun::class, 'content_generation_run_id');
    }
}
