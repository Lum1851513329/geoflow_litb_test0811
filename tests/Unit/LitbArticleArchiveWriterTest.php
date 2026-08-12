<?php

namespace Tests\Unit;

use App\Models\AiModel;
use App\Models\ContentBlueprintVersion;
use App\Models\ContentBrief;
use App\Models\ContentGenerationRun;
use App\Models\ContentGenerationRunEvidence;
use App\Models\ContentQualityReport;
use App\Models\ContentStrategyVersion;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use App\Services\GeoFlow\LitbAssets\LitbAssetConfig;
use App\Services\GeoFlow\LitbAssets\LitbArticleArchiveWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;

class LitbArticleArchiveWriterTest extends TestCase
{
    use RefreshDatabase;

    private string $archiveRoot;

    private LitbArticleArchiveWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->archiveRoot = sys_get_temp_dir().'/litb-archive-test-'.uniqid();
        $config = new LitbAssetConfig(
            knowledgeRoot: sys_get_temp_dir(),
            keywordRoot: sys_get_temp_dir(),
            quarterKey: 'litb-quarterly-2026-q3',
            archiveRoot: $this->archiveRoot,
        );
        $this->writer = new LitbArticleArchiveWriter($config);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->archiveRoot);

        parent::tearDown();
    }

    public function test_it_writes_wordpress_ready_markdown_evidence_and_quality_files(): void
    {
        $run = $this->pendingRun();
        ContentGenerationRunEvidence::query()->create([
            'content_generation_run_id' => $run->id,
            'evidence_key' => 'official-company-brand.001',
            'evidence_snapshot' => ['title' => 'About LightInTheBox'],
            'is_required' => true,
        ]);
        ContentQualityReport::query()->create([
            'content_generation_run_id' => $run->id,
            'report_type' => 'deterministic',
            'status' => 'passed',
            'passed' => true,
            'score' => 92.5,
            'deterministic_results' => [],
            'issues' => [],
        ]);
        $this->finalize($run, ContentGenerationRun::STATUS_READY_FOR_REVIEW);

        $result = $this->writer->write($run->fresh());

        $this->assertNotNull($result->articlePath);
        $markdown = file_get_contents($result->articlePath);
        $this->assertStringContainsString('title:', $markdown);
        $this->assertStringContainsString('slug:', $markdown);
        $this->assertStringContainsString('seo_title:', $markdown);
        $this->assertStringContainsString('content_generation_run_id: '.$run->id, $markdown);
        $this->assertStringContainsString($run->raw_output, $markdown);

        $this->assertJson(file_get_contents($result->evidencePath));
        $evidence = json_decode(file_get_contents($result->evidencePath), true);
        $this->assertCount(1, $evidence['evidence']);

        $this->assertJson(file_get_contents($result->qualityPath));
        $quality = json_decode(file_get_contents($result->qualityPath), true);
        $this->assertCount(1, $quality['quality_reports']);
    }

    public function test_rerun_atomically_replaces_the_previous_archive_for_the_same_brief(): void
    {
        $brief = $this->brief();
        $context = $this->generationContext($brief);
        $first = $this->finalize($this->runFor($context, 'First draft body.'), ContentGenerationRun::STATUS_READY_FOR_REVIEW);
        $firstResult = $this->writer->write($first->fresh());
        $firstContents = file_get_contents($firstResult->articlePath);

        $second = $this->finalize($this->runFor($context, 'Second, corrected draft body.'), ContentGenerationRun::STATUS_READY_FOR_REVIEW);
        $secondResult = $this->writer->write($second->fresh());

        $this->assertSame($firstResult->articlePath, $secondResult->articlePath);
        $this->assertNotSame($firstContents, file_get_contents($secondResult->articlePath));
        $this->assertStringContainsString('Second, corrected draft body.', file_get_contents($secondResult->articlePath));
        $this->assertCount(0, glob(dirname($secondResult->articlePath).'/*.tmp'));
    }

    public function test_it_rejects_a_non_content_bearing_run(): void
    {
        $run = $this->finalize($this->pendingRun(), ContentGenerationRun::STATUS_FAILED);

        $this->expectException(InvalidArgumentException::class);

        $this->writer->write($run->fresh());
    }

    public function test_it_rejects_missing_final_content(): void
    {
        $run = $this->finalize($this->pendingRun(null, '   '), ContentGenerationRun::STATUS_READY_FOR_REVIEW);

        $this->expectException(InvalidArgumentException::class);

        $this->writer->write($run->fresh());
    }

    public function test_it_rejects_a_run_whose_brief_is_outside_the_litb_asset_namespace(): void
    {
        $brief = $this->brief(['asset_key' => 'other-namespace.001']);
        $run = $this->finalize($this->pendingRun($brief), ContentGenerationRun::STATUS_READY_FOR_REVIEW);

        $this->expectException(InvalidArgumentException::class);

        $this->writer->write($run->fresh());
    }

    public function test_write_diagnostics_records_evidence_and_quality_without_an_article_file_for_needs_evidence_runs(): void
    {
        $run = $this->finalize($this->pendingRun(null, null), ContentGenerationRun::STATUS_NEEDS_EVIDENCE);

        $result = $this->writer->writeDiagnostics($run->fresh());

        $this->assertNull($result->articlePath);
        $this->assertFileExists($result->evidencePath);
        $this->assertFileExists($result->qualityPath);
    }

    private function finalize(ContentGenerationRun $run, string $status): ContentGenerationRun
    {
        $run->forceFill(['status' => $status, 'completed_at' => now()])->save();

        return $run->fresh();
    }

    /** @param array<string,mixed> $overrides */
    private function brief(array $overrides = []): ContentBrief
    {
        return ContentBrief::query()->create(array_replace([
            'asset_key' => 'litb-quarterly-2026-q3.001.running-shoe-fit-guide',
            'asset_provenance' => [
                'slug' => 'running-shoe-fit-guide',
                'seo_title' => 'Running Shoe Fit Guide | LightInTheBox',
                'meta_description' => 'How to choose running shoes that fit.',
                'primary_keyword' => 'running shoe fit',
                'secondary_keywords' => ['running shoe fit guide'],
                'question_keywords' => ['how should running shoes fit'],
                'pillar' => 'product_education',
                'schema_types' => ['Article'],
                'store_landing_page_type' => 'category',
            ],
            'topic' => 'Running shoe fit',
            'market_code' => 'GLOBAL',
            'language_code' => 'en',
            'audience' => 'First-time runners',
            'core_question' => 'How should running shoes fit?',
            'business_goal' => 'Help readers choose a fit',
            'content_type' => 'buying_guide',
            'status' => ContentBrief::STATUS_STRATEGY_READY,
        ], $overrides));
    }

    /** @return array{brief: ContentBrief, strategy: ContentStrategyVersion, blueprint: ContentBlueprintVersion, presetVersion: PromptPresetVersion, model: AiModel} */
    private function generationContext(ContentBrief $brief): array
    {
        $strategy = ContentStrategyVersion::forceCreate([
            'content_brief_id' => $brief->id,
            'version' => 1,
            'strategy' => ['primary_intent' => 'informational'],
            'lifecycle_status' => ContentStrategyVersion::STATUS_CONFIRMED,
        ]);
        $blueprint = ContentBlueprintVersion::forceCreate([
            'content_brief_id' => $brief->id,
            'content_strategy_version_id' => $strategy->id,
            'version' => 1,
            'blueprint' => ['sections' => []],
            'lifecycle_status' => ContentBlueprintVersion::STATUS_CONFIRMED,
        ]);
        $preset = PromptPreset::query()->create([
            'preset_key' => 'archive-writer-'.str()->uuid(),
            'display_name' => 'Archive writer preset',
        ]);
        $presetVersion = PromptPresetVersion::forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => 1,
            'matching_rules' => [],
            'generation_policy' => [],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
        ]);
        $model = AiModel::query()->create([
            'name' => 'Archive writer model',
            'version' => '2026-08',
            'api_key' => 'test-key',
            'api_url' => 'https://model.example.test/v1',
            'model_id' => 'archive-writer-model',
            'max_tokens' => 4096,
        ]);

        return compact('brief', 'strategy', 'blueprint', 'presetVersion', 'model');
    }

    /** @param array{brief: ContentBrief, strategy: ContentStrategyVersion, blueprint: ContentBlueprintVersion, presetVersion: PromptPresetVersion, model: AiModel} $context */
    private function runFor(array $context, ?string $rawOutput): ContentGenerationRun
    {
        return ContentGenerationRun::forceCreate([
            'content_brief_id' => $context['brief']->id,
            'strategy_version_id' => $context['strategy']->id,
            'blueprint_version_id' => $context['blueprint']->id,
            'preset_version_id' => $context['presetVersion']->id,
            'ai_model_id' => $context['model']->id,
            'compiled_prompt' => 'Write a practical running shoe fit guide.',
            'raw_output' => $rawOutput,
            'status' => ContentGenerationRun::STATUS_PENDING,
            'idempotency_key' => 'archive-writer-'.str()->uuid(),
        ]);
    }

    private function pendingRun(?ContentBrief $brief = null, ?string $rawOutput = "## Fit\nChoose shoes with a thumb width in front of the longest toe."): ContentGenerationRun
    {
        return $this->runFor($this->generationContext($brief ?? $this->brief()), $rawOutput);
    }
}
