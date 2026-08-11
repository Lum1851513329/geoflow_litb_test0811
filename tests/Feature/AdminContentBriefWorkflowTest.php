<?php

namespace Tests\Feature;

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
use App\Models\PromptModule;
use App\Models\PromptModuleVersion;
use App\Models\PromptPreset;
use App\Models\PromptPresetModuleVersion;
use App\Models\PromptPresetVersion;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
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
