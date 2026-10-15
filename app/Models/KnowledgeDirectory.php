<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeDirectory extends Model
{
    protected $fillable = [
        'brand_id',
        'parent_id',
        'key',
        'path',
        'name',
    ];

    protected function casts(): array
    {
        return [
            'brand_id' => 'integer',
            'parent_id' => 'integer',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBrand::class, 'brand_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
