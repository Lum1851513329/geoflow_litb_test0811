<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\ContentBlueprintVersion;
use App\Models\ContentBrief;
use App\Models\ContentGenerationRun;
use App\Models\ContentStrategyVersion;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use App\Services\GeoFlow\LitbAssets\LitbAssetConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateLitbQuarterlyContentCommandTest extends TestCase
{
    use RefreshDatabase;

    private const QUARTER_KEY = 'litb-quarterly-2026-q3';

    protected function setUp(): void
    {
        parent::setUp();

        $config = new LitbAssetConfig(
            knowledgeRoot: sys_get_temp_dir(),
            keywordRoot: sys_get_temp_dir(),
            quarterKey: self::QUARTER_KEY,
            archiveRoot: sys_get_temp_dir().'/litb-command-test',
        );
        $this->app->instance(LitbAssetConfig::class, $config);
    }

    public function test_dry_run_reports_eligibility_without_dispatching_any_generation(): void
    {
        $this->activeModel();
        $this->readyBrief('001', 'faq');

        $this->artisan('geoflow:litb-content:generate', ['--dry-run' => true, '--pillar' => 'faq', '--limit' => 3])
            ->expectsOutputToContain('Eligible: 1')
            ->assertSuccessful();

        $this->assertDatabaseCount('content_generation_runs', 0);
    }

    public function test_limit_outside_bounds_is_rejected(): void
    {
        $this->artisan('geoflow:litb-content:generate', ['--dry-run' => true, '--limit' => 26])
            ->assertFailed();
    }

    public function test_a_real_batch_dispatches_eligible_briefs_and_resume_does_not_duplicate(): void
    {
        Admin::query()->create([
            'username' => 'litb-command-operator',
            'password' => 'secret-123',
            'email' => 'litb-command-operator@example.com',
            'display_name' => 'LITB Command Operator',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $this->activeModel();
        $this->readyBrief('002', 'faq');

        $this->artisan('geoflow:litb-content:generate', ['--pillar' => 'faq', '--limit' => 5])
            ->expectsOutputToContain('Dispatched: 1')
            ->assertSuccessful();
        $this->assertDatabaseCount('content_generation_runs', 1);

        $this->artisan('geoflow:litb-content:generate', ['--resume' => true, '--pillar' => 'faq', '--limit' => 5])
            ->expectsOutputToContain('Dispatched: 0')
            ->assertSuccessful();
        $this->assertDatabaseCount('content_generation_runs', 1);
    }

    public function test_no_active_admin_fails_a_real_batch_without_dispatching(): void
    {
        $this->activeModel();
        $this->readyBrief('003', 'faq');

        $this->artisan('geoflow:litb-content:generate', ['--pillar' => 'faq', '--limit' => 5])
            ->assertFailed();
        $this->assertDatabaseCount('content_generation_runs', 0);
    }

    private function activeModel(): AiModel
    {
        return AiModel::query()->create([
            'name' => 'LITB command model',
            'version' => '2026-08',
            'api_key' => 'test-key',
            'api_url' => 'https://model.example.test/v1',
            'model_id' => 'litb-command-model',
            'max_tokens' => 4096,
            'status' => 'active',
            'model_type' => 'chat',
        ]);
    }

    private function readyBrief(string $sequence, string $pillar): ContentBrief
    {
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => 'LITB command knowledge '.uniqid(),
            'review_status' => 'reviewed',
            'asset_key' => 'litb.knowledge.'.hash('sha256', uniqid()),
            'asset_provenance' => ['group_key' => 'official-company-brand'],
        ]);
        KnowledgeChunk::query()->create([
            'knowledge_base_id' => $knowledgeBase->id,
            'chunk_index' => 0,
            'content' => 'Reviewed evidence.',
            'content_hash' => hash('sha256', uniqid()),
        ]);

        $presetKey = 'litb.'.$pillar.'.en';
        $preset = PromptPreset::query()->create([
            'preset_key' => $presetKey,
            'display_name' => $presetKey,
            'is_system' => true,
            'is_enabled' => true,
        ]);
        $version = PromptPresetVersion::forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => 1,
            'matching_rules' => [],
            'generation_policy' => [],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
        ]);
        $preset->forceFill(['current_version_id' => $version->id])->save();

        $brief = ContentBrief::query()->create([
            'asset_key' => self::QUARTER_KEY.'.'.$sequence.'.command-test',
            'asset_provenance' => [
                'slug' => 'command-test-'.$sequence,
                'pillar' => $pillar,
                'prompt_preset_key' => $presetKey,
                'evidence_group_keys' => ['official-company-brand'],
                'risk_flags' => [],
            ],
            'topic' => 'Command test topic',
            'market_code' => 'GLOBAL',
            'language_code' => 'en',
            'audience' => 'Global shoppers',
            'core_question' => 'Test question?',
            'business_goal' => 'Test goal',
            'content_type' => 'faq',
            'status' => ContentBrief::STATUS_STRATEGY_READY,
        ]);
        $strategy = ContentStrategyVersion::forceCreate([
            'content_brief_id' => $brief->id,
            'version' => 1,
            'strategy' => ['primary_intent' => 'informational'],
            'lifecycle_status' => ContentStrategyVersion::STATUS_CONFIRMED,
        ]);
        ContentBlueprintVersion::forceCreate([
            'content_brief_id' => $brief->id,
            'content_strategy_version_id' => $strategy->id,
            'version' => 1,
            'blueprint' => ['sections' => []],
            'lifecycle_status' => ContentBlueprintVersion::STATUS_CONFIRMED,
        ]);

        return $brief;
    }
}
