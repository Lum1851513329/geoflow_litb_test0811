<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ContentGenerationRunModule extends Model
{
    protected $fillable = [
        'content_generation_run_id', 'prompt_module_version_id', 'sort_order',
        'is_required', 'rendered_content', 'module_snapshot', 'variables_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'content_generation_run_id' => 'integer',
            'prompt_module_version_id' => 'integer',
            'sort_order' => 'integer',
            'is_required' => 'boolean',
            'module_snapshot' => 'array',
            'variables_snapshot' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ContentGenerationRun::class, 'content_generation_run_id');
    }

    public function promptModuleVersion(): BelongsTo
    {
        return $this->belongsTo(PromptModuleVersion::class, 'prompt_module_version_id');
    }
}
