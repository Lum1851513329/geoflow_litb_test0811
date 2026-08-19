<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeBase extends Model
{
    protected $table = 'knowledge_bases';

    protected $attributes = [
        'chunk_sync_status' => 'idle',
        'chunk_source_hash' => '',
        'chunk_sync_require_real_embedding' => false,
    ];

    protected $fillable = [
        'name',
        'description',
        'content',
        'character_count',
        'used_task_count',
        'file_type',
        'file_path',
        'word_count',
        'usage_count',
        'source_name',
        'source_url',
        'source_type',
        'business_line',
        'effective_date',
        'risk_level',
        'review_status',
        'chunk_sync_status',
        'chunk_sync_token',
        'chunk_source_hash',
        'chunk_sync_error',
        'chunk_sync_require_real_embedding',
        'chunk_synced_at',
        'asset_key',
        'asset_provenance',
        'brand_id',
        'site_id',
        'primary_directory_id',
        'canonical_document_key',
        'language_code',
        'authority_level',
        'retrieved_at',
        'review_due_at',
        'version_label',
        'lifecycle_status',
        'generation_eligible',
        'citation_required',
        'normalized_content_hash',
        'source_file_path',
        'source_file_hash',
    ];

    protected function casts(): array
    {
        return [
            'character_count' => 'integer',
            'used_task_count' => 'integer',
            'word_count' => 'integer',
            'usage_count' => 'integer',
            'effective_date' => 'date',
            'chunk_sync_require_real_embedding' => 'boolean',
            'chunk_synced_at' => 'datetime',
            'asset_provenance' => 'array',
            'brand_id' => 'integer',
            'site_id' => 'integer',
            'primary_directory_id' => 'integer',
            'retrieved_at' => 'datetime',
            'review_due_at' => 'date',
            'generation_eligible' => 'boolean',
            'citation_required' => 'boolean',
        ];
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class, 'knowledge_base_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBrand::class, 'brand_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSite::class, 'site_id');
    }

    public function primaryDirectory(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDirectory::class, 'primary_directory_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'knowledge_base_id');
    }

    public function linkedTasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_knowledge_bases')
            ->withPivot(['sort_order'])
            ->withTimestamps()
            ->orderByPivot('sort_order')
            ->orderBy('tasks.id');
    }

    public function canonicalImportRows(): HasMany
    {
        return $this->hasMany(KnowledgeImportRow::class, 'canonical_document_id');
    }

    public function duplicateAuditRows(): HasMany
    {
        return $this->hasMany(KnowledgeDuplicate::class, 'duplicate_document_id');
    }

    public function canonicalDuplicateAuditRows(): HasMany
    {
        return $this->hasMany(KnowledgeDuplicate::class, 'canonical_document_id');
    }

    public function keywordNodes(): BelongsToMany
    {
        return $this->belongsToMany(KeywordNode::class, 'knowledge_keyword_nodes', 'knowledge_base_id', 'keyword_node_id')
            ->using(KnowledgeKeywordNode::class)
            ->withPivot(['confidence', 'source', 'is_primary'])
            ->withTimestamps();
    }

    public function keywordClusters(): BelongsToMany
    {
        return $this->belongsToMany(KeywordCluster::class, 'knowledge_keyword_clusters', 'knowledge_base_id', 'keyword_cluster_id')
            ->using(KnowledgeKeywordCluster::class)
            ->withPivot(['confidence', 'source', 'is_primary'])
            ->withTimestamps();
    }
}
