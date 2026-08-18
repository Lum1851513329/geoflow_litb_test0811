<?php

namespace Tests\Unit;

use App\Domain\ContentGeneration\BulkContentImportParser;
use App\Domain\ContentGeneration\BulkContentImportRow;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\KnowledgeBase;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

final class BulkContentImportParserTest extends TestCase
{
    use RefreshDatabase;

    public function test_parses_valid_rows_and_resolves_site_preset_category_knowledge_base(): void
    {
        $channel = DistributionChannel::query()->create([
            'name' => 'LITB Style Blog', 'domain' => 'blog.lightinthebox.com',
            'endpoint_url' => 'https://blog.lightinthebox.com', 'channel_type' => 'wordpress', 'status' => 'active',
        ]);
        $preset = PromptPreset::query()->create(['preset_key' => 'litb.geo_category_authority.en', 'display_name' => 'Category Authority Guide', 'is_system' => true, 'is_enabled' => true]);
        $version = PromptPresetVersion::query()->forceCreate([
            'prompt_preset_id' => $preset->id, 'version' => 1, 'matching_rules' => [], 'generation_policy' => [],
            'blueprint_template' => ['target_length' => 1800, 'sections' => []],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
        ]);
        $preset->forceFill(['current_version_id' => $version->id])->save();
        $category = Category::query()->create(['name' => 'Style & Buying Guides', 'slug' => 'style-and-buying-guides']);
        $knowledgeBase = KnowledgeBase::query()->create(['name' => 'LITB Product KB', 'content' => 'x', 'review_status' => 'reviewed']);

        $path = $this->buildFixture([
            ['site', 'preset', 'category', 'topic', 'knowledge_base', 'core_question', 'primary_keyword', 'reference_urls'],
            ['【必填】...', '【必填】...', '【必填】...', '【必填】...', '【必填】...', '【选填】...', '【选填】...', '【选填】...'],
            ['LITB Style Blog (blog.lightinthebox.com)', 'Category Authority Guide (litb.geo_category_authority.en)', 'Style & Buying Guides', 'How to Choose Running Shoes', 'LITB Product KB', 'What matters most?', 'running shoes', "https://example.com/a\nhttps://example.com/b"],
        ]);

        $result = $this->app->make(BulkContentImportParser::class)->parse($path);

        $this->assertSame([], $result['errors']);
        $this->assertCount(1, $result['rows']);
        $row = $result['rows'][0];
        $this->assertSame($channel->id, $row->distributionChannelId);
        $this->assertSame($preset->id, $row->promptPresetId);
        $this->assertSame($category->id, $row->categoryId);
        $this->assertSame($knowledgeBase->id, $row->knowledgeBaseId);
        $this->assertSame('How to Choose Running Shoes', $row->topic);
        $this->assertSame(['https://example.com/a', 'https://example.com/b'], $row->referenceUrls);
    }

    public function test_reports_an_error_for_an_unresolvable_site_without_processing_any_row(): void
    {
        $preset = PromptPreset::query()->create(['preset_key' => 'litb.geo_category_authority.en', 'display_name' => 'x', 'is_system' => true, 'is_enabled' => true]);
        $category = Category::query()->create(['name' => 'Style & Buying Guides', 'slug' => 'style-and-buying-guides']);
        $knowledgeBase = KnowledgeBase::query()->create(['name' => 'KB', 'content' => 'x', 'review_status' => 'reviewed']);

        $path = $this->buildFixture([
            ['site', 'preset', 'category', 'topic', 'knowledge_base'],
            ['instructions'],
            ['Unknown Site (nowhere.example.com)', 'x (litb.geo_category_authority.en)', 'Style & Buying Guides', 'Topic', 'KB'],
        ]);

        $result = $this->app->make(BulkContentImportParser::class)->parse($path);

        $this->assertSame([], $result['rows']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame(3, $result['errors'][0]['row']);
    }

    public function test_conflicts_flags_a_differing_knowledge_base_within_the_same_group(): void
    {
        $parser = $this->app->make(BulkContentImportParser::class);
        $rowA = new BulkContentImportRow(3, 1, 1, 1, 10, 'Topic A', null, null, []);
        $rowB = new BulkContentImportRow(4, 1, 1, 1, 20, 'Topic B', null, null, []);

        $errors = $parser->conflicts([$rowA, $rowB]);

        $this->assertCount(1, $errors);
        $this->assertSame(4, $errors[0]['row']);
    }

    /** @param list<list<string>> $rows */
    private function buildFixture(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $colIndex => $value) {
                $sheet->setCellValue([$colIndex + 1, $rowIndex + 1], $value);
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'bulk-import-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }
}
