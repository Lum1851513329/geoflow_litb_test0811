<?php

namespace Tests\Feature;

use App\Models\KnowledgeBase;
use App\Services\GeoFlow\KnowledgeChunkSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class KnowledgeChunkGovernanceMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_propagates_governed_document_metadata_to_each_chunk(): void
    {
        Http::fake();
        $now = now();
        $brandId = DB::table('knowledge_brands')->insertGetId([
            'key' => 'lightinthebox', 'name' => 'LightInTheBox', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $siteId = DB::table('knowledge_sites')->insertGetId([
            'brand_id' => $brandId, 'key' => 'lightinthebox-main', 'canonical_host' => 'www.lightinthebox.com',
            'role' => 'commerce-primary', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $directoryId = DB::table('knowledge_directories')->insertGetId([
            'brand_id' => $brandId, 'key' => 'policy-compliance', 'path' => 'policy-compliance',
            'name' => 'Policy compliance', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => 'Returns', 'description' => '', 'content' => '', 'character_count' => 0,
            'file_type' => 'markdown', 'word_count' => 0, 'brand_id' => $brandId, 'site_id' => $siteId,
            'primary_directory_id' => $directoryId, 'language_code' => 'en', 'authority_level' => 'first_party',
            'lifecycle_status' => 'active', 'source_url' => 'https://www.lightinthebox.com/returns',
            'effective_date' => '2026-07-01', 'retrieved_at' => '2026-08-01 00:00:00',
        ]);

        app(KnowledgeChunkSyncService::class)->sync((int) $knowledgeBase->id, "# Returns\n\nReturns are accepted within 30 days.");

        $metadata = json_decode((string) $knowledgeBase->chunks()->firstOrFail()->metadata_json, true);

        $this->assertSame('lightinthebox', $metadata['brand_key'] ?? null);
        $this->assertSame('lightinthebox-main', $metadata['site_key'] ?? null);
        $this->assertSame('en', $metadata['language_code'] ?? null);
        $this->assertSame('first_party', $metadata['authority_level'] ?? null);
        $this->assertSame('active', $metadata['lifecycle_status'] ?? null);
        $this->assertSame('https://www.lightinthebox.com/returns', $metadata['source_url'] ?? null);
        $this->assertSame('2026-07-01', $metadata['effective_date'] ?? null);
        $this->assertStringStartsWith('2026-08-01T00:00:00', (string) ($metadata['retrieved_at'] ?? ''));
    }
}
