<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ContentGenerationRun extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_COMPILING = 'compiling';

    public const STATUS_GENERATING = 'generating';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_READY_FOR_REVIEW = 'ready_for_review';

    public const STATUS_NEEDS_EVIDENCE = 'needs_evidence';

    public const STATUS_MODEL_QUALITY_FAILED = 'model_quality_failed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_NEEDS_REVISION = 'needs_revision';

    public const STATUS_MANUAL_REVIEW_REQUIRED = 'manual_review_required';

    public const STATUS_ADOPTED = 'adopted';

    protected $fillable = [
        'article_id', 'raw_output', 'adopted_output', 'status', 'error_message',
        'adopted_by_admin_id', 'started_at', 'completed_at', 'adopted_at',
    ];

    protected function casts(): array
    {
        return [
            'content_brief_id' => 'integer',
            'strategy_version_id' => 'integer',
            'blueprint_version_id' => 'integer',
            'preset_version_id' => 'integer',
            'ai_model_id' => 'integer',
            'article_id' => 'integer',
            'variables_snapshot' => 'array',
            'model_snapshot' => 'array',
            'generation_parameters' => 'array',
            'created_by_admin_id' => 'integer',
            'lease_version' => 'integer',
            'adopted_by_admin_id' => 'integer',
            'lease_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'adopted_at' => 'datetime',
        ];
    }

    public function contentBrief(): BelongsTo
    {
        return $this->belongsTo(ContentBrief::class, 'content_brief_id');
    }

    public function strategyVersion(): BelongsTo
    {
        return $this->belongsTo(ContentStrategyVersion::class, 'strategy_version_id');
    }

    public function blueprintVersion(): BelongsTo
    {
        return $this->belongsTo(ContentBlueprintVersion::class, 'blueprint_version_id');
    }

    public function presetVersion(): BelongsTo
    {
        return $this->belongsTo(PromptPresetVersion::class, 'preset_version_id');
    }

    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class, 'article_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ContentGenerationRunStep::class, 'content_generation_run_id')
            ->orderBy('sequence');
    }

    public function modules(): HasMany
    {
        return $this->hasMany(ContentGenerationRunModule::class, 'content_generation_run_id')
            ->orderBy('sort_order');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(ContentGenerationRunEvidence::class, 'content_generation_run_id');
    }

    public function qualityReports(): HasMany
    {
        return $this->hasMany(ContentQualityReport::class, 'content_generation_run_id');
    }
}
