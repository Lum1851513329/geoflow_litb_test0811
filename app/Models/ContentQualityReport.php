<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ContentQualityReport extends Model
{
    public const STATUS_PASSED = 'passed';

    public const STATUS_NEEDS_REVISION = 'needs_revision';

    public const STATUS_NEEDS_EVIDENCE = 'needs_evidence';

    public const STATUS_MODEL_QUALITY_FAILED = 'model_quality_failed';

    public const STATUS_MANUAL_REVIEW_REQUIRED = 'manual_review_required';

    protected $fillable = [
        'content_generation_run_id', 'content_generation_run_step_id', 'report_type', 'status',
        'passed', 'score', 'deterministic_results', 'semantic_results', 'issues',
        'revision_instructions',
    ];

    protected function casts(): array
    {
        return [
            'content_generation_run_id' => 'integer',
            'content_generation_run_step_id' => 'integer',
            'passed' => 'boolean',
            'score' => 'decimal:2',
            'deterministic_results' => 'array',
            'semantic_results' => 'array',
            'issues' => 'array',
            'revision_instructions' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ContentGenerationRun::class, 'content_generation_run_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(ContentGenerationRunStep::class, 'content_generation_run_step_id');
    }
}
