<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeSite extends Model
{
    protected $fillable = [
        'brand_id',
        'key',
        'canonical_host',
        'role',
    ];

    protected function casts(): array
    {
        return [
            'brand_id' => 'integer',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBrand::class, 'brand_id');
    }
}
