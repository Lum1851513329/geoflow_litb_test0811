<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ContentStrategyVersion extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'content_brief_id', 'version', 'strategy', 'suggestion_snapshot', 'lifecycle_status',
        'change_note', 'created_by_admin_id', 'confirmed_by_admin_id', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'content_brief_id' => 'integer',
            'version' => 'integer',
            'strategy' => 'array',
            'suggestion_snapshot' => 'array',
            'created_by_admin_id' => 'integer',
            'confirmed_by_admin_id' => 'integer',
            'confirmed_at' => 'datetime',
        ];
    }

    public function contentBrief(): BelongsTo
    {
        return $this->belongsTo(ContentBrief::class, 'content_brief_id');
    }

    public function blueprintVersions(): HasMany
    {
        return $this->hasMany(ContentBlueprintVersion::class, 'content_strategy_version_id');
    }
}
