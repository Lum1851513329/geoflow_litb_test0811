<?php

namespace Tests\Feature;

use App\Events\ContentGenerationCompleted;
use App\Events\ContentGenerationFailed;
use App\Models\AiModel;
use App\Models\ContentBlueprintVersion;
use App\Models\ContentBrief;
use App\Models\ContentGenerationRun;
use App\Models\ContentStrategyVersion;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use App\Services\GeoFlow\LitbAssets\LitbAssetConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ArchiveLitbContentGenerationTest extends TestCase
{
    use RefreshDatabase;

    private const QUARTER_KEY = 'litb-quarterly-2026-q3';

    private string $archiveRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->archiveRoot = sys_get_temp_dir().'/litb-listener-test-'.uniqid();
        $this->app->instance(LitbAssetConfig::class, new LitbAssetConfig(
            knowledgeRoot: sys_get_temp_dir(),
            keywordRoot: sys_get_temp_dir(),
            quarterKey: self::QUARTER_KEY,
            archiveRoot: $this->archiveRoot,
        ));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->archiveRoot);

        parent::tearDown();
    }

    public function test_a_completed_litb_run_is_archived_as_a_full_article(): void
    {
        $litbRun = $this->finalize($this->pendingRun('litb-quarterly-2026-q3.001.archived-by-listener'), ContentGenerationRun::STATUS_READY_FOR_REVIEW);

        event(new ContentGenerationCompleted((int) $litbRun->id, $litbRun->status));

        $this->assertFileExists($this->articlePath('archived-by-listener'));
    }

    public function test_a_non_litb_run_is_not_archived(): void
    {
        $nonLitbRun = $this->finalize($this->pendingRun('other-namespace.001.not-litb'), ContentGenerationRun::STATUS_READY_FOR_REVIEW);

        event(new ContentGenerationCompleted((int) $nonLitbRun->id, $nonLitbRun->status));

        $this->assertFileDoesNotExist($this->articlePath('not-litb'));
    }

    public function test_a_failed_litb_run_gets_diagnostic_manifests_without_an_article_file(): void
    {
        $failedRun = $this->finalize($this->pendingRun('litb-quarterly-2026-q3.002.failed-run', null), ContentGenerationRun::STATUS_FAILED, 'Model provider timed out.');

        event(new ContentGenerationFailed((int) $failedRun->id, $failedRun->status, 'Model provider timed out.'));

        $this->assertFileDoesNotExist($this->articlePath('failed-run'));
        $this->assertFileExists($this->archiveRoot.'/quality-reports/failed-run.json');
        $this->assertFileExists($this->archiveRoot.'/evidence-manifests/failed-run.json');
    }

    public function test_a_missing_run_id_is_ignored_without_error(): void
    {
        event(new ContentGenerationCompleted(999999, ContentGenerationRun::STATUS_READY_FOR_REVIEW));

        $this->assertDirectoryDoesNotExist($this->archiveRoot.'/articles');
    }

    private function articlePath(string $slug): string
    {
        return $this->archiveRoot.'/articles/en/'.$slug.'.md';
    }

    private function finalize(ContentGenerationRun $run, string $status, ?string $errorMessage = null): ContentGenerationRun
    {
        $run->forceFill(['status' => $status, 'completed_at' => now(), 'error_message' => $errorMessage])->save();

        return $run->fresh();
    }

    private function pendingRun(string $assetKey, ?string $rawOutput = "## Fit\nChoose shoes with a thumb width in front of the longest toe."): ContentGenerationRun
    {
        $brief = ContentBrief::query()->create([
            'asset_key' => $assetKey,
            'asset_provenance' => ['slug' => explode('.', $assetKey)[2] ?? 'listener-test'],
            'topic' => 'Listener test topic',
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
            'preset_key' => 'listener-'.str()->uuid(),
            'display_name' => 'Listener preset',
        ]);
        $presetVersion = PromptPresetVersion::forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => 1,
            'matching_rules' => [],
            'generation_policy' => [],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
        ]);
        $model = AiModel::query()->create([
            'name' => 'Listener model',
            'version' => '2026-08',
            'api_key' => 'test-key',
            'api_url' => 'https://model.example.test/v1',
            'model_id' => 'listener-model',
            'max_tokens' => 4096,
        ]);

        return ContentGenerationRun::forceCreate([
            'content_brief_id' => $brief->id,
            'strategy_version_id' => $strategy->id,
            'blueprint_version_id' => $blueprint->id,
            'preset_version_id' => $presetVersion->id,
            'ai_model_id' => $model->id,
            'compiled_prompt' => 'Write test content.',
            'raw_output' => $rawOutput,
            'status' => ContentGenerationRun::STATUS_PENDING,
            'idempotency_key' => 'listener-'.str()->uuid(),
        ]);
    }
}
