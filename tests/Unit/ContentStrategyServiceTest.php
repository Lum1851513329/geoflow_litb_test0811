<?php

namespace Tests\Unit;

use App\Domain\ContentStrategy\ContentStrategyService;
use App\Domain\ContentStrategy\StrategySuggestion;
use App\Models\Admin;
use App\Models\ContentBrief;
use App\Models\ContentBlueprintVersion;
use App\Models\ContentStrategyVersion;
use App\Models\Keyword;
use App\Models\KeywordCluster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ContentStrategyServiceTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    private ContentBrief $brief;

    private ContentStrategyService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::query()->create([
            'username' => 'content-strategy-admin',
            'password' => 'password',
            'email' => 'content-strategy-admin@example.test',
            'display_name' => 'Content Strategy Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $this->brief = ContentBrief::query()->create($this->briefAttributes());
        $this->service = app(ContentStrategyService::class);
    }

    public function test_suggestion_uses_loaded_cluster_values_and_keeps_its_evidence_separate(): void
    {
        $primaryKeyword = Keyword::make([
            'id' => 101,
            'keyword' => 'running shoes',
            'search_intent' => 'commercial',
        ]);
        $questionKeyword = Keyword::make([
            'id' => 102,
            'keyword' => 'what are the best running shoes',
            'keyword_type' => 'question',
        ]);
        $cluster = KeywordCluster::make([
            'id' => 201,
            'name' => 'Running shoes',
            'slug' => 'running-shoes',
            'search_intent' => 'commercial',
            'content_type' => 'comparison',
            'target_url' => 'https://example.test/running-shoes',
            'coverage_status' => 'partial',
        ]);
        $cluster->setRelation('primaryKeyword', $primaryKeyword);
        $cluster->setRelation('keywords', new Collection([$questionKeyword]));
        $brief = ContentBrief::make($this->briefAttributes());
        $brief->setRelation('keywordCluster', $cluster);

        $suggestion = $this->service->suggest($brief);

        $this->assertInstanceOf(StrategySuggestion::class, $suggestion);
        $this->assertSame('commercial', $suggestion->strategy['primary_intent']);
        $this->assertSame('comparison', $suggestion->strategy['content_type']);
        $this->assertSame('https://example.test/running-shoes', $suggestion->strategy['target_url']);
        $this->assertSame([
            'How should a new runner choose running shoes?',
            'What are the best running shoes?',
        ], $suggestion->strategy['questions_to_answer']);
        $this->assertContains([
            'field' => 'keyword_cluster.search_intent',
            'value' => 'commercial',
        ], $suggestion->evidence);
        $this->assertSame(0.9, $suggestion->confidence);
    }

    public function test_confirming_changed_strategy_creates_a_new_immutable_version(): void
    {
        $v1 = $this->service->confirmStrategy($this->brief, $this->strategy('informational'), $this->admin, 'Initial');
        $v2 = $this->service->confirmStrategy($this->brief, $this->strategy('commercial_investigation'), $this->admin, 'Operator correction');

        $this->assertSame(1, $v1->version);
        $this->assertSame(2, $v2->version);
        $this->assertSame('informational', $v1->fresh()->strategy['primary_intent']);
        $this->assertSame(ContentBrief::STATUS_STRATEGY_READY, $this->brief->fresh()->status);
    }

    public function test_optional_model_extension_enriches_the_deterministic_suggestion_without_replacing_its_snapshot(): void
    {
        Config::set('content_strategy.model_extension', static fn (): array => [
            'strategy' => ['secondary_intents' => ['transactional']],
            'confidence' => 0.8,
            'evidence' => [['field' => 'model.intent', 'value' => 'transactional']],
        ]);

        $suggestion = $this->service->suggest($this->brief);

        $this->assertSame(['transactional'], $suggestion->strategy['secondary_intents']);
        $this->assertSame(0.8, $suggestion->confidence);
        $this->assertSame([
            'strategy' => ['secondary_intents' => ['transactional']],
            'confidence' => 0.8,
            'evidence' => [['field' => 'model.intent', 'value' => 'transactional']],
        ], $suggestion->modelExtension);
        $this->assertContains(['field' => 'model.intent', 'value' => 'transactional'], $suggestion->evidence);
    }

    public function test_model_extension_cannot_override_cluster_derived_classification_fields(): void
    {
        Config::set('content_strategy.model_extension', static fn (): array => [
            'strategy' => [
                'primary_intent' => 'transactional',
                'success_criteria' => ['Visitors can compare suitable options.'],
            ],
        ]);

        $suggestion = $this->service->suggest($this->brief);

        $this->assertSame('informational', $suggestion->strategy['primary_intent']);
        $this->assertSame(['Visitors can compare suitable options.'], $suggestion->strategy['success_criteria']);
        $this->assertSame('transactional', $suggestion->modelExtension['strategy']['primary_intent']);
    }

    public function test_strategy_snapshot_keeps_suggestion_correction_reason_and_final_classification_distinct(): void
    {
        $version = $this->service->confirmStrategy(
            $this->brief,
            $this->strategy('commercial_investigation'),
            $this->admin,
            'Commercial intent better matches shoppers.',
        );

        $snapshot = $version->suggestion_snapshot;

        $this->assertArrayHasKey('system_suggestion', $snapshot);
        $this->assertArrayHasKey('confidence', $snapshot);
        $this->assertArrayHasKey('evidence', $snapshot);
        $this->assertSame(['primary_intent' => 'commercial_investigation'], $snapshot['operator_correction']);
        $this->assertSame('Commercial intent better matches shoppers.', $snapshot['correction_reason']);
        $this->assertSame('commercial_investigation', $snapshot['final_classification']['primary_intent']);
    }

    public function test_confirming_a_valid_blueprint_allocates_an_immutable_version_for_its_strategy(): void
    {
        $strategy = $this->service->confirmStrategy($this->brief, $this->strategy('informational'), $this->admin, 'Initial');

        $blueprint = $this->service->confirmBlueprint($this->brief, $strategy, $this->blueprint(), $this->admin);

        $this->assertSame(1, $blueprint->version);
        $this->assertSame($strategy->id, $blueprint->content_strategy_version_id);
        $this->assertSame(ContentBlueprintVersion::STATUS_CONFIRMED, $blueprint->lifecycle_status);
        $this->assertSame([], $blueprint->validation_report);
    }

    public function test_invalid_blueprint_is_rejected_before_a_version_is_persisted(): void
    {
        $strategy = $this->service->confirmStrategy($this->brief, $this->strategy('informational'), $this->admin, 'Initial');
        $invalid = $this->blueprint();
        $invalid['sections'][0]['purpose'] = '';

        try {
            $this->service->confirmBlueprint($this->brief, $strategy, $invalid, $this->admin);
            $this->fail('Expected an invalid blueprint to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sections.0.purpose.required', $exception->errors());
        }

        $this->assertDatabaseCount('content_blueprint_versions', 0);
    }

    /** @return array<string, mixed> */
    private function briefAttributes(): array
    {
        return [
            'topic' => 'Running shoes for new runners',
            'market_code' => 'US',
            'language_code' => 'en',
            'audience' => 'New runners',
            'core_question' => 'How should a new runner choose running shoes?',
            'business_goal' => 'Help shoppers choose a suitable first pair.',
            'content_type' => 'guide',
            'cta_strength' => 'soft',
            'status' => ContentBrief::STATUS_READY,
        ];
    }

    /** @return array<string, mixed> */
    private function strategy(string $intent): array
    {
        return ['primary_intent' => $intent];
    }

    /** @return array<string, mixed> */
    private function blueprint(): array
    {
        return [
            'working_title' => 'How to choose running shoes for new runners',
            'thesis' => 'Fit and intended use matter more than trend-driven features.',
            'audience' => 'New runners',
            'primary_question' => 'How should a new runner choose running shoes?',
            'target_length' => 900,
            'sections' => [
                [
                    'key' => 'fit-basics',
                    'heading' => 'Start with fit',
                    'purpose' => 'Explain the fitting criteria that make a first pair comfortable.',
                    'questions_to_answer' => ['How should running shoes fit?'],
                    'required_fact_ids' => ['knowledge-chunk:fit-basics'],
                    'minimum_words' => 350,
                    'cta_allowed' => false,
                ],
                [
                    'key' => 'choose-by-use',
                    'heading' => 'Match shoes to intended use',
                    'purpose' => 'Connect terrain and training goals to practical shoe choices.',
                    'questions_to_answer' => ['What shoes suit road running?'],
                    'required_fact_ids' => [],
                    'minimum_words' => 350,
                    'cta_allowed' => true,
                ],
            ],
            'required_entities' => ['LightInTheBox'],
            'faq_questions' => ['How often should running shoes be replaced?'],
        ];
    }
}
