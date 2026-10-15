<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PromptPresetModuleVersion extends Model
{
    protected $fillable = [
        'prompt_preset_version_id', 'prompt_module_version_id', 'sort_order',
        'is_required', 'selection_source', 'configuration',
    ];

    protected function casts(): array
    {
        return [
            'prompt_preset_version_id' => 'integer',
            'prompt_module_version_id' => 'integer',
            'sort_order' => 'integer',
            'is_required' => 'boolean',
            'configuration' => 'array',
        ];
    }

    public function presetVersion(): BelongsTo
    {
        return $this->belongsTo(PromptPresetVersion::class, 'prompt_preset_version_id');
    }

    public function promptModuleVersion(): BelongsTo
    {
        return $this->belongsTo(PromptModuleVersion::class, 'prompt_module_version_id');
    }
}
