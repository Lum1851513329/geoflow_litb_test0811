<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KeywordCluster extends Model
{
    protected $fillable = ['name', 'slug', 'taxonomy_node_id', 'primary_keyword_id', 'primary_scope_key', 'language_code', 'market_code', 'market_scope', 'search_intent', 'content_type', 'status', 'coverage_status', 'knowledge_base_id', 'category_id', 'target_url', 'confirmed_by', 'confirmed_at', 'latest_opportunity_score'];

    protected function casts(): array
    {
        return ['taxonomy_node_id' => 'integer', 'primary_keyword_id' => 'integer', 'knowledge_base_id' => 'integer', 'category_id' => 'integer', 'confirmed_by' => 'integer', 'confirmed_at' => 'datetime', 'latest_opportunity_score' => 'decimal:2'];
    }

    public function taxonomyNode(): BelongsTo
    {
        return $this->belongsTo(KeywordNode::class, 'taxonomy_node_id');
    }

    public function primaryKeyword(): BelongsTo
    {
        return $this->belongsTo(Keyword::class, 'primary_keyword_id');
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class, 'knowledge_base_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'confirmed_by');
    }

    public function keywords(): BelongsToMany
    {
        return $this->belongsToMany(Keyword::class, 'keyword_cluster_keywords', 'cluster_id', 'keyword_id')
            ->using(KeywordClusterKeyword::class)
            ->withPivot(['role', 'weight', 'sort_order'])
            ->withTimestamps();
    }

    public function opportunityScores(): HasMany
    {
        return $this->hasMany(KeywordOpportunityScore::class, 'cluster_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(KeywordClusterKeyword::class, 'cluster_id');
    }

    public function knowledgeBases(): BelongsToMany
    {
        return $this->belongsToMany(KnowledgeBase::class, 'knowledge_keyword_clusters', 'keyword_cluster_id', 'knowledge_base_id')
            ->using(KnowledgeKeywordCluster::class)
            ->withPivot(['confidence', 'source', 'is_primary'])
            ->withTimestamps();
    }
}
