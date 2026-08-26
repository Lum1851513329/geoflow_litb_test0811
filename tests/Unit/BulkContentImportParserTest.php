<?php

namespace Tests\Unit;

use App\Domain\ContentGeneration\BulkContentImportParser;
use App\Domain\ContentGeneration\BulkContentImportRow;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeBrand;
use App\Models\KnowledgeDirectory;
use App\Models\KnowledgeSite;
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
        $channel = DistributionChannel::query()->create([
            'name' => 'LITB Style Blog', 'domain' => 'blog.lightinthebox.com',
            'endpoint_url' => 'https://blog.lightinthebox.com', 'channel_type' => 'wordpress', 'status' => 'active',
        ]);
        $preset = PromptPreset::query()->create(['preset_key' => 'litb.geo_category_authority.en', 'display_name' => 'x', 'is_system' => true, 'is_enabled' => true]);
        $version = PromptPresetVersion::query()->forceCreate([
            'prompt_preset_id' => $preset->id, 'version' => 1, 'matching_rules' => [], 'generation_policy' => [],
            'blueprint_template' => ['target_length' => 1800, 'sections' => []],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
        ]);
        $preset->forceFill(['current_version_id' => $version->id])->save();
        $category = Category::query()->create(['name' => 'Style & Buying Guides', 'slug' => 'style-and-buying-guides']);
        $knowledgeBase = KnowledgeBase::query()->create(['name' => 'KB', 'content' => 'x', 'review_status' => 'reviewed']);

        // Row 3 is unresolvable (unknown site domain). Row 4 would resolve
        // successfully entirely on its own using the same, valid channel/preset/
        // category/knowledge_base as row 3 references correctly elsewhere — it
        // exists here only to prove that a good row sitting alongside a bad row
        // in the same batch is also rejected, not silently kept. A single-bad-row
        // fixture can't distinguish "reject the whole batch on any error" (the
        // correct behavior) from a buggy "drop only the bad row" implementation,
        // since there'd be no other row available to wrongly survive.
        $path = $this->buildFixture([
            ['site', 'preset', 'category', 'topic', 'knowledge_base', 'core_question', 'primary_keyword', 'reference_urls'],
            ['instructions'],
            ['Unknown Site (nowhere.example.com)', 'x (litb.geo_category_authority.en)', 'Style & Buying Guides', 'Topic', 'KB', '', '', ''],
            ['LITB Style Blog (blog.lightinthebox.com)', 'x (litb.geo_category_authority.en)', 'Style & Buying Guides', 'Another Topic', 'KB', '', '', ''],
        ]);

        $result = $this->app->make(BulkContentImportParser::class)->parse($path);

        $this->assertSame([], $result['rows'], 'the good row (row 4) must also be rejected, not kept, when row 3 fails to resolve');
        $this->assertCount(1, $result['errors']);
        $this->assertSame(3, $result['errors'][0]['row']);
    }

    public function test_distinct_legacy_libraries_form_separate_groups(): void
    {
        $parser = $this->app->make(BulkContentImportParser::class);
        $rowA = new BulkContentImportRow(3, 1, 1, 1, 10, 'Topic A', null, null, []);
        $rowB = new BulkContentImportRow(4, 1, 1, 1, 20, 'Topic B', null, null, []);

        $errors = $parser->conflicts([$rowA, $rowB]);

        $this->assertSame([], $errors);
        $this->assertNotSame($rowA->groupKey(), $rowB->groupKey());
    }

    public function test_brand_rows_resolve_unique_source_site_and_descendant_directory_by_path(): void
    {
        [$brand, $site] = $this->brandFixtures();
        $parent = KnowledgeDirectory::create(['brand_id' => $brand->id, 'key' => 'root', 'name' => 'Root', 'path' => '/root']);
        $child = KnowledgeDirectory::create(['brand_id' => $brand->id, 'parent_id' => $parent->id, 'key' => 'leaf', 'name' => 'Leaf', 'path' => '/root/leaf']);
        $result = $this->parseBrandRows([['Brand (brand)', '/root/leaf', '', '', 'shoes']]);
        $this->assertSame([], $result['errors']);
        $row = $result['rows'][0];
        $this->assertSame('brand_auto', $row->knowledgeMode);
        $this->assertSame($brand->id, $row->knowledgeBrandId);
        $this->assertSame($site->id, $row->knowledgeSiteId);
        $this->assertSame($child->id, $row->knowledgeDirectoryId);
        $this->assertNull($row->knowledgeBaseId);
        $this->assertSame('shoes', $row->primaryKeyword);
    }

    public function test_explicit_source_site_and_distinct_brand_scopes_never_merge(): void
    {
        [$brand, $site] = $this->brandFixtures();
        $secondSite = KnowledgeSite::create(['role' => 'primary', 'brand_id' => $brand->id, 'key' => 'second', 'canonical_host' => 'second.example']);
        $other = KnowledgeBrand::create(['key' => 'other', 'name' => 'Other']);
        KnowledgeSite::create(['role' => 'primary', 'brand_id' => $other->id, 'key' => 'other', 'canonical_host' => 'other.example']);
        $result = $this->parseBrandRows([['brand', '', 'source'], ['brand', '', 'second'], ['Other']]);
        $this->assertSame([], $result['errors']);
        $this->assertSame($secondSite->id, $result['rows'][1]->knowledgeSiteId);
        $this->assertCount(3, array_unique(array_map(fn ($row) => $row->groupKey(), $result['rows'])));
    }

    public function test_ambiguous_or_mixed_scope_rejects_every_row(): void
    {
        [$brand, $site] = $this->brandFixtures();
        KnowledgeBase::create(['name' => 'Legacy', 'content' => 'x']);
        KnowledgeDirectory::create(['brand_id' => $brand->id, 'key' => 'leaf', 'name' => 'Leaf', 'path' => '/one/leaf']);
        KnowledgeDirectory::create(['brand_id' => $brand->id, 'key' => 'leaf', 'name' => 'Leaf', 'path' => '/two/leaf']);
        KnowledgeBrand::create(['key' => 'duplicate1', 'name' => 'Duplicate']);
        KnowledgeBrand::create(['key' => 'duplicate2', 'name' => 'Duplicate']);
        foreach ([['brand', 'leaf'], ['brand', 'Leaf'], ['brand', '', '', 'Legacy'], ['', '/one/leaf', '', 'Legacy'], ['Duplicate'], ['missing'], ['']] as $badRow) {
            $result = $this->parseBrandRows([['brand'], $badRow]);
            $this->assertSame([], $result['rows']);
            $this->assertSame(4, $result['errors'][0]['row']);
        }
        KnowledgeSite::create(['role' => 'primary', 'brand_id' => $brand->id, 'key' => 'second', 'canonical_host' => 'second.example']);
        $result = $this->parseBrandRows([['brand', '', 'source'], ['brand']]);
        $this->assertSame([], $result['rows']);
        $this->assertSame(4, $result['errors'][0]['row']);
        $this->assertStringContainsString('source site', $result['errors'][0]['message']);
    }

    private function brandFixtures(): array
    {
        DistributionChannel::create(['name' => 'Publish', 'domain' => 'publish.example', 'endpoint_url' => 'https://publish.example', 'channel_type' => 'wordpress', 'status' => 'active']);
        $preset = PromptPreset::create(['preset_key' => 'preset', 'display_name' => 'Preset', 'is_enabled' => true]);
        $version = PromptPresetVersion::forceCreate(['prompt_preset_id' => $preset->id, 'version' => 1, 'matching_rules' => [], 'generation_policy' => [], 'blueprint_template' => ['sections' => []], 'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION]);
        $preset->forceFill(['current_version_id' => $version->id])->save();
        Category::create(['name' => 'Category', 'slug' => 'category']);
        $brand = KnowledgeBrand::create(['key' => 'brand', 'name' => 'Brand']);
        $site = KnowledgeSite::create(['role' => 'primary', 'brand_id' => $brand->id, 'key' => 'source', 'canonical_host' => 'source.example']);

        return [$brand, $site];
    }

    public function test_exact_names_with_parentheses_and_full_directory_paths_are_accepted(): void
    {
        [$brand] = $this->brandFixtures();
        $brand->update(['name' => 'Brand (US)']);
        $directory = KnowledgeDirectory::create(['brand_id' => $brand->id, 'key' => 'leaf', 'name' => 'Leaf (US)', 'path' => '/root/leaf(us)']);
        $result = $this->parseBrandRows([['Brand (US)', 'Leaf (US)'], ['brand', '/root/leaf(us)'], ['Brand (US) (brand)', 'brand / Leaf (US) (/root/leaf(us))']]);
        $this->assertSame([], $result['errors']);
        $this->assertSame($directory->id, $result['rows'][0]->knowledgeDirectoryId);
        $this->assertSame($result['rows'][0]->groupKey(), $result['rows'][1]->groupKey());
        $this->assertSame($result['rows'][0]->groupKey(), $result['rows'][2]->groupKey());
    }

    public function test_legacy_and_brand_rows_and_directory_scopes_have_distinct_groups(): void
    {
        [$brand] = $this->brandFixtures();
        KnowledgeBase::create(['name' => 'Legacy', 'content' => 'x']);
        KnowledgeDirectory::create(['brand_id' => $brand->id, 'key' => 'root', 'name' => 'Root', 'path' => '/root']);
        $result = $this->parseBrandRows([['brand'], ['brand', 'root'], ['', '', '', 'Legacy']]);
        $this->assertSame([], $result['errors']);
        $this->assertCount(3, array_unique(array_map(fn ($row) => $row->groupKey(), $result['rows'])));
        $this->assertSame('legacy_manual', $result['rows'][2]->knowledgeMode);
    }

    private function parseBrandRows(array $rows): array
    {
        $path = $this->buildFixture(array_merge([
            ['site', 'preset', 'category', 'topic', 'brand', 'directory', 'knowledge_site', 'knowledge_base', 'primary_keyword'],
            ['instructions'],
        ], array_map(fn ($row) => array_merge(['Publish (publish.example)', 'Preset (preset)', 'Category', 'Topic'], $row), $rows)));
        try {
            return $this->app->make(BulkContentImportParser::class)->parse($path);
        } finally {
            unlink($path);
        }
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
