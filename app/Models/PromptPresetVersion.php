<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PromptPresetVersion extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_TEST = 'test';

    public const STATUS_CANARY = 'canary';

    public const STATUS_PRODUCTION = 'production';

    public const STATUS_DEPRECATED = 'deprecated';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'prompt_preset_id', 'version', 'matching_rules', 'generation_policy',
        'conflict_policy', 'lifecycle_status', 'change_note', 'created_by_admin_id',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'prompt_preset_id' => 'integer',
            'version' => 'integer',
            'matching_rules' => 'array',
            'generation_policy' => 'array',
            'conflict_policy' => 'array',
            'created_by_admin_id' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function preset(): BelongsTo
    {
        return $this->belongsTo(PromptPreset::class, 'prompt_preset_id');
    }

    public function moduleVersions(): HasMany
    {
        return $this->hasMany(PromptPresetModuleVersion::class, 'prompt_preset_version_id')
            ->orderBy('sort_order');
    }
}
