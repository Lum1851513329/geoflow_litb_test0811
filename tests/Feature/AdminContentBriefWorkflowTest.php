<?php

namespace Tests\Feature;

use App\Domain\ContentGeneration\ContentGenerationRequestService;
use App\Http\Controllers\Admin\ContentGenerationController;
use App\Http\Requests\Admin\RequestContentGenerationRequest;
use App\Jobs\GenerateStrategyContent;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\ContentBrief;
use App\Models\ContentGenerationRun;
use App\Models\ContentGenerationRunEvidence;
use App\Models\ContentGenerationRunModule;
use App\Models\ContentGenerationRunStep;
use App\Models\ContentQualityReport;
use App\Models\ContentStrategyVersion;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\PromptModule;
use App\Models\PromptModuleVersion;
use App\Models\PromptPreset;
use App\Models\PromptPresetModuleVersion;
use App\Models\PromptPresetVersion;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AdminContentBriefWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Admin $operator;

    private Admin $superAdmin;

    private PromptModuleVersion $moduleVersion;

    private PromptPresetVersion $presetVersion;

    private AiModel $model;

    private Author $author;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
        config()->set([
            'geoflow.content_strategy_enabled' => true,
            'geoflow.modular_prompts_enabled' => true,
            'geoflow.generation_quality_gate_enabled' => true,
        ]);
        $this->createActivityLogTable();
        Queue::fake();

        $this->operator = $this->admin('content-operator', 'content_operator');
        $this->superAdmin = $this->admin('content-root', 'super_admin');
        [$this->moduleVersion, $this->presetVersion] = $this->promptConfiguration();
        $this->model = AiModel::query()->create([
            'name' => 'Editorial model',
            'version' => '2026-08',
            'api_key' => 'test-key',
            'api_url' => 'https://model.example.test/v1',
            'model_id' => 'editorial-model',
            'status' => 'active',
            'max_tokens' => 4096,
        ]);
        $this->author = Author::query()->create([
            'name' => 'Editorial team',
            'email' => 'editorial@example.test',
        ]);
        $this->category = Category::query()->create([
            'name' => 'Guides',
            'slug' => 'guides',
        ]);
    }

    public function test_content_strategy_routes_are_hidden_before_authentication_when_disabled(): void
    {
        config()->set('geoflow.content_strategy_enabled', false);

        $this->get($this->adminPath('content-briefs'))->assertNotFound();
        $this->post($this->adminPath('content-briefs'), $this->briefPayload())->assertNotFound();
    }

    public function test_enabled_content_strategy_routes_require_admin_authentication(): void
    {
        $this->get($this->adminPath('content-briefs'))->assertRedirect($this->adminPath('login'));
    }

    public function test_ordinary_admins_only_see_and_mutate_their_own_briefs_and_descendant_runs(): void
    {
        $brief = $this->createBriefThroughHttp();
        $this->confirmStrategyThroughHttp($brief);
        $this->confirmBlueprintThroughHttp($brief);
        $this->requestGenerationThroughHttp($brief);
        $run = ContentGenerationRun::query()->where('content_brief_id', $brief->id)->sole();
        $otherOperator = $this->admin('other-content-operator', 'content_operator');
        $otherBrief = $this->createBriefThroughHttp($otherOperator, [
            ...$this->briefPayload(),
            'topic' => 'Private competitor brief',
            'core_question' => 'What should only the other operator see?',
        ]);

        $this->actingAs($this->operator, 'admin')
            ->withSession(['locale' => 'en'])
            ->get($this->adminPath('content-briefs'))
            ->assertOk()
            ->assertSee($brief->topic)
            ->assertDontSee($otherBrief->topic);

        foreach ([
            "content-briefs/{$otherBrief->id}/strategy",
            "content-briefs/{$otherBrief->id}/blueprint",
            "content-briefs/{$otherBrief->id}",
        ] as $path) {
            $this->actingAs($this->operator, 'admin')->get($this->adminPath($path))->assertForbidden();
        }

        $this->actingAs($otherOperator, 'admin')->post(
            $this->adminPath("content-briefs/{$brief->id}/strategy"),
            ['strategy' => ['primary_intent' => 'informational']],
        )->assertForbidden();
        $this->actingAs($otherOperator, 'admin')->post(
            $this->adminPath("content-briefs/{$brief->id}/blueprint"),
            ['blueprint' => $this->blueprint()],
        )->assertForbidden();
        $this->actingAs($otherOperator, 'admin')->post(
            $this->adminPath("content-briefs/{$brief->id}/generations"),
            $this->generationPayload($brief, 'cross-operator-generation'),
        )->assertForbidden();
        $this->actingAs($otherOperator, 'admin')->get(
            $this->adminPath("content-generations/{$run->id}"),
        )->assertForbidden();
        $this->actingAs($otherOperator, 'admin')->post(
            $this->adminPath("content-generations/{$run->id}/adopt"),
            [],
        )->assertForbidden();

        $this->actingAs($this->superAdmin, 'admin')->get(
            $this->adminPath("content-briefs/{$otherBrief->id}/strategy"),
        )->assertOk();
        $this->actingAs($this->superAdmin, 'admin')
            ->withSession(['locale' => 'en'])
            ->get($this->adminPath('content-briefs'))
            ->assertOk()
            ->assertSee($brief->topic)
            ->assertSee($otherBrief->topic);
        $this->actingAs($this->superAdmin, 'admin')->get(
            $this->adminPath("content-generations/{$run->id}"),
        )->assertOk();
    }

    public function test_brief_validation_preserves_market_and_language_fields(): void
    {
        $this->actingAs($this->operator, 'admin')->post($this->adminPath('content-briefs'), [
            ...$this->briefPayload(),
            'market_code' => '',
            'language_code' => '',
        ])->assertSessionHasErrors(['market_code', 'language_code']);

        $this->actingAs($this->operator, 'admin')->post(
            $this->adminPath('content-briefs'),
            $this->briefPayload(),
        )->assertRedirect();

        $this->assertDatabaseHas('content_briefs', [
            'topic' => 'Running shoe fit',
            'market_code' => 'US',
            'language_code' => 'en',
            'created_by_admin_id' => $this->operator->id,
        ]);

        $this->actingAs($this->operator, 'admin')->post($this->adminPath('content-briefs'), [
            ...$this->briefPayload(),
            'topic' => 'Canonical locale tags',
            'market_code' => 'cn',
            'language_code' => 'ZH_hANS_cn',
        ])->assertRedirect();
        $this->assertDatabaseHas('content_briefs', [
            'topic' => 'Canonical locale tags',
            'market_code' => 'CN',
            'language_code' => 'zh-Hans-CN',
        ]);
    }

    public function test_low_confidence_intent_correction_requires_an_operator_reason(): void
    {
        $brief = $this->createBriefThroughHttp();

        $this->actingAs($this->operator, 'admin')->post(
            $this->adminPath("content-briefs/{$brief->id}/strategy"),
            ['strategy' => ['primary_intent' => 'commercial'], 'correction_reason' => ''],
        )->assertSessionHasErrors('correction_reason');

        $this->assertSame(0, $brief->strategyVersions()->count());

        $this->actingAs($this->operator, 'admin')->post(
            $this->adminPath("content-briefs/{$brief->id}/strategy"),
            [
                'strategy' => ['primary_intent' => 'commercial'],
                'correction_reason' => 'The brief is intended to support a product comparison decision.',
            ],
        )->assertRedirectContains($this->adminPath("content-briefs/{$brief->id}/blueprint"));

        $strategy = $brief->strategyVersions()->sole();
        $this->assertSame('commercial', $strategy->strategy['primary_intent']);
        $this->assertSame(
            'The brief is intended to support a product comparison decision.',
            $strategy->suggestion_snapshot['correction_reason'],
        );
    }

    public function test_blueprint_validation_errors_return_to_the_blueprint_step(): void
    {
        $brief = $this->createBriefThroughHttp();
        $this->confirmStrategyThroughHttp($brief);

        $this->actingAs($this->operator, 'admin')->post(
            $this->adminPath("content-briefs/{$brief->id}/blueprint"),
            ['blueprint' => ['working_title' => '', 'target_length' => 0, 'sections' => []]],
        )->assertSessionHasErrors([
            'working_title.required',
            'target_length.positive',
            'sections.required',
        ]);

        $this->assertSame(0, $brief->blueprintVersions()->count());
    }

    public function test_operator_can_complete_brief_to_generation_request_workflow(): void
    {
        $brief = $this->createBriefThroughHttp();
        $this->confirmStrategyThroughHttp($brief);
        $this->confirmBlueprintThroughHttp($brief);

        $review = $this->actingAs($this->operator, 'admin')->get(
            $this->adminPath("content-briefs/{$brief->id}"),
        );
        $review->assertOk()
            ->assertSee('Safety foundation')
            ->assertSee('v1')
            ->assertSee('data-advanced-module-configuration', false);

        $response = $this->requestGenerationThroughHttp($brief);

        $run = ContentGenerationRun::query()->where('content_brief_id', $brief->id)->sole();
        $response->assertRedirect($this->adminPath("content-generations/{$run->id}"));
        $this->assertDatabaseHas('content_generation_runs', [
            'content_brief_id' => $brief->id,
            'strategy_version_id' => $brief->strategyVersions()->sole()->id,
            'blueprint_version_id' => $brief->blueprintVersions()->sole()->id,
            'preset_version_id' => $this->presetVersion->id,
            'ai_model_id' => $this->model->id,
            'status' => ContentGenerationRun::STATUS_PENDING,
        ]);
        Queue::assertPushed(
            GenerateStrategyContent::class,
            fn (GenerateStrategyContent $job): bool => $job->runId === $run->id,
        );
    }

    public function test_generation_request_rejects_an_unavailable_required_module(): void
    {
        $brief = $this->createBriefThroughHttp();
        $this->confirmStrategyThroughHttp($brief);
        $this->confirmBlueprintThroughHttp($brief);
        $this->moduleVersion->module->forceFill(['is_enabled' => false])->save();

        $this->requestGenerationThroughHttp($brief)
            ->assertSessionHasErrors('preset_version_id');

        $this->assertSame(0, ContentGenerationRun::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_reservation_rejects_a_model_disabled_after_request_validation(): void
    {
        $brief = $this->createBriefThroughHttp();
        $this->confirmStrategyThroughHttp($brief);
        $this->confirmBlueprintThroughHttp($brief);
        $request = $this->validatedGenerationRequest($brief, 'model-disabled-after-validation');
        $this->model->forceFill(['status' => 'inactive'])->save();

        try {
            app(ContentGenerationController::class)->store($request, $brief);
            $this->fail('Reservation accepted a model that became inactive after request validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('ai_model_id', $exception->errors());
        }

        $this->assertSame(0, ContentGenerationRun::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_reservation_rejects_a_required_module_disabled_after_request_validation(): void
    {
        $brief = $this->createBriefThroughHttp();
        $this->confirmStrategyThroughHttp($brief);
        $this->confirmBlueprintThroughHttp($brief);
        $request = $this->validatedGenerationRequest($brief, 'module-disabled-after-validation');
        $this->moduleVersion->module->forceFill(['is_enabled' => false])->save();

        try {
            app(ContentGenerationController::class)->store($request, $brief);
            $this->fail('Reservation accepted a required module that became unavailable after request validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('preset_version_id', $exception->errors());
        }

        $this->assertSame(0, ContentGenerationRun::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_generation_request_requires_the_enabled_presets_current_version(): void
    {
        $brief = $this->createBriefThroughHttp();
        $this->confirmStrategyThroughHttp($brief);
        $this->confirmBlueprintThroughHttp($brief);
        $oldVersion = $this->presetVersion;
        $currentVersion = PromptPresetVersion::forceCreate([
            'prompt_preset_id' => $oldVersion->prompt_preset_id,
            'version' => 2,
            'matching_rules' => ['content_type' => 'guide', 'market_code' => 'US'],
            'generation_policy' => ['max_tokens' => 4096],
            'conflict_policy' => [],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
            'created_by_admin_id' => $this->superAdmin->id,
        ]);
        PromptPresetModuleVersion::query()->create([
            'prompt_preset_version_id' => $currentVersion->id,
            'prompt_module_version_id' => $this->moduleVersion->id,
            'sort_order' => 1,
            'is_required' => true,
        ]);
        $oldVersion->preset->forceFill(['current_version_id' => $currentVersion->id])->save();

        $this->actingAs($this->operator, 'admin')->post(
            $this->adminPath("content-briefs/{$brief->id}/generations"),
            [
                ...$this->generationPayload($brief, 'stale-preset-version'),
                'preset_version_id' => $oldVersion->id,
            ],
        )->assertSessionHasErrors('preset_version_id');

        $this->assertSame(0, ContentGenerationRun::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_generation_ui_and_post_use_the_same_exact_preset_context_match(): void
    {
        $brief = $this->createBriefThroughHttp();
        $this->confirmStrategyThroughHttp($brief);
        $this->confirmBlueprintThroughHttp($brief);
        $mismatchedVersion = PromptPresetVersion::forceCreate([
            'prompt_preset_id' => $this->presetVersion->prompt_preset_id,
            'version' => 2,
            'matching_rules' => ['content_type' => 'guide', 'market_code' => 'GB'],
            'generation_policy' => ['max_tokens' => 4096],
            'conflict_policy' => [],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
            'created_by_admin_id' => $this->superAdmin->id,
        ]);
        $this->presetVersion->preset->forceFill(['current_version_id' => $mismatchedVersion->id])->save();

        $this->actingAs($this->operator, 'admin')->get(
            $this->adminPath("content-briefs/{$brief->id}"),
        )->assertOk()->assertDontSee('value="'.$mismatchedVersion->id.'"', false);

        $this->actingAs($this->operator, 'admin')->post(
            $this->adminPath("content-briefs/{$brief->id}/generations"),
            [
                ...$this->generationPayload($brief, 'mismatched-preset-context'),
                'preset_version_id' => $mismatchedVersion->id,
            ],
        )->assertSessionHasErrors('preset_version_id');

        $this->assertSame(0, ContentGenerationRun::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_generation_rejects_a_current_preset_outside_production_like_lifecycles(): void
    {
        $brief = $this->createBriefThroughHttp();
        $this->confirmStrategyThroughHttp($brief);
        $this->confirmBlueprintThroughHttp($brief);
        $testVersion = PromptPresetVersion::forceCreate([
            'prompt_preset_id' => $this->presetVersion->prompt_preset_id,
            'version' => 2,
            'matching_rules' => ['content_type' => 'guide', 'market_code' => 'US'],
            'generation_policy' => ['max_tokens' => 4096],
            'conflict_policy' => [],
            'lifecycle_status' => PromptPresetVersion::STATUS_TEST,
            'created_by_admin_id' => $this->superAdmin->id,
        ]);
        $this->presetVersion->preset->forceFill(['current_version_id' => $testVersion->id])->save();

        $this->actingAs($this->operator, 'admin')->post(
            $this->adminPath("content-briefs/{$brief->id}/generations"),
            [
                ...$this->generationPayload($brief, 'test-lifecycle-preset'),
                'preset_version_id' => $testVersion->id,
            ],
        )->assertSessionHasErrors('preset_version_id');

        $this->assertSame(0, ContentGenerationRun::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_exact_idempotent_replay_returns_the_same_run_without_duplicate_dispatch(): void
    {
        $brief = $this->createBriefThroughHttp();
        $this->confirmStrategyThroughHttp($brief);
        $this->confirmBlueprintThroughHttp($brief);
        $key = 'exact-replay';

        $first = $this->requestGenerationThroughHttp($brief, $key);
        $run = ContentGenerationRun::query()->sole();
        $second = $this->requestGenerationThroughHttp($brief, $key);

        $first->assertRedirect($this->adminPath("content-generations/{$run->id}"));
        $second->assertRedirect($this->adminPath("content-generations/{$run->id}"));
        $this->assertSame(1, ContentGenerationRun::query()->count());
        Queue::assertPushed(GenerateStrategyContent::class, 1);
    }

    public function test_matching_pending_reservation_is_redispatched_after_the_original_dispatch_was_lost(): void
    {
        $brief = $this->createBriefThroughHttp();
        $this->confirmStrategyThroughHttp($brief);
        $this->confirmBlueprintThroughHttp($brief);
        $payload = $this->generationPayload($brief, 'lost-after-commit');
        $reservation = app(ContentGenerationRequestService::class)->reserve($brief, $payload, $this->operator);

        $this->assertTrue($reservation['created']);
        Queue::assertNothingPushed();

        $this->requestGenerationThroughHttp($brief, 'lost-after-commit')->assertRedirect();

        $this->assertSame(1, ContentGenerationRun::query()->count());
        Queue::assertPushed(GenerateStrategyContent::class, 1);
    }

    public function test_generation_rejects_a_preset_that_omits_an_applicable_mandatory_system_module(): void
    {
        $brief = $this->createBriefThroughHttp();
        $this->confirmStrategyThroughHttp($brief);
        $this->confirmBlueprintThroughHttp($brief);
        $module = PromptModule::query()->create([
            'module_key' => 'mandatory-market-policy',
            'display_name' => 'Mandatory market policy',
            'module_type' => PromptModule::TYPE_LANGUAGE_MARKET,
            'is_system' => true,
            'is_enabled' => true,
            'is_mandatory' => true,
            'applicability' => ['market_code' => ['US']],
            'owned_by_admin_id' => $this->superAdmin->id,
        ]);
        $version = PromptModuleVersion::forceCreate([
            'prompt_module_id' => $module->id,
            'version' => 1,
            'content' => 'Mandatory US market rules.',
            'variable_contract' => [],
            'content_sha256' => hash('sha256', 'Mandatory US market rules.'),
            'lifecycle_status' => PromptModuleVersion::STATUS_PRODUCTION,
            'created_by_admin_id' => $this->superAdmin->id,
        ]);
        $module->forceFill(['current_version_id' => $version->id])->save();

        $this->actingAs($this->operator, 'admin')->post(
            $this->adminPath("content-briefs/{$brief->id}/generations"),
            $this->generationPayload($brief, 'missing-mandatory-module'),
        )->assertSessionHasErrors('preset_version_id');

        $this->assertSame(0, ContentGenerationRun::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_generation_reservation_snapshots_explicit_reviewed_evidence_transactionally(): void
    {
        $brief = $this->createBriefThroughHttp();
        $this->confirmStrategyThroughHttp($brief);
        $blueprint = $this->blueprint();
        $blueprint['sections'][0]['required_fact_ids'] = ['fit-basics'];
        $this->actingAs($this->operator, 'admin')->post(
            $this->adminPath("content-briefs/{$brief->id}/blueprint"),
            ['blueprint' => $blueprint],
        )->assertRedirect($this->adminPath("content-briefs/{$brief->id}"));

        $this->actingAs($this->operator, 'admin')->post(
            $this->adminPath("content-briefs/{$brief->id}/generations"),
            [
                ...$this->generationPayload($brief, 'explicit-evidence'),
                'evidence' => [[
                    'fact_id' => 'fit-basics',
                    'title' => 'Operator-reviewed fit note',
                    'content' => 'A thumbnail width of toe room is a commonly used fit check.',
                    'source_url' => 'https://example.test/reviewed-fit-note',
                ]],
            ],
        )->assertRedirect();

        $run = ContentGenerationRun::query()->sole();
        $snapshot = $run->evidence()->sole();
        $this->assertSame('fit-basics', $snapshot->evidence_key);
        $this->assertTrue($snapshot->is_required);
        $this->assertSame('explicit', $snapshot->evidence_snapshot['source_type']);
        $this->assertSame($this->operator->id, $snapshot->evidence_snapshot['reviewed_by_admin_id']);
        $this->assertSame(
            'A thumbnail width of toe room is a commonly used fit check.',
            $snapshot->evidence_snapshot['content'],
        );
    }

    public function test_generation_reservation_snapshots_only_reviewed_knowledge_chunks(): void
    {
        $brief = $this->createBriefThroughHttp();
        $this->confirmStrategyThroughHttp($brief);
        $this->confirmBlueprintThroughHttp($brief);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => 'Reviewed fit handbook',
            'content' => 'Reviewed source content.',
            'review_status' => 'reviewed',
        ]);
        $chunk = KnowledgeChunk::query()->create([
            'knowledge_base_id' => $knowledgeBase->id,
            'chunk_index' => 0,
            'content' => 'Leave comfortable room in front of the longest toe.',
            'content_hash' => hash('sha256', 'reviewed-fit-chunk'),
            'chunk_title' => 'Toe room',
        ]);

        $this->actingAs($this->operator, 'admin')->post(
            $this->adminPath("content-briefs/{$brief->id}/generations"),
            [...$this->generationPayload($brief, 'reviewed-knowledge'), 'knowledge_chunk_ids' => [$chunk->id]],
        )->assertRedirect();

        $evidence = ContentGenerationRun::query()->sole()->evidence()->sole();
        $this->assertSame('knowledge-chunk:'.$chunk->id, $evidence->evidence_key);
        $this->assertSame($chunk->id, $evidence->knowledge_chunk_id);
        $this->assertSame('reviewed', $evidence->evidence_snapshot['knowledge_base_review_status']);
        $this->assertSame('Leave comfortable room in front of the longest toe.', $evidence->evidence_snapshot['content']);
    }

    public function test_exact_replay_returns_the_original_run_after_operational_configuration_changes(): void
    {
        $brief = $this->createBriefThroughHttp();
        $this->confirmStrategyThroughHttp($brief);
        $this->confirmBlueprintThroughHttp($brief);
        $key = 'exact-replay-after-config-change';
        $this->requestGenerationThroughHttp($brief, $key);
        $run = ContentGenerationRun::query()->sole();
        $replacement = PromptPresetVersion::forceCreate([
            'prompt_preset_id' => $this->presetVersion->prompt_preset_id,
            'version' => 2,
            'matching_rules' => ['content_type' => 'guide', 'market_code' => 'US'],
            'generation_policy' => ['max_tokens' => 4096],
            'conflict_policy' => [],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
            'created_by_admin_id' => $this->superAdmin->id,
        ]);
        $this->presetVersion->preset->forceFill(['current_version_id' => $replacement->id])->save();
        $this->model->forceFill(['status' => 'inactive'])->save();

        $this->requestGenerationThroughHttp($brief, $key)
            ->assertRedirect($this->adminPath("content-generations/{$run->id}"));

        $this->assertSame(1, ContentGenerationRun::query()->count());
        Queue::assertPushed(GenerateStrategyContent::class, 1);
    }

    public function test_idempotency_key_reuse_rejects_changed_evidence_payload(): void
    {
        $brief = $this->createBriefThroughHttp();
        $this->confirmStrategyThroughHttp($brief);
        $this->confirmBlueprintThroughHttp($brief);
        $payload = [
            ...$this->generationPayload($brief, 'changed-evidence'),
            'evidence' => [[
                'fact_id' => 'fit-basics',
                'content' => 'Reviewed evidence version one.',
            ]],
        ];

        $this->actingAs($this->operator, 'admin')->post(
            $this->adminPath("content-briefs/{$brief->id}/generations"),
            $payload,
        )->assertRedirect();
        $payload['evidence'][0]['content'] = 'A different reviewed evidence body.';

        $this->actingAs($this->operator, 'admin')->post(
            $this->adminPath("content-briefs/{$brief->id}/generations"),
            $payload,
        )->assertSessionHasErrors('idempotency_key');

        $this->assertSame(1, ContentGenerationRun::query()->count());
        $this->assertSame('Reviewed evidence version one.', ContentGenerationRun::query()->sole()->evidence()->sole()->evidence_snapshot['content']);
    }

    public function test_idempotency_key_reuse_rejects_changed_configuration_on_the_key_field(): void
    {
        $brief = $this->createBriefThroughHttp();
        $this->confirmStrategyThroughHttp($brief);
        $this->confirmBlueprintThroughHttp($brief);
        $key = 'changed-configuration';
        $this->requestGenerationThroughHttp($brief, $key);
        $otherModel = AiModel::query()->create([
            'name' => 'Alternative model',
            'version' => '2026-09',
            'api_key' => 'test-key',
            'api_url' => 'https://model.example.test/v1',
            'model_id' => 'alternative-model',
            'status' => 'active',
            'max_tokens' => 4096,
        ]);

        $this->actingAs($this->operator, 'admin')->post(
            $this->adminPath("content-briefs/{$brief->id}/generations"),
            [
                ...$this->generationPayload($brief, $key),
                'ai_model_id' => $otherModel->id,
            ],
        )->assertSessionHasErrors('idempotency_key');

        $this->assertSame(1, ContentGenerationRun::query()->count());
        Queue::assertPushed(GenerateStrategyContent::class, 1);
    }

    public function test_idempotency_key_is_bound_to_the_actor_even_for_a_super_admin(): void
    {
        $brief = $this->createBriefThroughHttp();
        $this->confirmStrategyThroughHttp($brief);
        $this->confirmBlueprintThroughHttp($brief);
        $key = 'actor-bound-replay';
        $this->requestGenerationThroughHttp($brief, $key);

        $this->actingAs($this->superAdmin, 'admin')->post(
            $this->adminPath("content-briefs/{$brief->id}/generations"),
            $this->generationPayload($brief, $key),
        )->assertSessionHasErrors('idempotency_key');

        $this->assertSame(1, ContentGenerationRun::query()->count());
        Queue::assertPushed(GenerateStrategyContent::class, 1);
    }

    public function test_global_idempotency_key_reuse_across_briefs_returns_a_validation_error(): void
    {
        $firstBrief = $this->createBriefThroughHttp();
        $this->confirmStrategyThroughHttp($firstBrief);
        $this->confirmBlueprintThroughHttp($firstBrief);
        $key = 'global-key-across-briefs';
        $this->requestGenerationThroughHttp($firstBrief, $key);
        $secondBrief = $this->createBriefThroughHttp();
        $this->confirmStrategyThroughHttp($secondBrief);
        $this->confirmBlueprintThroughHttp($secondBrief);

        $this->requestGenerationThroughHttp($secondBrief, $key)
            ->assertSessionHasErrors('idempotency_key');

        $this->assertSame(1, ContentGenerationRun::query()->count());
        Queue::assertPushed(GenerateStrategyContent::class, 1);
    }

    public function test_module_resolution_errors_are_reported_internally_but_rendered_generically(): void
    {
        $brief = $this->createBriefThroughHttp();
        $this->confirmStrategyThroughHttp($brief);
        $this->confirmBlueprintThroughHttp($brief);
        $this->moduleVersion->module->forceFill(['is_enabled' => false])->save();

        $this->actingAs($this->operator, 'admin')
            ->withSession(['locale' => 'en'])
            ->get($this->adminPath("content-briefs/{$brief->id}"))
            ->assertOk()
            ->assertSee('One or more required Prompt modules are unavailable for this brief.')
            ->assertDontSee('Required module safety-foundation is not available for this preset context.');
    }

    public function test_generation_request_uses_the_exact_strategy_and_blueprint_reviewed_by_the_operator(): void
    {
        $brief = $this->createBriefThroughHttp();
        $reviewedStrategy = $this->confirmStrategyThroughHttp($brief);
        $this->confirmBlueprintThroughHttp($brief);
        $reviewedBlueprint = $brief->blueprintVersions()->sole();

        $this->actingAs($this->operator, 'admin')->post(
            $this->adminPath("content-briefs/{$brief->id}/strategy"),
            [
                'strategy' => ['primary_intent' => 'informational'],
                'correction_reason' => '',
            ],
        )->assertRedirect();

        $response = $this->actingAs($this->operator, 'admin')->post(
            $this->adminPath("content-briefs/{$brief->id}/generations"),
            [
                'strategy_version_id' => $reviewedStrategy->id,
                'blueprint_version_id' => $reviewedBlueprint->id,
                'preset_version_id' => $this->presetVersion->id,
                'ai_model_id' => $this->model->id,
            ],
        );

        $response->assertRedirect();
        $run = ContentGenerationRun::query()->sole();
        $this->assertSame($reviewedStrategy->id, $run->strategy_version_id);
        $this->assertSame($reviewedBlueprint->id, $run->blueprint_version_id);
    }

    public function test_run_review_shows_trace_data_but_only_privileged_users_see_the_compiled_prompt(): void
    {
        $brief = $this->createBriefThroughHttp();
        $this->confirmStrategyThroughHttp($brief);
        $this->confirmBlueprintThroughHttp($brief);
        $this->requestGenerationThroughHttp($brief);
        $run = ContentGenerationRun::query()->where('content_brief_id', $brief->id)->sole();
        $digest = str_repeat('a', 64);
        $compiledPrompt = 'COMPILED-PROMPT-SECRET-731';
        $run->forceFill([
            'compiled_prompt' => $compiledPrompt,
            'variables_snapshot' => [
                'context_digest' => $digest,
                'conflict_decisions' => [[
                    'code' => 'cta_disabled_by_hard_constraint',
                    'winner' => 'cta_allowed=false',
                    'loser' => 'requested_cta=strong',
                    'reason' => 'Hard constraints win.',
                    'affected_module_version_ids' => [$this->moduleVersion->id],
                ]],
            ],
            'model_snapshot' => ['name' => 'Editorial model', 'version' => '2026-08', 'model_id' => 'editorial-model'],
            'generation_parameters' => ['max_tokens' => 4096],
            'raw_output' => "## Fit\nChoose shoes with enough toe room.",
            'status' => ContentGenerationRun::STATUS_READY_FOR_REVIEW,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ])->save();
        ContentGenerationRunModule::query()->create([
            'content_generation_run_id' => $run->id,
            'prompt_module_version_id' => $this->moduleVersion->id,
            'sort_order' => 1,
            'is_required' => true,
            'module_snapshot' => [
                'module_key' => 'safety-foundation',
                'display_name' => 'Safety foundation',
                'version' => 1,
                'version_id' => $this->moduleVersion->id,
            ],
        ]);
        ContentGenerationRunEvidence::query()->create([
            'content_generation_run_id' => $run->id,
            'evidence_key' => 'fit-research-1',
            'evidence_snapshot' => [
                'title' => 'Fit research',
                'content' => 'Leave enough space in the toe box.',
                'source_url' => 'https://example.test/fit',
            ],
            'is_required' => true,
        ]);
        ContentGenerationRunEvidence::query()->create([
            'content_generation_run_id' => $run->id,
            'evidence_key' => 'unsafe-research',
            'evidence_snapshot' => [
                'title' => '<script>alert("evidence-title")</script>',
                'content' => 'Unsafe schemes must remain inert.',
                'source_url' => 'javascript:alert("evidence-url")',
            ],
            'is_required' => false,
        ]);
        $step = ContentGenerationRunStep::query()->create([
            'content_generation_run_id' => $run->id,
            'sequence' => 1,
            'step_type' => ContentGenerationRunStep::TYPE_TARGETED_REVISION,
            'status' => ContentGenerationRunStep::STATUS_COMPLETED,
            'input_tokens' => 120,
            'output_tokens' => 340,
            'cost' => 0.042,
            'metadata' => ['revision' => 1],
        ]);
        ContentQualityReport::query()->create([
            'content_generation_run_id' => $run->id,
            'content_generation_run_step_id' => $step->id,
            'report_type' => 'semantic',
            'status' => ContentQualityReport::STATUS_NEEDS_REVISION,
            'passed' => false,
            'score' => 72.5,
            'issues' => [[
                'code' => 'missing_support',
                'message' => 'Support the cushioning claim.',
                'repair_instruction' => 'Add a source or remove the claim.',
            ]],
        ]);
        $futureStep = ContentGenerationRunStep::query()->create([
            'content_generation_run_id' => $run->id,
            'sequence' => 2,
            'step_type' => 'future_revision_type',
            'status' => 'future_step_status',
        ]);
        ContentQualityReport::query()->create([
            'content_generation_run_id' => $run->id,
            'content_generation_run_step_id' => $futureStep->id,
            'report_type' => 'future_report_type',
            'status' => 'future_report_status',
            'passed' => false,
            'issues' => [],
        ]);

        $operatorView = $this->actingAs($this->operator, 'admin')
            ->withSession(['locale' => 'en'])
            ->get($this->adminPath("content-generations/{$run->id}"));
        $operatorView->assertOk()
            ->assertSee('Safety foundation')
            ->assertSee($digest)
            ->assertSee('Hard constraints win.')
            ->assertSee('Fit research')
            ->assertSee('Leave enough space in the toe box.')
            ->assertSee('Support the cushioning claim.')
            ->assertSee('editorial-model')
            ->assertSee('Semantic quality')
            ->assertSee('Needs revision')
            ->assertSee('Targeted revision')
            ->assertSee('Completed')
            ->assertSee('future_revision_type')
            ->assertSee('future_step_status')
            ->assertSee('future_report_type')
            ->assertSee('future_report_status')
            ->assertSee('href="https://example.test/fit"', false)
            ->assertDontSee('href="javascript:', false)
            ->assertDontSee('<script>alert("evidence-title")</script>', false)
            ->assertDontSee($compiledPrompt);

        $this->actingAs($this->operator, 'admin')
            ->withSession(['locale' => 'zh_CN'])
            ->get($this->adminPath("content-generations/{$run->id}"))
            ->assertOk()
            ->assertSee('语义质量')
            ->assertSee('需要修订')
            ->assertSee('定向修订')
            ->assertSee('已完成');

        $this->actingAs($this->operator, 'admin')
            ->withSession(['locale' => 'en'])
            ->get($this->adminPath("content-briefs/{$brief->id}"))
            ->assertOk()
            ->assertSee('Ready for review')
            ->assertDontSee('ready_for_review');

        $this->actingAs($this->superAdmin, 'admin')->get(
            $this->adminPath("content-generations/{$run->id}"),
        )->assertOk()->assertSee($compiledPrompt);
    }

    public function test_generation_history_is_paginated_and_searches_quality_issue_text(): void
    {
        $brief = $this->createBriefThroughHttp();
        $this->confirmStrategyThroughHttp($brief);
        $this->confirmBlueprintThroughHttp($brief);
        $payload = $this->generationPayload($brief);
        $runs = collect(range(1, 22))->map(fn (int $index): ContentGenerationRun => ContentGenerationRun::forceCreate([
            'content_brief_id' => $brief->id,
            'strategy_version_id' => $payload['strategy_version_id'],
            'blueprint_version_id' => $payload['blueprint_version_id'],
            'preset_version_id' => $payload['preset_version_id'],
            'ai_model_id' => $payload['ai_model_id'],
            'compiled_prompt' => null,
            'status' => ContentGenerationRun::STATUS_PENDING,
            'idempotency_key' => 'history-'.$index,
            'created_by_admin_id' => $this->operator->id,
        ]));
        $oldest = $runs->first();
        ContentQualityReport::query()->create([
            'content_generation_run_id' => $oldest->id,
            'report_type' => 'deterministic',
            'status' => ContentQualityReport::STATUS_NEEDS_REVISION,
            'passed' => false,
            'issues' => [['code' => 'rare-audit-needle', 'message' => 'Rare audit needle']],
        ]);

        $this->actingAs($this->operator, 'admin')->get($this->adminPath("content-briefs/{$brief->id}"))
            ->assertOk()
            ->assertSee('href="'.route('admin.content-generations.show', $runs->last()).'"', false)
            ->assertDontSee('href="'.route('admin.content-generations.show', $oldest).'"', false);
        $this->actingAs($this->operator, 'admin')->get($this->adminPath("content-briefs/{$brief->id}?runs_page=2"))
            ->assertOk()
            ->assertSee('href="'.route('admin.content-generations.show', $oldest).'"', false);
        $this->actingAs($this->operator, 'admin')->get($this->adminPath("content-briefs/{$brief->id}?run_q=rare-audit-needle"))
            ->assertOk()
            ->assertSee('href="'.route('admin.content-generations.show', $oldest).'"', false)
            ->assertDontSee('href="'.route('admin.content-generations.show', $runs->last()).'"', false);
    }

    public function test_ready_run_can_be_adopted_once_into_the_article_workflow(): void
    {
        $brief = $this->createBriefThroughHttp();
        $this->confirmStrategyThroughHttp($brief);
        $this->confirmBlueprintThroughHttp($brief);
        $this->requestGenerationThroughHttp($brief);
        $run = ContentGenerationRun::query()->where('content_brief_id', $brief->id)->sole();
        $run->forceFill([
            'compiled_prompt' => 'Write a safe guide.',
            'variables_snapshot' => ['context_digest' => str_repeat('b', 64)],
            'model_snapshot' => ['model_id' => 'editorial-model'],
            'generation_parameters' => [],
            'raw_output' => "## Fit\nChoose shoes with enough toe room.",
            'status' => ContentGenerationRun::STATUS_READY_FOR_REVIEW,
        ])->save();
        $payload = [
            'title' => 'Generated running shoe guide',
            'excerpt' => 'A practical fit guide.',
            'keywords' => 'running shoes, fit',
            'meta_description' => 'How to choose running shoes that fit.',
            'category_id' => $this->category->id,
            'author_id' => $this->author->id,
        ];

        $first = $this->actingAs($this->operator, 'admin')->post(
            $this->adminPath("content-generations/{$run->id}/adopt"),
            $payload,
        );
        $article = Article::query()->sole();
        $first->assertRedirect($this->adminPath("articles/{$article->id}/edit"));

        $this->actingAs($this->operator, 'admin')->post(
            $this->adminPath("content-generations/{$run->id}/adopt"),
            $payload,
        )->assertRedirect($this->adminPath("articles/{$article->id}/edit"));

        $this->assertSame(1, Article::query()->count());
        $this->assertSame(ContentGenerationRun::STATUS_ADOPTED, $run->fresh()->status);
        $this->assertSame('draft', $article->fresh()->status);
        $this->assertSame('pending', $article->fresh()->review_status);
    }

    /** @param array<string, mixed>|null $payload */
    private function createBriefThroughHttp(?Admin $operator = null, ?array $payload = null): ContentBrief
    {
        $this->actingAs($operator ?? $this->operator, 'admin')->post(
            $this->adminPath('content-briefs'),
            $payload ?? $this->briefPayload(),
        )->assertRedirect();

        return ContentBrief::query()->latest('id')->firstOrFail();
    }

    private function confirmStrategyThroughHttp(ContentBrief $brief): ContentStrategyVersion
    {
        $this->actingAs($this->operator, 'admin')->post(
            $this->adminPath("content-briefs/{$brief->id}/strategy"),
            [
                'strategy' => ['primary_intent' => 'informational'],
                'correction_reason' => '',
            ],
        )->assertRedirectContains($this->adminPath("content-briefs/{$brief->id}/blueprint"));

        return $brief->strategyVersions()->latest('version')->firstOrFail();
    }

    private function confirmBlueprintThroughHttp(ContentBrief $brief): void
    {
        $this->actingAs($this->operator, 'admin')->post(
            $this->adminPath("content-briefs/{$brief->id}/blueprint"),
            ['blueprint' => $this->blueprint()],
        )->assertRedirect($this->adminPath("content-briefs/{$brief->id}"));
    }

    private function requestGenerationThroughHttp(ContentBrief $brief, ?string $idempotencyKey = null)
    {
        return $this->actingAs($this->operator, 'admin')->post(
            $this->adminPath("content-briefs/{$brief->id}/generations"),
            $this->generationPayload($brief, $idempotencyKey),
        );
    }

    private function validatedGenerationRequest(
        ContentBrief $brief,
        string $idempotencyKey,
    ): RequestContentGenerationRequest {
        $request = RequestContentGenerationRequest::create(
            $this->adminPath("content-briefs/{$brief->id}/generations"),
            'POST',
            $this->generationPayload($brief, $idempotencyKey),
        );
        $route = new Route('POST', 'content-briefs/{brief}/generations', static fn (): null => null);
        $route->bind($request);
        $route->setParameter('brief', $brief);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make('redirect'));
        $request->setRouteResolver(static fn (): Route => $route);
        $request->setUserResolver(fn (?string $guard = null): ?Admin => $guard === null || $guard === 'admin'
            ? $this->operator
            : null);
        $request->validateResolved();

        return $request;
    }

    /** @return array<string, int|string> */
    private function generationPayload(ContentBrief $brief, ?string $idempotencyKey = null): array
    {
        return [
            'strategy_version_id' => (int) $brief->strategyVersions()->latest('version')->value('id'),
            'blueprint_version_id' => (int) $brief->blueprintVersions()->latest('version')->value('id'),
            'preset_version_id' => $this->presetVersion->id,
            'ai_model_id' => $this->model->id,
            'idempotency_key' => $idempotencyKey ?? 'brief-'.$brief->id.'-'.str()->uuid(),
        ];
    }

    /** @return array<string, mixed> */
    private function briefPayload(): array
    {
        return [
            'topic' => 'Running shoe fit',
            'market_code' => 'US',
            'language_code' => 'en',
            'audience' => 'First-time runners',
            'core_question' => 'How should running shoes fit?',
            'business_goal' => 'Help readers choose a fit',
            'content_type' => 'guide',
            'target_url' => 'https://example.test/running-shoes',
            'required_mentions' => "toe room\nheel lock",
            'prohibited_information' => 'Unsupported medical claims',
            'reference_urls' => 'https://example.test/fit-research',
            'cta_strength' => 'soft',
        ];
    }

    /** @return array<string, mixed> */
    private function blueprint(): array
    {
        return [
            'working_title' => 'How running shoes should fit',
            'target_length' => 900,
            'sections' => [[
                'key' => 'fit-basics',
                'heading' => 'Fit basics',
                'purpose' => 'Explain the essential fit checks.',
                'questions_to_answer' => ['How much toe room is needed?'],
                'minimum_words' => 300,
                'cta_allowed' => false,
                'required_fact_ids' => [],
            ]],
        ];
    }

    /** @return array{PromptModuleVersion, PromptPresetVersion} */
    private function promptConfiguration(): array
    {
        $module = PromptModule::query()->create([
            'module_key' => 'safety-foundation',
            'display_name' => 'Safety foundation',
            'module_type' => PromptModule::TYPE_FOUNDATION,
            'description' => 'Core safety constraints.',
            'is_system' => true,
            'is_enabled' => true,
            'is_mandatory' => true,
            'default_priority' => 100,
            'owned_by_admin_id' => $this->superAdmin->id,
        ]);
        $moduleVersion = PromptModuleVersion::forceCreate([
            'prompt_module_id' => $module->id,
            'version' => 1,
            'content' => 'Write a safe guide for {{ audience }}.',
            'variable_contract' => ['variables' => ['audience' => ['type' => 'string', 'required' => true]]],
            'content_sha256' => hash('sha256', 'Write a safe guide for {{ audience }}.'),
            'lifecycle_status' => PromptModuleVersion::STATUS_PRODUCTION,
            'created_by_admin_id' => $this->superAdmin->id,
        ]);
        $module->forceFill(['current_version_id' => $moduleVersion->id])->save();

        $preset = PromptPreset::query()->create([
            'preset_key' => 'running-guide',
            'display_name' => 'Running guide',
            'is_enabled' => true,
            'owned_by_admin_id' => $this->superAdmin->id,
        ]);
        $presetVersion = PromptPresetVersion::forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => 1,
            'matching_rules' => ['content_type' => 'guide', 'market_code' => 'US'],
            'generation_policy' => ['max_tokens' => 4096],
            'conflict_policy' => [],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
            'created_by_admin_id' => $this->superAdmin->id,
        ]);
        PromptPresetModuleVersion::query()->create([
            'prompt_preset_version_id' => $presetVersion->id,
            'prompt_module_version_id' => $moduleVersion->id,
            'sort_order' => 1,
            'is_required' => true,
        ]);
        $preset->forceFill(['current_version_id' => $presetVersion->id])->save();

        return [$moduleVersion, $presetVersion];
    }

    private function admin(string $username, string $role): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.test',
            'display_name' => str($username)->headline(),
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function adminPath(string $path): string
    {
        $prefix = trim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');

        return '/'.$prefix.'/'.ltrim($path, '/');
    }

    private function createActivityLogTable(): void
    {
        if (Schema::hasTable('admin_activity_logs')) {
            return;
        }

        Schema::create('admin_activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('admin_username')->default('');
            $table->string('admin_role')->default('');
            $table->string('action');
            $table->string('request_method')->default('GET');
            $table->string('page')->default('');
            $table->string('target_type')->default('');
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('ip_address')->default('');
            $table->text('details')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }
}
