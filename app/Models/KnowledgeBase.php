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
}
