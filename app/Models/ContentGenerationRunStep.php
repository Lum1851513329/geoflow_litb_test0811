<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ContentGenerationRunStep extends Model
{
    public const TYPE_INITIAL_GENERATION = 'initial_generation';

    public const TYPE_DETERMINISTIC_QUALITY = 'deterministic_quality';

    public const TYPE_SEMANTIC_QUALITY = 'semantic_quality';

    public const TYPE_REVISION = 'revision';

    public const TYPE_TARGETED_REVISION = 'targeted_revision';

    public const TYPE_ADOPTION = 'adoption';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'content_generation_run_id', 'sequence', 'step_type', 'status', 'prompt', 'output',
        'input_snapshot', 'metadata', 'input_tokens', 'output_tokens', 'cost',
        'error_message', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'content_generation_run_id' => 'integer',
            'sequence' => 'integer',
            'input_snapshot' => 'array',
            'metadata' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost' => 'decimal:6',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ContentGenerationRun::class, 'content_generation_run_id');
    }

    public function qualityReports(): HasMany
    {
        return $this->hasMany(ContentQualityReport::class, 'content_generation_run_step_id');
    }
}
