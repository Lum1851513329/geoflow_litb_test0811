<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KeywordTaxonomy extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'status'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(KeywordNode::class, 'taxonomy_id');
    }

    public function rootNodes(): HasMany
    {
        return $this->hasMany(KeywordNode::class, 'taxonomy_id')->whereNull('parent_id')->orderBy('sort_order');
    }
}
