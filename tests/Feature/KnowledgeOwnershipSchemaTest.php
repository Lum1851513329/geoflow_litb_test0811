<?php

namespace Tests\Feature;

use App\Models\KnowledgeBase;
use App\Models\KnowledgeBrand;
use App\Models\KnowledgeDirectory;
use App\Models\KnowledgeSite;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KnowledgeOwnershipSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_lightinthebox_document_has_a_brand_site_and_primary_directory(): void
    {
        $brandId = DB::table('knowledge_brands')->insertGetId([
            'key' => 'lightinthebox',
            'name' => 'LightInTheBox',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $siteId = DB::table('knowledge_sites')->insertGetId([
            'brand_id' => $brandId,
            'key' => 'lightinthebox-main',
            'canonical_host' => 'www.lightinthebox.com',
            'role' => 'commerce-primary',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $directoryId = DB::table('knowledge_directories')->insertGetId([
            'brand_id' => $brandId,
            'key' => 'brand-company',
            'path' => 'brand-company',
            'name' => 'Brand company',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $document = KnowledgeBase::query()->create([
            'name' => 'LightInTheBox company profile',
            'content' => 'LightInTheBox company information.',
            'brand_id' => $brandId,
            'site_id' => $siteId,
            'primary_directory_id' => $directoryId,
        ]);

        $this->assertSame('lightinthebox', $document->brand->key);
        $this->assertSame('lightinthebox-main', $document->site->key);
        $this->assertSame('brand-company', $document->primaryDirectory->path);
        $this->assertSame($siteId, KnowledgeBrand::query()->findOrFail($brandId)->sites()->value('id'));
        $this->assertSame($brandId, KnowledgeSite::query()->findOrFail($siteId)->brand->id);
        $this->assertSame($brandId, KnowledgeDirectory::query()->findOrFail($directoryId)->brand->id);
    }

    public function test_document_cannot_reference_a_site_from_another_brand(): void
    {
        [$lightInTheBoxBrandId, $otherBrandId, $otherSiteId, $lightInTheBoxDirectoryId] = $this->ownershipIds();

        $this->expectException(QueryException::class);

        KnowledgeBase::query()->create([
            'name' => 'Cross-brand site',
            'content' => 'This document must not be saved.',
            'brand_id' => $lightInTheBoxBrandId,
            'site_id' => $otherSiteId,
            'primary_directory_id' => $lightInTheBoxDirectoryId,
        ]);
    }

    public function test_document_cannot_reference_a_directory_from_another_brand(): void
    {
        [$lightInTheBoxBrandId, $otherBrandId, $otherSiteId, $lightInTheBoxDirectoryId] = $this->ownershipIds();
        $lightInTheBoxSiteId = (int) DB::table('knowledge_sites')
            ->where('brand_id', $lightInTheBoxBrandId)
            ->value('id');
        $otherDirectoryId = (int) DB::table('knowledge_directories')
            ->where('brand_id', $otherBrandId)
            ->value('id');

        $this->expectException(QueryException::class);

        KnowledgeBase::query()->create([
            'name' => 'Cross-brand directory',
            'content' => 'This document must not be saved.',
            'brand_id' => $lightInTheBoxBrandId,
            'site_id' => $lightInTheBoxSiteId,
            'primary_directory_id' => $otherDirectoryId,
        ]);
    }

    public function test_legacy_document_can_omit_all_ownership_references(): void
    {
        $document = KnowledgeBase::query()->create([
            'name' => 'Legacy knowledge document',
            'content' => 'Legacy documents remain supported until migrated.',
        ]);

        $this->assertNull($document->brand_id);
        $this->assertNull($document->site_id);
        $this->assertNull($document->primary_directory_id);
    }

    #[DataProvider('partialOwnershipShapes')]
    public function test_document_cannot_have_partial_ownership(
        bool $includeBrand,
        bool $includeSite,
        bool $includeDirectory,
    ): void {
        [$brandId, , , $directoryId] = $this->ownershipIds();
        $siteId = (int) DB::table('knowledge_sites')
            ->where('brand_id', $brandId)
            ->value('id');

        $this->expectException(QueryException::class);

        KnowledgeBase::query()->create([
            'name' => 'Partial ownership is invalid',
            'content' => 'Every ownership reference must be absent or present together.',
            'brand_id' => $includeBrand ? $brandId : null,
            'site_id' => $includeSite ? $siteId : null,
            'primary_directory_id' => $includeDirectory ? $directoryId : null,
        ]);
    }

    /**
     * @return array<string, array{bool, bool, bool}>
     */
    public static function partialOwnershipShapes(): array
    {
        return [
            'site only' => [false, true, false],
            'directory only' => [false, false, true],
            'site and directory without brand' => [false, true, true],
            'brand only' => [true, false, false],
            'brand and site without directory' => [true, true, false],
            'brand and directory without site' => [true, false, true],
        ];
    }

    /**
     * @return array{int, int, int, int}
     */
    private function ownershipIds(): array
    {
        $now = now();
        $lightInTheBoxBrandId = DB::table('knowledge_brands')->insertGetId([
            'key' => 'lightinthebox',
            'name' => 'LightInTheBox',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $otherBrandId = DB::table('knowledge_brands')->insertGetId([
            'key' => 'other-brand',
            'name' => 'Other Brand',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $otherSiteId = DB::table('knowledge_sites')->insertGetId([
            'brand_id' => $otherBrandId,
            'key' => 'other-main',
            'canonical_host' => 'www.other.test',
            'role' => 'commerce-primary',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $lightInTheBoxDirectoryId = DB::table('knowledge_directories')->insertGetId([
            'brand_id' => $lightInTheBoxBrandId,
            'key' => 'brand-company',
            'path' => 'brand-company',
            'name' => 'Brand company',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('knowledge_sites')->insert([
            'brand_id' => $lightInTheBoxBrandId,
            'key' => 'lightinthebox-main',
            'canonical_host' => 'www.lightinthebox.com',
            'role' => 'commerce-primary',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('knowledge_directories')->insert([
            'brand_id' => $otherBrandId,
            'key' => 'brand-company',
            'path' => 'brand-company',
            'name' => 'Brand company',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$lightInTheBoxBrandId, $otherBrandId, $otherSiteId, $lightInTheBoxDirectoryId];
    }
}
