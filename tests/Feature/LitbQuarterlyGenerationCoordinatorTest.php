<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\ContentBlueprintVersion;
use App\Models\ContentBrief;
use App\Models\ContentStrategyVersion;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use App\Services\GeoFlow\LitbAssets\LitbAssetConfig;
use App\Services\GeoFlow\LitbAssets\LitbQuarterlyGenerationCoordinator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LitbQuarterlyGenerationCoordinatorTest extends TestCase
{
    use RefreshDatabase;

    private const QUARTER_KEY = 'litb-quarterly-2026-q3';

    private Admin $admin;

    private LitbQuarterlyGenerationCoordinator $coordinator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::query()->create([
            'username' => 'litb-generation-operator',
            'password' => 'secret-123',
            'email' => 'litb-generation-operator@example.com',
            'display_name' => 'LITB Generation Operator',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $config = new LitbAssetConfig(
            knowledgeRoot: sys_get_temp_dir(),
            keywordRoot: sys_get_temp_dir(),
            quarterKey: self::QUARTER_KEY,
            archiveRoot: sys_get_temp_dir().'/litb-coordinator-test',
        );
        $this->app->instance(LitbAssetConfig::class, $config);
        $this->coordinator = $this->app->make(LitbQuarterlyGenerationCoordinator::class);
    }

    public function test_preview_separates_evidence_ready_briefs_from_needs_evidence_briefs(): void
    {
        $this->activeModel();
        $this->briefWithEvidence('001', 'product-category', 'product_education');
        $this->briefWithoutEvidence('002', 'news-investor-information', 'official_news');

        $preview = $this->coordinator->preview(['quarter_key' => self::QUARTER_KEY, 'limit' => 10]);

        $this->assertCount(1, $preview->eligible);
        $this->assertSame(self::QUARTER_KEY.'.001.evidence-ready', $preview->eligible[0]['asset_key']);
        $this->assertCount(1, $preview->skipped);
        $this->assertSame('needs_evidence', $preview->skipped[0]['reason']);
    }

    public function test_dispatch_is_idempotent_and_reuses_the_same_run_on_replay(): void
    {
        $this->activeModel();
        $this->briefWithEvidence('003', 'product-category', 'product_education');
        $filters = ['quarter_key' => self::QUARTER_KEY, 'limit' => 10];

        $first = $this->coordinator->dispatch($filters, $this->admin->id);
        $second = $this->coordinator->dispatch($filters, $this->admin->id);

        $this->assertCount(1, $first->runIds);
        $this->assertCount(0, $first->existingRunIds);
        $this->assertCount(0, $second->runIds);
        $this->assertSame($first->runIds, $second->existingRunIds);
    }

    public function test_dispatch_reports_no_active_model_when_none_is_configured(): void
    {
        $this->briefWithEvidence('004', 'product-category', 'product_education');

        $result = $this->coordinator->dispatch(['quarter_key' => self::QUARTER_KEY, 'limit' => 10], $this->admin->id);

        $this->assertCount(0, $result->runIds);
        $this->assertSame('no_active_model', $result->skipped[0]['reason']);
    }

    public function test_time_sensitive_new_arrivals_briefs_always_need_evidence(): void
    {
        $this->activeModel();
        $this->briefWithEvidence('005', 'product-category', 'new_arrivals', ['needs_current_product_evidence', 'time_sensitive']);

        $preview = $this->coordinator->preview(['quarter_key' => self::QUARTER_KEY, 'limit' => 10]);

        $this->assertCount(0, $preview->eligible);
        $this->assertSame('needs_evidence', $preview->skipped[0]['reason']);
    }

    private function activeModel(): AiModel
    {
        return AiModel::query()->create([
            'name' => 'LITB coordinator model',
            'version' => '2026-08',
            'api_key' => 'test-key',
            'api_url' => 'https://model.example.test/v1',
            'model_id' => 'litb-coordinator-model',
            'max_tokens' => 4096,
            'status' => 'active',
            'model_type' => 'chat',
        ]);
    }

    private function reviewedKnowledgeChunk(string $groupKey): KnowledgeChunk
    {
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => 'LITB '.$groupKey.' knowledge '.uniqid(),
            'review_status' => 'reviewed',
            'asset_key' => 'litb.knowledge.'.hash('sha256', $groupKey.uniqid()),
            'asset_provenance' => ['group_key' => $groupKey],
        ]);

        return KnowledgeChunk::query()->create([
            'knowledge_base_id' => $knowledgeBase->id,
            'chunk_index' => 0,
            'content' => 'Reviewed evidence for '.$groupKey.'.',
            'content_hash' => hash('sha256', $groupKey.uniqid()),
        ]);
    }

    /** @param list<string> $riskFlags */
    private function briefWithEvidence(string $sequence, string $evidenceGroupKey, string $pillar, array $riskFlags = []): ContentBrief
    {
        $this->reviewedKnowledgeChunk($evidenceGroupKey);
        $presetKey = 'litb.'.$pillar.'.en';
        $this->productionPreset($presetKey);

        return $this->brief($sequence, 'evidence-ready', $evidenceGroupKey, $pillar, $presetKey, $riskFlags);
    }

    private function briefWithoutEvidence(string $sequence, string $evidenceGroupKey, string $pillar): ContentBrief
    {
        $presetKey = 'litb.'.$pillar.'.en';
        $this->productionPreset($presetKey);

        return $this->brief($sequence, 'evidence-missing', $evidenceGroupKey, $pillar, $presetKey, []);
    }

    /** @param list<string> $riskFlags */
    private function brief(string $sequence, string $slugWord, string $evidenceGroupKey, string $pillar, string $presetKey, array $riskFlags): ContentBrief
    {
        $brief = ContentBrief::query()->create([
            'asset_key' => self::QUARTER_KEY.'.'.$sequence.'.'.$slugWord,
            'asset_provenance' => [
                'slug' => $slugWord,
                'pillar' => $pillar,
                'prompt_preset_key' => $presetKey,
                'evidence_group_keys' => [$evidenceGroupKey],
                'risk_flags' => $riskFlags,
            ],
            'topic' => 'Test topic '.$sequence,
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
        ContentBlueprintVersion::forceCreate([
            'content_brief_id' => $brief->id,
            'content_strategy_version_id' => $strategy->id,
            'version' => 1,
            'blueprint' => ['sections' => []],
            'lifecycle_status' => ContentBlueprintVersion::STATUS_CONFIRMED,
        ]);

        return $brief;
    }

    private function productionPreset(string $presetKey): PromptPresetVersion
    {
        $existing = PromptPreset::query()->where('preset_key', $presetKey)->first();
        if ($existing !== null) {
            return $existing->versions()->where('lifecycle_status', PromptPresetVersion::STATUS_PRODUCTION)->sole();
        }

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

        return $version;
    }
}
