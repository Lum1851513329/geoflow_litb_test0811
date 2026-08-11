<?php

namespace Tests\Unit;

use App\Services\GeoFlow\LitbAssets\LitbAssetConfig;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class LitbAssetConfigTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureRoot = storage_path('framework/testing/litb-assets-'.uniqid());
        File::makeDirectory($this->fixtureRoot.'/knowledge', 0755, true);
        File::makeDirectory($this->fixtureRoot.'/keywords', 0755, true);
        config()->set('litb_content_assets', [
            'knowledge_root' => $this->fixtureRoot.'/knowledge',
            'keyword_root' => $this->fixtureRoot.'/keywords',
            'quarter_key' => 'litb-quarterly-2026-q3',
            'archive_root' => 'content/litb-quarterly-2026-q3',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->fixtureRoot);
        parent::tearDown();
    }

    public function test_it_resolves_validated_asset_paths_and_identity(): void
    {
        $config = LitbAssetConfig::fromConfig();

        $this->assertSame(realpath($this->fixtureRoot.'/knowledge'), $config->knowledgeRoot());
        $this->assertSame(realpath($this->fixtureRoot.'/keywords'), $config->keywordRoot());
        $this->assertSame('litb-quarterly-2026-q3', $config->quarterKey());
        $this->assertSame(base_path('content/litb-quarterly-2026-q3'), $config->archiveRoot());
    }
}
