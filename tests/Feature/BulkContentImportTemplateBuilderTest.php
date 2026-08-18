<?php

namespace Tests\Feature;

use App\Domain\ContentGeneration\BulkContentImportTemplateBuilder;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use Tests\TestCase;

final class BulkContentImportTemplateBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_sheet_values_match_the_format_the_parser_expects(): void
    {
        DistributionChannel::query()->create([
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
        Category::query()->create(['name' => 'Style & Buying Guides', 'slug' => 'style-and-buying-guides']);

        $spreadsheet = $this->app->make(BulkContentImportTemplateBuilder::class)->build();

        $mainSheet = $spreadsheet->getSheet(0);
        $this->assertSame('site', $mainSheet->getCell('A1')->getValue());
        $this->assertStringContainsString('必填', (string) $mainSheet->getCell('A2')->getValue());

        $referenceSheet = $spreadsheet->getSheetByName('可选值参考');
        $this->assertSame('LITB Style Blog (blog.lightinthebox.com)', $referenceSheet->getCell('A2')->getValue());
        $this->assertSame('Category Authority Guide (litb.geo_category_authority.en)', $referenceSheet->getCell('B2')->getValue());
        $this->assertSame('Style & Buying Guides', $referenceSheet->getCell('C2')->getValue());

        $validation = $mainSheet->getCell('A3')->getDataValidation();
        $this->assertSame(DataValidation::TYPE_LIST, $validation->getType());
        $this->assertStringContainsString('可选值参考', $validation->getFormula1());
    }
}
