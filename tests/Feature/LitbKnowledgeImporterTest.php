<?php

namespace Tests\Feature;

use App\Models\KnowledgeBase;
use App\Services\GeoFlow\LitbAssets\LitbKnowledgeImporter;
use App\Services\GeoFlow\LitbAssets\LitbSourceScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class LitbKnowledgeImporterTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/litb-knowledge-import-'.uniqid());
        File::ensureDirectoryExists($this->root.'/LightInTheBox_PT_Official_KB/02_Customer_Service');
        File::put($this->root.'/LightInTheBox_PT_Official_KB/02_Customer_Service/Payment Methods.md', "# Payment Methods\nUse checkout options.");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_it_imports_once_and_skips_the_same_source_hash(): void
    {
        $records = (new LitbSourceScanner())->scanKnowledge($this->root);
        $importer = $this->app->make(LitbKnowledgeImporter::class);

        $first = $importer->import($records, false);
        $second = $importer->import($records, false);

        $this->assertSame(1, KnowledgeBase::query()->where('asset_key', $records[0]->sourceKey)->count());
        $this->assertSame(1, $first->createdCount());
        $this->assertSame(1, $second->skippedCount());
    }

    public function test_it_reports_an_operator_owned_name_collision_without_overwriting(): void
    {
        KnowledgeBase::query()->create([
            'name' => 'Payment Methods',
            'content' => 'Operator-owned content.',
            'file_type' => 'markdown',
        ]);
        $records = (new LitbSourceScanner())->scanKnowledge($this->root);

        $manifest = $this->app->make(LitbKnowledgeImporter::class)->import($records, false);

        $this->assertSame(1, $manifest->conflictCount());
        $this->assertSame('Operator-owned content.', KnowledgeBase::query()->sole()->content);
        $this->assertNull(KnowledgeBase::query()->sole()->asset_key);
    }
}
