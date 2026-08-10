<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ContentBlueprintVersion extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'lifecycle_status', 'change_note', 'confirmed_by_admin_id', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'content_brief_id' => 'integer',
            'content_strategy_version_id' => 'integer',
            'version' => 'integer',
            'blueprint' => 'array',
            'validation_report' => 'array',
            'created_by_admin_id' => 'integer',
            'confirmed_by_admin_id' => 'integer',
            'confirmed_at' => 'datetime',
        ];
    }

    public function contentBrief(): BelongsTo
    {
        return $this->belongsTo(ContentBrief::class, 'content_brief_id');
    }

    public function strategyVersion(): BelongsTo
    {
        return $this->belongsTo(ContentStrategyVersion::class, 'content_strategy_version_id');
    }
}
