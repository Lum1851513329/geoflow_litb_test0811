<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeBrand extends Model
{
    protected $fillable = [
        'key',
        'name',
    ];

    public function sites(): HasMany
    {
        return $this->hasMany(KnowledgeSite::class, 'brand_id');
    }
}
