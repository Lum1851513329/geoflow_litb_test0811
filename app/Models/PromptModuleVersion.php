<?php

namespace App\Models;

use App\Domain\PromptComposition\PromptModuleSnapshotIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

final class PromptModuleVersion extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_TEST = 'test';

    public const STATUS_CANARY = 'canary';

    public const STATUS_PRODUCTION = 'production';

    public const STATUS_DEPRECATED = 'deprecated';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'lifecycle_status', 'change_note', 'published_at',
    ];

    protected static function booted(): void
    {
        self::creating(function (PromptModuleVersion $version): void {
            $version->content_sha256 = hash('sha256', (string) $version->content);
            if (Schema::hasColumn($version->getTable(), 'snapshot_sha256')) {
                $version->snapshot_sha256 = PromptModuleSnapshotIdentity::digest(
                    (string) $version->content,
                    $version->variable_contract,
                    $version->output_contract,
                    $version->parameter_defaults,
                );
            }
        });
    }

    protected function casts(): array
    {
        return [
            'prompt_module_id' => 'integer',
            'version' => 'integer',
            'variable_contract' => 'array',
            'output_contract' => 'array',
            'parameter_defaults' => 'array',
            'created_by_admin_id' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(PromptModule::class, 'prompt_module_id');
    }

    public function presetBindings(): HasMany
    {
        return $this->hasMany(PromptPresetModuleVersion::class, 'prompt_module_version_id');
    }
}
