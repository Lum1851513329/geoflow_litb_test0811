<?php

namespace Tests\Feature;

use App\Domain\ContentGeneration\ContentGenerationCoordinator;
use App\Events\ContentGenerationCompleted;
use App\Events\ContentGenerationFailed;
use App\Jobs\GenerateStrategyContent;
use App\Models\AiModel;
use App\Models\ContentBlueprintVersion;
use App\Models\ContentBrief;
use App\Models\ContentGenerationRun;
use App\Models\ContentGenerationRunEvidence;
use App\Models\ContentGenerationRunStep;
use App\Models\ContentQualityReport;
use App\Models\ContentStrategyVersion;
use App\Models\PromptModule;
use App\Models\PromptModuleVersion;
use App\Models\PromptPreset;
use App\Models\PromptPresetModuleVersion;
use App\Models\PromptPresetVersion;
use App\Services\GeoFlow\ArticleContentGenerationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class ContentGenerationCoordinatorTest extends TestCase
{
    use RefreshDatabase;

    private FakeArticleContentGenerationService $fakeGenerator;

    private ContentGenerationCoordinator $coordinator;

    public function refreshDatabase(): void
    {
        $this->artisan('migrate:fresh');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeGenerator = new FakeArticleContentGenerationService;
        $this->app->instance(ArticleContentGenerationService::class, $this->fakeGenerator);
        $this->coordinator = $this->app->make(ContentGenerationCoordinator::class);
    }

    public function test_generation_snapshots_inputs_before_calls_and_performs_only_one_targeted_revision(): void
    {
        Event::fake();
        $this->fakeGenerator->queue(
            '## Fit\nToo thin.',
            $this->substantiveArticle(),
            $this->semanticPass(),
        );
        $ambientTransactionLevel = DB::transactionLevel();

        $run = $this->coordinator->generate($this->pendingRun(withEvidence: true));

        $fresh = $run->fresh();
        $this->assertSame(ContentGenerationRun::STATUS_READY_FOR_REVIEW, $fresh->status);
        $this->assertNotNull($fresh->compiled_prompt);
        $this->assertSame(64, strlen((string) ($fresh->variables_snapshot['context_digest'] ?? '')));
        $this->assertStringNotContainsString('secret-campaign-value', json_encode($fresh->variables_snapshot, JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('[REDACTED]', json_encode($fresh->variables_snapshot, JSON_THROW_ON_ERROR));
        $this->assertSame('fake-model', $fresh->model_snapshot['model_id']);
        $this->assertStringNotContainsString('secret-never-snapshot', json_encode($fresh->model_snapshot, JSON_THROW_ON_ERROR));
        $this->assertCount(1, $fresh->modules()->get());
        $this->assertCount(1, $fresh->evidence()->get());
        $this->assertSame([true, true, true], $this->fakeGenerator->snapshotsPrepared);
        $this->assertCount(2, $fresh->steps()->whereIn('step_type', [
            ContentGenerationRunStep::TYPE_INITIAL_GENERATION,
            ContentGenerationRunStep::TYPE_TARGETED_REVISION,
        ])->get());
        $this->assertSame(array_fill(0, 3, $ambientTransactionLevel), $this->fakeGenerator->transactionLevels);

        $revisionPrompt = $fresh->steps()
            ->where('step_type', ContentGenerationRunStep::TYPE_TARGETED_REVISION)
            ->sole()
            ->prompt;
        $this->assertStringContainsString('## Fit\\nToo thin.', $revisionPrompt);
        $this->assertStringContainsString('Add an H2 heading named \\"Fit\\"', $revisionPrompt);
        $this->assertStringNotContainsString('Rewrite the whole article', $revisionPrompt);
        $semanticPrompt = $fresh->steps()->where('step_type', ContentGenerationRunStep::TYPE_SEMANTIC_QUALITY)->sole()->prompt;
        $this->assertStringContainsString('Allow a thumb width in front of the longest toe.', $semanticPrompt);
        $this->assertStringContainsString('needs_evidence', $semanticPrompt);
        Event::assertDispatched(ContentGenerationCompleted::class, fn ($event): bool => $event->runId === $run->id);
    }

    public function test_duplicate_job_execution_is_idempotent_after_terminal_completion(): void
    {
        $this->fakeGenerator->queue($this->substantiveArticle(), $this->semanticPass());
        $run = $this->pendingRun(withEvidence: true);
        $job = new GenerateStrategyContent($run->id);

        $job->handle($this->coordinator);
        $job->handle($this->coordinator);

        $this->assertSame(ContentGenerationRun::STATUS_READY_FOR_REVIEW, $run->fresh()->status);
        $this->assertCount(2, $this->fakeGenerator->prompts);
        $this->assertSame($run->idempotency_key, $job->uniqueId());
        $this->assertSame(3, $job->tries);
    }

    public function test_initial_model_failure_is_sanitized_and_emits_failure_after_commit(): void
    {
        Event::fake();
        $this->fakeGenerator->queue(new RuntimeException(
            'Provider rejected Authorization: Bearer sk-live-secret and api_key=private-value',
        ));

        $run = $this->coordinator->generate($this->pendingRun(withEvidence: true))->fresh();

        $this->assertSame(ContentGenerationRun::STATUS_FAILED, $run->status);
        $this->assertStringNotContainsString('sk-live-secret', (string) $run->error_message);
        $this->assertStringNotContainsString('private-value', (string) $run->error_message);
        $this->assertSame(ContentGenerationRunStep::STATUS_FAILED, $run->steps()->sole()->status);
        Event::assertDispatched(ContentGenerationFailed::class, fn ($event): bool => $event->runId === $run->id);
    }

    public function test_empty_initial_output_fails_without_quality_or_revision_calls(): void
    {
        $this->fakeGenerator->queue(" \n\t ");

        $run = $this->coordinator->generate($this->pendingRun(withEvidence: true))->fresh();

        $this->assertSame(ContentGenerationRun::STATUS_FAILED, $run->status);
        $this->assertSame('The model returned an empty response.', $run->error_message);
        $this->assertCount(1, $this->fakeGenerator->prompts);
        $this->assertSame(0, $run->qualityReports()->count());
    }

    public function test_required_evidence_failure_stops_before_any_model_call(): void
    {
        $run = $this->pendingRun(evidenceRequired: true);

        $fresh = $this->coordinator->generate($run)->fresh();

        $this->assertSame(ContentGenerationRun::STATUS_NEEDS_EVIDENCE, $fresh->status);
        $this->assertSame([], $this->fakeGenerator->prompts);
        $report = $fresh->qualityReports()->sole();
        $this->assertSame(ContentQualityReport::STATUS_NEEDS_EVIDENCE, $report->status);
        $this->assertSame('needs_evidence', $report->issues[0]['code']);
    }

    public function test_required_evidence_uses_effective_post_budget_evidence(): void
    {
        $run = $this->pendingRun(withEvidence: true, evidenceRequired: true);
        $evidence = $run->evidence()->sole();
        $evidence->forceFill(['evidence_snapshot' => [
            'content' => str_repeat('low relevance background material ', 400),
            'relevance' => 0.1,
        ]])->save();
        $run->aiModel()->update(['max_tokens' => 3000]);

        $fresh = $this->coordinator->generate($run)->fresh();

        $this->assertSame(ContentGenerationRun::STATUS_NEEDS_EVIDENCE, $fresh->status);
        $this->assertSame([], $this->fakeGenerator->prompts);
        $this->assertSame([], $fresh->variables_snapshot['resolved_variables']['evidence']);
    }

    public function test_deterministic_pass_and_semantic_pass_need_no_revision(): void
    {
        $this->fakeGenerator->queue($this->substantiveArticle(), $this->semanticPass());

        $run = $this->coordinator->generate($this->pendingRun(withEvidence: true))->fresh();

        $this->assertSame(ContentGenerationRun::STATUS_READY_FOR_REVIEW, $run->status);
        $this->assertCount(2, $this->fakeGenerator->prompts);
        $this->assertSame(0, $run->steps()->where('step_type', ContentGenerationRunStep::TYPE_TARGETED_REVISION)->count());
        $this->assertSame(2, $run->qualityReports()->count());
    }

    public function test_semantic_review_parse_failure_retries_once_then_requires_manual_review(): void
    {
        $this->fakeGenerator->queue(
            $this->substantiveArticle(),
            'not json',
            '```json {"passed":true,"score":100,"issues":[]} ```',
        );

        $run = $this->coordinator->generate($this->pendingRun(withEvidence: true))->fresh();

        $this->assertSame(ContentGenerationRun::STATUS_MANUAL_REVIEW_REQUIRED, $run->status);
        $this->assertCount(3, $this->fakeGenerator->prompts);
        $this->assertCount(2, $run->steps()->where('step_type', ContentGenerationRunStep::TYPE_SEMANTIC_QUALITY)->get());
        $this->assertSame(ContentQualityReport::STATUS_MODEL_QUALITY_FAILED, $run->qualityReports()->latest('id')->first()->status);
    }

    public function test_second_quality_failure_requires_manual_review_without_second_revision(): void
    {
        $this->fakeGenerator->queue(
            '## Fit\nToo thin.',
            '## Fit\nStill thin.',
        );

        $run = $this->coordinator->generate($this->pendingRun(withEvidence: true))->fresh();

        $this->assertSame(ContentGenerationRun::STATUS_MANUAL_REVIEW_REQUIRED, $run->status);
        $this->assertCount(2, $this->fakeGenerator->prompts);
        $this->assertCount(1, $run->steps()->where('step_type', ContentGenerationRunStep::TYPE_TARGETED_REVISION)->get());
        $this->assertSame(ContentQualityReport::STATUS_MANUAL_REVIEW_REQUIRED, $run->qualityReports()->latest('id')->first()->status);
    }

    public function test_semantic_issue_drives_a_targeted_revision_and_preserves_strict_json_contract(): void
    {
        $this->fakeGenerator->queue(
            $this->substantiveArticle(),
            json_encode([
                'passed' => false,
                'score' => 72,
                'issues' => [[
                    'code' => 'audience_mismatch',
                    'section_key' => 'fit',
                    'message' => 'The advice assumes expert readers.',
                    'repair_instruction' => 'Explain the fit steps for first-time buyers.',
                ]],
            ], JSON_THROW_ON_ERROR),
            $this->substantiveArticle('First-time buyers can follow these measured steps.'),
            $this->semanticPass(),
        );

        $run = $this->coordinator->generate($this->pendingRun(withEvidence: true))->fresh();

        $this->assertSame(ContentGenerationRun::STATUS_READY_FOR_REVIEW, $run->status);
        $this->assertCount(1, $run->steps()->where('step_type', ContentGenerationRunStep::TYPE_TARGETED_REVISION)->get());
        $revisionPrompt = $run->steps()->where('step_type', ContentGenerationRunStep::TYPE_TARGETED_REVISION)->sole()->prompt;
        $this->assertStringContainsString('Explain the fit steps for first-time buyers.', $revisionPrompt);
        $this->assertStringNotContainsString('The advice assumes expert readers.', $revisionPrompt);
    }

    public function test_semantic_json_object_key_order_does_not_change_its_contract(): void
    {
        $this->fakeGenerator->queue(
            $this->substantiveArticle(),
            '{"score":96,"issues":[],"passed":true}',
        );

        $run = $this->coordinator->generate($this->pendingRun(withEvidence: true))->fresh();

        $this->assertSame(ContentGenerationRun::STATUS_READY_FOR_REVIEW, $run->status);
        $this->assertCount(2, $this->fakeGenerator->prompts);
    }

    public function test_semantic_unsupported_fact_stops_for_evidence_without_revision(): void
    {
        $this->fakeGenerator->queue(
            $this->substantiveArticle(),
            json_encode([
                'passed' => false,
                'score' => 65,
                'issues' => [[
                    'code' => 'unsupported_fact',
                    'section_key' => 'fit',
                    'message' => 'The cushioning claim is not supported by attached evidence.',
                    'repair_instruction' => 'Attach evidence for the cushioning claim or remove it.',
                ]],
            ], JSON_THROW_ON_ERROR),
        );

        $run = $this->coordinator->generate($this->pendingRun(withEvidence: true))->fresh();

        $this->assertSame(ContentGenerationRun::STATUS_NEEDS_EVIDENCE, $run->status);
        $this->assertSame(ContentQualityReport::STATUS_NEEDS_EVIDENCE, $run->qualityReports()->latest('id')->first()->status);
        $this->assertSame(0, $run->steps()->where('step_type', ContentGenerationRunStep::TYPE_TARGETED_REVISION)->count());
        $this->assertCount(2, $this->fakeGenerator->prompts);
    }

    public function test_ambient_transaction_prevents_model_call_before_snapshots_are_durable(): void
    {
        $run = $this->pendingRun(withEvidence: true);
        $this->fakeGenerator->queue($this->substantiveArticle(), $this->semanticPass());

        DB::beginTransaction();
        try {
            $inside = $this->coordinator->generate($run)->fresh();

            $this->assertSame(ContentGenerationRun::STATUS_FAILED, $inside->status);
            $this->assertStringContainsString('outside an open database transaction', (string) $inside->error_message);
            $this->assertSame([], $this->fakeGenerator->prompts);
        } finally {
            DB::rollBack();
        }

        $this->assertSame(ContentGenerationRun::STATUS_PENDING, $run->fresh()->status);
    }

    public function test_failed_initial_call_reuses_immutable_snapshots_on_idempotent_retry(): void
    {
        $run = $this->pendingRun(withEvidence: true);
        $this->fakeGenerator->queue(new RuntimeException('temporary provider outage'));
        $failed = $this->coordinator->generate($run)->fresh();
        $promptDigest = hash('sha256', (string) $failed->compiled_prompt);
        $this->assertSame(ContentGenerationRun::STATUS_FAILED, $failed->status);

        $this->fakeGenerator->queue($this->substantiveArticle(), $this->semanticPass());
        $retried = $this->coordinator->generate($failed)->fresh();

        $this->assertSame(ContentGenerationRun::STATUS_READY_FOR_REVIEW, $retried->status);
        $this->assertSame($promptDigest, hash('sha256', (string) $retried->compiled_prompt));
        $this->assertSame(1, $retried->modules()->count());
        $this->assertCount(2, $retried->steps()->where('step_type', ContentGenerationRunStep::TYPE_INITIAL_GENERATION)->get());
    }

    public function test_fresh_generating_claim_is_not_stolen_but_stale_claim_is_recovered(): void
    {
        $run = $this->pendingRun(withEvidence: true);
        $this->fakeGenerator->queue(new RuntimeException('worker interrupted'));
        $failed = $this->coordinator->generate($run)->fresh();
        $failed->forceFill(['status' => ContentGenerationRun::STATUS_GENERATING])->save();
        $this->fakeGenerator->queue($this->substantiveArticle(), $this->semanticPass());

        $freshClaim = $this->coordinator->generate($failed)->fresh();
        $this->assertSame(ContentGenerationRun::STATUS_GENERATING, $freshClaim->status);
        $this->assertCount(1, $this->fakeGenerator->prompts);

        DB::table('content_generation_runs')->where('id', $failed->id)->update(['updated_at' => now()->subMinutes(21)]);
        $recovered = $this->coordinator->generate($failed->fresh())->fresh();

        $this->assertSame(ContentGenerationRun::STATUS_READY_FOR_REVIEW, $recovered->status);
        $this->assertCount(3, $this->fakeGenerator->prompts);
    }

    public function test_queue_retry_releases_a_fresh_claim_until_it_can_be_recovered(): void
    {
        $run = $this->pendingRun(withEvidence: true);
        $this->fakeGenerator->queue(new RuntimeException('worker interrupted'));
        $failed = $this->coordinator->generate($run)->fresh();
        $failed->forceFill(['status' => ContentGenerationRun::STATUS_GENERATING])->save();
        $this->fakeGenerator->queue($this->substantiveArticle(), $this->semanticPass());
        $job = (new GenerateStrategyContent($run->id))->withFakeQueueInteractions();

        $job->handle($this->coordinator);

        $job->assertReleased(1205);
        $this->assertCount(1, $this->fakeGenerator->prompts);

        $this->travel(21)->minutes();
        $job->handle($this->coordinator);

        $this->assertSame(ContentGenerationRun::STATUS_READY_FOR_REVIEW, $run->fresh()->status);
        $this->assertCount(3, $this->fakeGenerator->prompts);
    }

    public function test_forward_snapshot_migration_preserves_guards_on_upgrade_and_rollback(): void
    {
        $run = $this->pendingRun();
        $migration = require database_path('migrations/2026_08_10_010300_prepare_content_generation_run_snapshots.php');

        $migration->down();
        $downColumns = collect(DB::select("PRAGMA table_info('content_generation_runs')"))->keyBy('name');
        $this->assertSame(1, (int) $downColumns['compiled_prompt']->notnull);
        $this->assertSame("'queued'", $downColumns['status']->dflt_value);
        $this->assertDatabaseRejects(fn () => ContentGenerationRun::query()->whereKey($run->id)->delete());

        $migration->up();
        $upColumns = collect(DB::select("PRAGMA table_info('content_generation_runs')"))->keyBy('name');
        $this->assertSame(0, (int) $upColumns['compiled_prompt']->notnull);
        $this->assertSame("'pending'", $upColumns['status']->dflt_value);
        $run->fresh()->forceFill(['variables_snapshot' => ['initialized' => true]])->save();
        $this->assertSame(['initialized' => true], $run->fresh()->variables_snapshot);
        $this->assertDatabaseRejects(fn () => ContentGenerationRun::query()->whereKey($run->id)->delete());
    }

    private function pendingRun(bool $withEvidence = false, bool $evidenceRequired = false): ContentGenerationRun
    {
        $brief = ContentBrief::query()->create([
            'topic' => 'Running shoe fit',
            'market_code' => 'US',
            'language_code' => 'en',
            'audience' => 'First-time runners',
            'core_question' => 'How should running shoes fit?',
            'business_goal' => 'Help readers choose a fit',
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
            'blueprint' => $this->blueprint(),
            'lifecycle_status' => ContentBlueprintVersion::STATUS_CONFIRMED,
        ]);
        $module = PromptModule::query()->create([
            'module_key' => 'fit-guide',
            'display_name' => 'Fit guide',
            'module_type' => PromptModule::TYPE_FOUNDATION,
            'default_priority' => 100,
        ]);
        $moduleVersion = PromptModuleVersion::forceCreate([
            'prompt_module_id' => $module->id,
            'version' => 1,
            'content' => 'Write a practical guide for {{ audience }}. Internal launch: {{ campaign_secret }}.',
            'variable_contract' => ['variables' => [
                'audience' => ['type' => 'string', 'required' => true],
                'campaign_secret' => ['type' => 'string', 'default' => 'secret-campaign-value', 'sensitive' => true],
            ]],
            'lifecycle_status' => PromptModuleVersion::STATUS_PRODUCTION,
        ]);
        $preset = PromptPreset::query()->create([
            'preset_key' => 'fit-guide',
            'display_name' => 'Fit guide',
        ]);
        $presetVersion = PromptPresetVersion::forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => 1,
            'matching_rules' => ['content_type' => 'guide'],
            'generation_policy' => ['evidence_required' => $evidenceRequired],
            'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
        ]);
        PromptPresetModuleVersion::query()->create([
            'prompt_preset_version_id' => $presetVersion->id,
            'prompt_module_version_id' => $moduleVersion->id,
            'sort_order' => 1,
            'is_required' => true,
        ]);
        $model = AiModel::query()->create([
            'name' => 'Fake model',
            'version' => '2026-08',
            'api_key' => 'secret-never-snapshot',
            'api_url' => 'https://model.example.test/v1',
            'model_id' => 'fake-model',
            'max_tokens' => 4096,
        ]);
        $run = ContentGenerationRun::forceCreate([
            'content_brief_id' => $brief->id,
            'strategy_version_id' => $strategy->id,
            'blueprint_version_id' => $blueprint->id,
            'preset_version_id' => $presetVersion->id,
            'ai_model_id' => $model->id,
            'compiled_prompt' => null,
            'status' => ContentGenerationRun::STATUS_PENDING,
            'idempotency_key' => 'generation-'.str()->uuid(),
        ]);

        if ($withEvidence) {
            ContentGenerationRunEvidence::query()->create([
                'content_generation_run_id' => $run->id,
                'evidence_key' => 'fit-source-1',
                'evidence_snapshot' => [
                    'title' => 'Fit research',
                    'content' => 'Allow a thumb width in front of the longest toe.',
                    'source_url' => 'https://example.test/fit',
                ],
                'is_required' => true,
            ]);
        }

        return $run;
    }

    /** @return array<string, mixed> */
    private function blueprint(): array
    {
        return [
            'working_title' => 'How running shoes should fit',
            'target_length' => 80,
            'sections' => [[
                'key' => 'fit',
                'heading' => 'Fit',
                'purpose' => 'Explain practical fit checks.',
                'questions_to_answer' => ['How should running shoes fit?'],
                'minimum_words' => 25,
                'cta_allowed' => false,
                'required_fact_ids' => [],
            ]],
        ];
    }

    private function substantiveArticle(string $extra = ''): string
    {
        return "## Fit\nHow should running shoes fit? Running shoes should hold the heel securely while leaving a thumb width ahead of the longest toe. The midfoot should feel supported without pressure, and toes should spread naturally. Walk and jog to check for slipping, rubbing, numbness, or pinching before choosing the size. {$extra}";
    }

    private function semanticPass(): string
    {
        return '{"passed":true,"score":96,"issues":[]}';
    }

    private function assertDatabaseRejects(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected the database to reject the mutation.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }
}

