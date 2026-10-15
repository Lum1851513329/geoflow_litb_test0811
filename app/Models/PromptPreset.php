<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PromptPreset extends Model
{
    protected $fillable = [
        'preset_key', 'display_name', 'description', 'is_system', 'is_enabled',
        'owned_by_admin_id', 'current_version_id',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_enabled' => 'boolean',
            'owned_by_admin_id' => 'integer',
            'current_version_id' => 'integer',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PromptPresetVersion::class, 'prompt_preset_id');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(PromptPresetVersion::class, 'current_version_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'owned_by_admin_id');
    }
}
