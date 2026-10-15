<?php

namespace Tests\Unit\Semrush;

use App\Models\Category;
use App\Models\KnowledgeBrand;
use App\Models\KnowledgeSite;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use App\Services\GeoFlow\Semrush\SemrushTopicGapConverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use Tests\TestCase;

final class SemrushTopicGapConverterTest extends TestCase
{
    use RefreshDatabase;

    public function test_converts_an_acegolf_topic_without_litb_category_or_preset_names(): void
    {
        $brand = KnowledgeBrand::query()->create(['key' => 'acegolf', 'name' => 'AceGolf']);
        KnowledgeSite::query()->create([
            'brand_id' => $brand->id,
            'key' => 'acegolf-main',
            'canonical_host' => 'www.acegolfs.com',
            'role' => 'storefront',
        ]);
        Category::query()->create(['name' => 'Golf Buying Guides', 'slug' => 'golf-buying-guides']);
        $preset = PromptPreset::query()->create([
            'preset_key' => 'acegolf.buying-guide.en',
            'display_name' => 'AceGolf Buying Guide',
            'is_system' => false,
            'is_enabled' => true,
        ]);
        $version = PromptPresetVersion::query()->forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => 1,
            'matching_rules' => ['content_type' => ['buying_guide']],
            'generation_policy' => [],
            'blueprint_template' => ['sections' => []],
            'default_brief_fields' => ['content_type' => 'buying_guide'],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
        ]);
        $preset->forceFill(['current_version_id' => $version->id])->save();

        $path = $this->csvFixture([
            ['name', 'country', 'volume', 'difficulty', 'intents'],
            ['How to choose golf irons', 'us', '1200', '35', 'informational:70;commercial:30'],
        ]);

        $result = $this->app->make(SemrushTopicGapConverter::class)->convert($path, 10, $brand->id);

        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['accepted']);
        $this->assertSame('general', $result['accepted'][0]['knowledge_mode']);
        $this->assertSame('on_insufficient', $result['accepted'][0]['external_evidence_policy']);
        $this->assertSame('acegolf.buying-guide.en', $result['accepted'][0]['preset_key']);
        $this->assertSame('Golf Buying Guides', $result['accepted'][0]['category']);
    }

    private function csvFixture(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'semrush-');
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($rows as $rowNumber => $row) {
            foreach ($row as $column => $value) {
                $sheet->setCellValue([$column + 1, $rowNumber + 1], $value);
            }
        }
        (new Csv($spreadsheet))->save($path);

        return $path;
    }
}
