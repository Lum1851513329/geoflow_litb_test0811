<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PromptModule extends Model
{
    public const TYPE_FOUNDATION = 'foundation';

    public const TYPE_GEO = 'geo';

    public const TYPE_SEO = 'seo';

    public const TYPE_CONTENT_TYPE = 'content_type';

    public const TYPE_PLATFORM = 'platform';

    public const TYPE_LANGUAGE_MARKET = 'language_market';

    public const TYPE_KNOWLEDGE_POLICY = 'knowledge_policy';

    public const TYPE_OUTPUT_FORMAT = 'output_format';

    public const TYPE_QUALITY_CHECK = 'quality_check';

    protected $fillable = [
        'module_key', 'display_name', 'module_type', 'description', 'is_system',
        'applicability', 'is_mandatory', 'default_priority', 'is_enabled',
        'owned_by_admin_id', 'current_version_id',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'applicability' => 'array',
            'is_mandatory' => 'boolean',
            'default_priority' => 'integer',
            'is_enabled' => 'boolean',
            'owned_by_admin_id' => 'integer',
            'current_version_id' => 'integer',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PromptModuleVersion::class, 'prompt_module_id');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(PromptModuleVersion::class, 'current_version_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'owned_by_admin_id');
    }
}