class FakeArticleContentGenerationService extends ArticleContentGenerationService
{
    /** @var list<string|Throwable> */
    private array $outputs = [];

    /** @var list<string> */
    public array $prompts = [];

    /** @var list<int> */
    public array $transactionLevels = [];

    /** @var list<bool> */
    public array $snapshotsPrepared = [];

    public function __construct() {}

    public function queue(string|Throwable ...$outputs): void
    {
        array_push($this->outputs, ...$outputs);
    }

    public function generate(AiModel $aiModel, string $prompt): AgentResponse
    {
        $this->prompts[] = $prompt;
        $this->transactionLevels[] = DB::transactionLevel();
        $run = ContentGenerationRun::query()->latest('id')->first();
        $this->snapshotsPrepared[] = $run !== null
            && $run->compiled_prompt !== null
            && $run->variables_snapshot !== null
            && $run->model_snapshot !== null
            && $run->modules()->exists();
        $output = array_shift($this->outputs);
        if ($output instanceof Throwable) {
            throw $output;
        }
        if (! is_string($output)) {
            throw new RuntimeException('Fake generator response queue exhausted.');
        }

        return new AgentResponse(
            'fake-invocation-'.count($this->prompts),
            $output,
            new Usage(promptTokens: 10, completionTokens: 20),
            new Meta('fake-provider', (string) $aiModel->model_id),
        );
    }

    public function maxTokens(AiModel $aiModel): int
    {
        return (int) ($aiModel->max_tokens ?: 4096);
    }
}
