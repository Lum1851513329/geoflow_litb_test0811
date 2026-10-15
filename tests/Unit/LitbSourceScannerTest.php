<?php

namespace Tests\Unit;

use App\Services\GeoFlow\LitbAssets\LitbSourceScanner;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class LitbSourceScannerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/litb-source-scanner-'.uniqid());
        File::ensureDirectoryExists($this->root.'/LightInTheBox_PT_Official_KB/02_Customer_Service');
        File::ensureDirectoryExists($this->root.'/officialknowege/LITB_Reports');
        File::ensureDirectoryExists($this->root.'/LightInTheBox_PT_Keyword_Library');
        File::put($this->root.'/LightInTheBox_PT_Official_KB/02_Customer_Service/2.8_Payment Methods.md', "# Payment Methods\nUse the methods shown at checkout.");
        File::put($this->root.'/officialknowege/LITB_Reports/02_财务数据全面分析.md', "# 财务分析\n研究材料。");
        File::put($this->root.'/LightInTheBox_PT_Keyword_Library/keywords.txt', 'wedding dresses');
        File::put($this->root.'/.DS_Store', 'ignored');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_it_discovers_supported_sources_with_stable_relative_metadata(): void
    {
        $records = (new LitbSourceScanner())->scanKnowledge($this->root);

        $this->assertCount(2, $records);
        $payment = collect($records)->firstWhere('title', 'Payment Methods');
        $this->assertNotNull($payment);
        $this->assertSame('customer-service-policies', $payment->groupKey);
        $this->assertSame('official', $payment->sourceType);
        $this->assertSame('en', $payment->languageCode);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $payment->sha256);
        $this->assertStringNotContainsString($this->root, $payment->relativePath);

        $report = collect($records)->firstWhere('sourceType', 'research');
        $this->assertSame('marketing-research-reference', $report->groupKey);
        $this->assertSame('high', $report->riskLevel);
        $this->assertTrue($report->timeSensitive);
    }
}
