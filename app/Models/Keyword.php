<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Keyword extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'keywords';

    protected $fillable = [
        'library_id',
        'keyword',
        'used_count',
        'usage_count',
        'normalized_keyword',
        'language_code',
        'market_code',
        'market_scope',
        'keyword_type',
        'search_intent',
        'brand_relation',
        'status',
        'canonical_keyword_id',
        'latest_opportunity_score',
    ];

    protected function casts(): array
    {
        return [
            'library_id' => 'integer',
            'used_count' => 'integer',
            'usage_count' => 'integer',
            'canonical_keyword_id' => 'integer',
            'latest_opportunity_score' => 'decimal:2',
        ];
    }

    public function library(): BelongsTo
    {
        return $this->belongsTo(KeywordLibrary::class, 'library_id');
    }

    public function canonicalKeyword(): BelongsTo
    {
        return $this->belongsTo(self::class, 'canonical_keyword_id');
    }

    public function canonicalVariants(): HasMany
    {
        return $this->hasMany(self::class, 'canonical_keyword_id');
    }

    public function taxonomyNodes(): BelongsToMany
    {
        return $this->belongsToMany(KeywordNode::class, 'keyword_node_keyword', 'keyword_id', 'node_id')
            ->using(KeywordNodeKeyword::class)
            ->withPivot(['is_primary', 'confidence', 'source'])
            ->withTimestamps();
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(KeywordMetric::class, 'keyword_id');
    }

    public function importRows(): HasMany
    {
        return $this->hasMany(KeywordImportRow::class, 'keyword_id');
    }

    public function outgoingRelations(): HasMany
    {
        return $this->hasMany(KeywordRelation::class, 'source_keyword_id');
    }

    public function incomingRelations(): HasMany
    {
        return $this->hasMany(KeywordRelation::class, 'target_keyword_id');
    }

    public function clusters(): BelongsToMany
    {
        return $this->belongsToMany(KeywordCluster::class, 'keyword_cluster_keywords', 'keyword_id', 'cluster_id')
            ->using(KeywordClusterKeyword::class)
            ->withPivot(['role', 'weight', 'sort_order'])
            ->withTimestamps();
    }

    public function primaryClusters(): HasMany
    {
        return $this->hasMany(KeywordCluster::class, 'primary_keyword_id');
    }
}
