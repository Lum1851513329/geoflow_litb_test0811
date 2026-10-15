<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class LitbContentAssetSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_governed_asset_tables_have_stable_keys_and_provenance(): void
    {
        foreach (['knowledge_bases', 'keyword_libraries', 'content_briefs'] as $table) {
            $this->assertTrue(Schema::hasColumns($table, ['asset_key', 'asset_provenance']), $table);
        }
    }
}
