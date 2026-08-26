<?php

namespace App\Http\Controllers\Admin;

use App\Models\KnowledgeBrand;
use App\Models\KnowledgeDirectory;
use App\Models\KnowledgeSite;

final class KnowledgeFormOptions
{
    public static function load(): array
    {
        return [
            'brands' => KnowledgeBrand::orderBy('name')->get(['id', 'name']),
            'sites' => KnowledgeSite::orderBy('canonical_host')->get(['id', 'brand_id', 'canonical_host']),
            'directories' => KnowledgeDirectory::orderBy('path')->get(['id', 'brand_id', 'path', 'name']),
        ];
    }
}
