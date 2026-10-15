<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KeywordNode extends Model
{
    protected $fillable = ['taxonomy_id', 'parent_id', 'name', 'slug', 'path', 'depth', 'sort_order'];

    protected function casts(): array
    {
        return ['taxonomy_id' => 'integer', 'parent_id' => 'integer', 'depth' => 'integer', 'sort_order' => 'integer'];
    }

    public function taxonomy(): BelongsTo
    {
        return $this->belongsTo(KeywordTaxonomy::class, 'taxonomy_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function keywords(): BelongsToMany
    {
        return $this->belongsToMany(Keyword::class, 'keyword_node_keyword', 'node_id', 'keyword_id')
            ->using(KeywordNodeKeyword::class)
            ->withPivot(['is_primary', 'confidence', 'source'])
            ->withTimestamps();
    }

    public function clusters(): HasMany
    {
        return $this->hasMany(KeywordCluster::class, 'taxonomy_node_id');
    }

    public function knowledgeBases(): BelongsToMany
    {
        return $this->belongsToMany(KnowledgeBase::class, 'knowledge_keyword_nodes', 'keyword_node_id', 'knowledge_base_id')
            ->using(KnowledgeKeywordNode::class)
            ->withPivot(['confidence', 'source', 'is_primary'])
            ->withTimestamps();
    }
}
