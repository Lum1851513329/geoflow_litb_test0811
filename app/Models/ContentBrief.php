<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ContentBrief extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_READY = 'ready';

    public const STATUS_STRATEGY_READY = 'strategy_ready';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'knowledge_mode', 'knowledge_brand_id', 'knowledge_site_id', 'knowledge_directory_id',
        'external_evidence_policy', 'external_general_domains',
        'topic', 'market_code', 'language_code', 'audience', 'core_question', 'business_goal',
        'content_type', 'planned_publish_at', 'keyword_cluster_id', 'primary_keyword_id',
        'category_id', 'target_url', 'required_mentions', 'prohibited_information',
        'campaign_window', 'reference_urls', 'cta_strength', 'status', 'created_by_admin_id',
        'asset_key', 'asset_provenance',
    ];

    protected function casts(): array
    {
        return [
            'knowledge_brand_id' => 'integer',
            'knowledge_site_id' => 'integer',
            'knowledge_directory_id' => 'integer',
            'external_general_domains' => 'array',
            'planned_publish_at' => 'datetime',
            'keyword_cluster_id' => 'integer',
            'primary_keyword_id' => 'integer',
            'category_id' => 'integer',
            'required_mentions' => 'array',
            'prohibited_information' => 'array',
            'campaign_window' => 'array',
            'reference_urls' => 'array',
            'created_by_admin_id' => 'integer',
            'asset_provenance' => 'array',
            'task_id' => 'integer',
        ];
    }

    public function strategyVersions(): HasMany
    {
        return $this->hasMany(ContentStrategyVersion::class, 'content_brief_id');
    }

    public function blueprintVersions(): HasMany
    {
        return $this->hasMany(ContentBlueprintVersion::class, 'content_brief_id');
    }

    public function generationRuns(): HasMany
    {
        return $this->hasMany(ContentGenerationRun::class, 'content_brief_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function keywordCluster(): BelongsTo
    {
        return $this->belongsTo(KeywordCluster::class, 'keyword_cluster_id');
    }

    public function primaryKeyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class, 'primary_keyword_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }
}
