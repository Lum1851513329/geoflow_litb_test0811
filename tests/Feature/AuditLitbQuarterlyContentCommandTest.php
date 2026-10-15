<?php

namespace Tests\Feature;

use App\Models\AiModel;
use App\Models\ContentBlueprintVersion;
use App\Models\ContentBrief;
use App\Models\ContentGenerationRun;
use App\Models\ContentStrategyVersion;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use App\Services\GeoFlow\LitbAssets\LitbArticleArchiveWriter;
use App\Services\GeoFlow\LitbAssets\LitbAssetConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AuditLitbQuarterlyContentCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $archiveRoot;

    private LitbArticleArchiveWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->archiveRoot = sys_get_temp_dir().'/litb-audit-test-'.uniqid();
        $config = new LitbAssetConfig(
            knowledgeRoot: sys_get_temp_dir(),
            keywordRoot: sys_get_temp_dir(),
            quarterKey: 'litb-quarterly-2026-q3',
            archiveRoot: $this->archiveRoot,
        );
        $this->app->instance(LitbAssetConfig::class, $config);
        $this->writer = $this->app->make(LitbArticleArchiveWriter::class);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->archiveRoot);

        parent::tearDown();
    }

    public function test_a_clean_archive_passes_strict_audit(): void
    {
        $this->archivedRun();

        $this->artisan('geoflow:litb-content:audit', ['--strict' => true])
            ->expectsOutputToContain('Issues found: 0')
            ->assertSuccessful();
    }

    public function test_a_corrupted_content_hash_fails_strict_audit(): void
    {
        $run = $this->archivedRun();
        $articlePath = $this->archiveRoot.'/articles/en/audit-test.md';
        File::put($articlePath, str_replace(hash('sha256', trim($run->raw_output)), str_repeat('0', 64), File::get($articlePath)));

        $this->artisan('geoflow:litb-content:audit', ['--strict' => true])
            ->expectsOutputToContain('content_hash_mismatch')
            ->assertFailed();
    }

    public function test_a_missing_quality_manifest_fails_strict_audit(): void
    {
        $this->archivedRun();
        File::delete($this->archiveRoot.'/quality-reports/audit-test.json');

        $this->artisan('geoflow:litb-content:audit', ['--strict' => true])
            ->expectsOutputToContain('missing_quality_manifest')
            ->assertFailed();
    }

    public function test_non_strict_mode_reports_issues_but_still_succeeds(): void
    {
        $this->archivedRun();
        File::delete($this->archiveRoot.'/quality-reports/audit-test.json');

        $this->artisan('geoflow:litb-content:audit')
            ->expectsOutputToContain('missing_quality_manifest')
            ->assertSuccessful();
    }

    private function archivedRun(): ContentGenerationRun
    {
        $brief = ContentBrief::query()->create([
            'asset_key' => 'litb-quarterly-2026-q3.001.audit-test',
            'asset_provenance' => ['slug' => 'audit-test'],
            'topic' => 'Audit test topic',
            'market_code' => 'GLOBAL',
            'language_code' => 'en',
            'audience' => 'Global shoppers',
            'core_question' => 'Test question?',
            'business_goal' => 'Test goal',
            'content_type' => 'guide',
            'status' => ContentBrief::STATUS_STRATEGY_READY,
        ]);
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
            'preset_key' => 'audit-'.str()->uuid(),
            'display_name' => 'Audit preset',
        ]);
        $presetVersion = PromptPresetVersion::forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => 1,
            'matching_rules' => [],
            'generation_policy' => [],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
        ]);
        $model = AiModel::query()->create([
            'name' => 'Audit model',
            'version' => '2026-08',
            'api_key' => 'test-key',
            'api_url' => 'https://model.example.test/v1',
            'model_id' => 'audit-model',
            'max_tokens' => 4096,
        ]);
        $run = ContentGenerationRun::forceCreate([
            'content_brief_id' => $brief->id,
            'strategy_version_id' => $strategy->id,
            'blueprint_version_id' => $blueprint->id,
            'preset_version_id' => $presetVersion->id,
            'ai_model_id' => $model->id,
            'compiled_prompt' => 'Write audit test content.',
            'raw_output' => "## Section\nAudit test content body.",
            'status' => ContentGenerationRun::STATUS_PENDING,
            'idempotency_key' => 'audit-'.str()->uuid(),
        ]);
        $run->forceFill(['status' => ContentGenerationRun::STATUS_READY_FOR_REVIEW, 'completed_at' => now()])->save();
        $this->writer->write($run->fresh());

        return $run->fresh();
    }
}
