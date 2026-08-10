<?php

namespace App\Domain\ContentGeneration;

use App\Domain\PromptComposition\PromptCompiler;
use App\Domain\PromptComposition\PromptContext;
use App\Domain\PromptComposition\PromptModuleResolver;
use App\Domain\PromptComposition\ResolvedPromptModule;
use App\Events\ContentGenerationCompleted;
use App\Events\ContentGenerationFailed;
use App\Exceptions\PromptCompilationException;
use App\Models\AiModel;
use App\Models\ContentBlueprintVersion;
use App\Models\ContentBrief;
use App\Models\ContentGenerationRun;
use App\Models\ContentGenerationRunModule;
use App\Models\ContentGenerationRunStep;
use App\Models\ContentQualityReport;
use App\Models\ContentStrategyVersion;
use App\Models\PromptPresetVersion;
use App\Services\GeoFlow\ArticleContentGenerationService;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Responses\AgentResponse;
use RuntimeException;
use Throwable;

final class ContentGenerationCoordinator
{
    private const STALE_RUN_SECONDS = 1200;

    /** @var list<string> */
    private const TERMINAL_STATUSES = [
        ContentGenerationRun::STATUS_READY_FOR_REVIEW,
        ContentGenerationRun::STATUS_NEEDS_EVIDENCE,
        ContentGenerationRun::STATUS_MODEL_QUALITY_FAILED,
        ContentGenerationRun::STATUS_MANUAL_REVIEW_REQUIRED,
        ContentGenerationRun::STATUS_COMPLETED,
        ContentGenerationRun::STATUS_ADOPTED,
    ];

    public function __construct(
        private readonly PromptModuleResolver $moduleResolver,
        private readonly PromptCompiler $promptCompiler,
        private readonly ArticleContentGenerationService $generator,
        private readonly DeterministicQualityGate $deterministicQualityGate,
        private readonly SemanticQualityReviewer $semanticReviewer,
    ) {}

    public function generate(ContentGenerationRun $run): ContentGenerationRun
    {
        try {
            $prepared = $this->prepare((int) $run->id);
        } catch (Throwable $exception) {
            if ($exception instanceof PromptCompilationException && $exception->codeName === 'required_evidence_unavailable') {
                return $this->needsEvidence((int) $run->id);
            }

            return $this->failRun((int) $run->id, $exception);
        }

        if ($prepared === null) {
            return $run->fresh() ?? $run;
        }

        $run = $prepared['run'];
        $blueprint = $prepared['blueprint'];
        $requirements = $prepared['requirements'];

        if ($prepared['manual_review']) {
            return $this->finish($run, ContentGenerationRun::STATUS_MANUAL_REVIEW_REQUIRED);
        }

        if (($requirements['evidence_required'] ?? false) === true && (array) ($requirements['evidence'] ?? []) === []) {
            $issue = [[
                'code' => 'needs_evidence',
                'severity' => 'error',
                'section_key' => null,
                'message' => 'The selected prompt policy requires evidence, but the run has no evidence snapshot.',
                'repair_instruction' => 'Attach at least one reviewed evidence source and create a new generation run.',
            ]];
            $this->qualityReport($run, null, 'preflight', ContentQualityReport::STATUS_NEEDS_EVIDENCE, false, 0, null, null, $issue, [
                'Attach reviewed evidence before generation.',
            ]);

            return $this->finish($run, ContentGenerationRun::STATUS_NEEDS_EVIDENCE, failureMessage: $issue[0]['message']);
        }

        $initial = $prepared['initial_step'] === null
            ? $this->modelInteraction(
                $run,
                ContentGenerationRunStep::TYPE_INITIAL_GENERATION,
                (string) $run->compiled_prompt,
                ['context_digest' => $run->variables_snapshot['context_digest'] ?? null],
            )
            : [
                'ok' => true,
                'output' => (string) $prepared['initial_step']->output,
                'step' => $prepared['initial_step'],
                'exception' => new RuntimeException(''),
            ];
        if (! $initial['ok']) {
            return $this->failRun((int) $run->id, $initial['exception'], $initial['step']);
        }
        if (trim($initial['output']) === '') {
            return $this->failRun(
                (int) $run->id,
                new RuntimeException('The model returned an empty response.'),
                $initial['step'],
            );
        }

        $run->forceFill(['raw_output' => $initial['output']])->save();
        $deterministic = $this->deterministicReview($run, $initial['step'], $initial['output'], $blueprint, $requirements);
        if (! $deterministic->passed) {
            return $this->reviseOnce($run, $blueprint, $requirements, $initial['output'], $deterministic->issuesAsArray());
        }

        $semantic = $this->semanticReview($run, $initial['step'], $blueprint, $initial['output']);
        if ($semantic === null) {
            return $this->finish($run, ContentGenerationRun::STATUS_MANUAL_REVIEW_REQUIRED);
        }
        if ($semantic['passed']) {
            return $this->finish($run, ContentGenerationRun::STATUS_READY_FOR_REVIEW);
        }
        if ($this->semanticNeedsEvidence($semantic['issues'])) {
            return $this->finish($run, ContentGenerationRun::STATUS_NEEDS_EVIDENCE, failureMessage: 'Semantic review requires additional evidence.');
        }

        return $this->reviseOnce($run, $blueprint, $requirements, $initial['output'], $semantic['issues']);
    }

    /**
     * @return array{run: ContentGenerationRun, blueprint: array<string, mixed>, requirements: array<string, mixed>, initial_step: ContentGenerationRunStep|null, manual_review: bool}|null
     */
    private function prepare(int $runId): ?array
    {
        return DB::transaction(function () use ($runId): ?array {
            $run = ContentGenerationRun::query()->lockForUpdate()->findOrFail($runId);
            if (in_array((string) $run->status, self::TERMINAL_STATUSES, true)) {
                return null;
            }
            if (in_array((string) $run->status, [ContentGenerationRun::STATUS_COMPILING, ContentGenerationRun::STATUS_GENERATING, ContentGenerationRun::STATUS_RUNNING], true)
                && $run->updated_at?->greaterThan(now()->subSeconds(self::STALE_RUN_SECONDS))) {
                return null;
            }
            if (in_array((string) $run->status, [
                ContentGenerationRun::STATUS_FAILED,
                ContentGenerationRun::STATUS_COMPILING,
                ContentGenerationRun::STATUS_GENERATING,
                ContentGenerationRun::STATUS_RUNNING,
            ], true)) {
                return $this->resumePreparedRun($run);
            }
            if (! in_array((string) $run->status, [ContentGenerationRun::STATUS_PENDING, ContentGenerationRun::STATUS_QUEUED], true)) {
                throw new RuntimeException('The generation run is not pending.');
            }

            $run->load(['contentBrief.primaryKeyword', 'strategyVersion', 'blueprintVersion', 'presetVersion', 'aiModel', 'evidence']);
            $brief = $run->contentBrief;
            $strategy = $run->strategyVersion;
            $blueprint = $run->blueprintVersion;
            $preset = $run->presetVersion;
            $model = $run->aiModel;
            $this->assertConfirmedInputs($brief, $strategy, $blueprint, $preset, $model);

            $selectionContext = [
                'content_type' => $brief->content_type,
                'language_code' => $brief->language_code,
                'market_code' => $brief->market_code,
            ];
            $modules = $this->moduleResolver->resolve($preset, $selectionContext);
            $generationPolicy = is_array($preset->generation_policy) ? $preset->generation_policy : [];
            $context = new PromptContext(
                strategy: $strategy->strategy,
                blueprint: $blueprint->blueprint,
                keywords: $this->keywords($brief, $strategy->strategy, $blueprint->blueprint),
                evidence: $run->evidence->pluck('evidence_snapshot')->values()->all(),
                products: $this->products($strategy->strategy, $blueprint->blueprint),
                language: (string) $brief->language_code,
                market: (string) $brief->market_code,
                variables: $this->contextVariables($brief, $modules, $generationPolicy),
                modelTokenBudget: $this->modelTokenBudget($model, $generationPolicy),
            );
            $compiled = $this->promptCompiler->compile($modules, $context);

            foreach ($compiled->moduleSnapshots as $index => $snapshot) {
                ContentGenerationRunModule::query()->create([
                    'content_generation_run_id' => $run->id,
                    'prompt_module_version_id' => $snapshot['version_id'],
                    'sort_order' => $index + 1,
                    'is_required' => $snapshot['required'],
                    'rendered_content' => $snapshot['rendered_content'],
                    'module_snapshot' => $snapshot,
                    'variables_snapshot' => $snapshot['resolved_variables'],
                ]);
            }

            $requirements = array_merge(
                $generationPolicy,
                $compiled->resolvedVariables,
                [
                    'language_code' => (string) $brief->language_code,
                    'primary_keyword' => $brief->primaryKeyword?->keyword,
                    'required_mentions' => $brief->required_mentions ?? [],
                ],
            );
            $run->forceFill([
                'compiled_prompt' => $compiled->text,
                'variables_snapshot' => [
                    'resolved_variables' => $compiled->resolvedVariables,
                    'conflict_decisions' => $compiled->conflictDecisions,
                    'estimated_tokens' => $compiled->estimatedTokens,
                    'context_digest' => $compiled->contextDigest,
                ],
                'model_snapshot' => $this->modelSnapshot($model),
                'generation_parameters' => $generationPolicy,
                'status' => ContentGenerationRun::STATUS_COMPILING,
                'started_at' => $run->started_at ?? now(),
                'error_message' => null,
            ])->save();
            $run->forceFill(['status' => ContentGenerationRun::STATUS_GENERATING])->save();

            return [
                'run' => $run->fresh() ?? $run,
                'blueprint' => $blueprint->blueprint,
                'requirements' => $requirements,
                'initial_step' => null,
                'manual_review' => false,
            ];
        });
    }

    /** @return array{run: ContentGenerationRun, blueprint: array<string, mixed>, requirements: array<string, mixed>, initial_step: ContentGenerationRunStep|null, manual_review: bool}|null */
    private function resumePreparedRun(ContentGenerationRun $run): ?array
    {
        if ($run->compiled_prompt === null || $run->variables_snapshot === null || $run->model_snapshot === null) {
            return null;
        }

        $run->load(['contentBrief.primaryKeyword', 'blueprintVersion', 'presetVersion', 'aiModel']);
        if ($run->steps()->where('step_type', ContentGenerationRunStep::TYPE_TARGETED_REVISION)->exists()) {
            return [
                'run' => $run,
                'blueprint' => $run->blueprintVersion->blueprint,
                'requirements' => [],
                'initial_step' => null,
                'manual_review' => true,
            ];
        }

        $run->forceFill([
            'status' => ContentGenerationRun::STATUS_GENERATING,
            'error_message' => null,
            'completed_at' => null,
        ])->save();
        $resolved = (array) ($run->variables_snapshot['resolved_variables'] ?? []);
        $requirements = array_merge(
            (array) $run->generation_parameters,
            $resolved,
            [
                'language_code' => (string) $run->contentBrief->language_code,
                'primary_keyword' => $run->contentBrief->primaryKeyword?->keyword,
                'required_mentions' => $run->contentBrief->required_mentions ?? [],
            ],
        );
        $initialStep = $run->steps()
            ->where('step_type', ContentGenerationRunStep::TYPE_INITIAL_GENERATION)
            ->where('status', ContentGenerationRunStep::STATUS_COMPLETED)
            ->whereNotNull('output')
            ->latest('sequence')
            ->first();

        return [
            'run' => $run->fresh() ?? $run,
            'blueprint' => $run->blueprintVersion->blueprint,
            'requirements' => $requirements,
            'initial_step' => $initialStep,
            'manual_review' => false,
        ];
    }

    private function assertConfirmedInputs(
        ContentBrief $brief,
        ContentStrategyVersion $strategy,
        ContentBlueprintVersion $blueprint,
        PromptPresetVersion $preset,
        AiModel $model,
    ): void {
        if ($brief->status !== ContentBrief::STATUS_STRATEGY_READY
            || $strategy->lifecycle_status !== ContentStrategyVersion::STATUS_CONFIRMED
            || $blueprint->lifecycle_status !== ContentBlueprintVersion::STATUS_CONFIRMED) {
            throw new RuntimeException('The content brief, strategy, and blueprint must be confirmed before generation.');
        }
        if ((int) $blueprint->content_strategy_version_id !== (int) $strategy->id) {
            throw new RuntimeException('The blueprint does not belong to the selected strategy version.');
        }
        if (in_array($preset->lifecycle_status, [PromptPresetVersion::STATUS_DRAFT, PromptPresetVersion::STATUS_ARCHIVED], true)) {
            throw new RuntimeException('The selected prompt preset version is not eligible for generation.');
        }
        if (! $model->exists) {
            throw new RuntimeException('The selected AI model is unavailable.');
        }
    }

    /** @param array<string, mixed> $policy @param list<ResolvedPromptModule> $modules @return array<string, mixed> */
    private function contextVariables(ContentBrief $brief, array $modules, array $policy): array
    {
        $declared = [];
        foreach ($modules as $module) {
            $contract = $module->variableContract['variables'] ?? $module->variableContract;
            if (is_array($contract)) {
                foreach (array_keys($contract) as $name) {
                    if (is_string($name)) {
                        $declared[$name] = true;
                    }
                }
            }
            foreach ((array) ($module->variableContract['required'] ?? []) as $name) {
                if (is_string($name)) {
                    $declared[$name] = true;
                }
            }
        }

        $briefValues = [
            'topic' => $brief->topic,
            'audience' => $brief->audience,
            'core_question' => $brief->core_question,
            'business_goal' => $brief->business_goal,
            'content_type' => $brief->content_type,
            'cta_strength' => $brief->cta_strength,
        ];
        $variables = array_intersect_key($briefValues, $declared);
        foreach (['cta_allowed', 'requested_cta', 'external_links_allowed', 'requested_external_links', 'evidence_required', 'allow_unsupported_claims', 'output_format', 'required_output_format', 'target_length', 'requested_target_length'] as $name) {
            if (array_key_exists($name, $policy)) {
                $variables[$name] = $policy[$name];
            }
        }

        return array_filter($variables, static fn (mixed $value): bool => $value !== null);
    }

    /** @param array<string, mixed> $strategy @param array<string, mixed> $blueprint @return list<mixed> */
    private function keywords(ContentBrief $brief, array $strategy, array $blueprint): array
    {
        $values = array_merge(
            [$brief->primaryKeyword?->keyword],
            (array) ($strategy['keywords'] ?? []),
            (array) ($blueprint['keywords'] ?? []),
        );

        return array_values(array_filter($values, static fn (mixed $value): bool => is_string($value) && trim($value) !== ''));
    }

    /** @param array<string, mixed> $strategy @param array<string, mixed> $blueprint @return list<mixed> */
    private function products(array $strategy, array $blueprint): array
    {
        return array_values(array_merge((array) ($strategy['products'] ?? []), (array) ($blueprint['products'] ?? [])));
    }

    /** @param array<string, mixed> $policy */
    private function modelTokenBudget(AiModel $model, array $policy): int
    {
        $modelBudget = $this->generator->maxTokens($model);
        $policyBudget = (int) ($policy['max_tokens'] ?? 0);

        return $policyBudget > 0 ? min($modelBudget, $policyBudget) : $modelBudget;
    }

    /** @return array<string, mixed> */
    private function modelSnapshot(AiModel $model): array
    {
        return [
            'id' => (int) $model->id,
            'name' => (string) $model->name,
            'version' => $model->version,
            'model_id' => (string) $model->model_id,
            'model_type' => $model->model_type,
            'api_url' => (string) $model->api_url,
            'max_tokens' => $this->generator->maxTokens($model),
        ];
    }

    /**
     * @param  array<string, mixed>  $inputSnapshot
     * @return array{ok: bool, output: string, step: ContentGenerationRunStep, exception: Throwable}
     */
    private function modelInteraction(ContentGenerationRun $run, string $type, string $prompt, array $inputSnapshot): array
    {
        $step = DB::transaction(function () use ($run, $type, $prompt, $inputSnapshot): ContentGenerationRunStep {
            $locked = ContentGenerationRun::query()->lockForUpdate()->findOrFail($run->id);
            $locked->touch();

            return ContentGenerationRunStep::query()->create([
                'content_generation_run_id' => $run->id,
                'sequence' => ((int) $run->steps()->max('sequence')) + 1,
                'step_type' => $type,
                'status' => ContentGenerationRunStep::STATUS_RUNNING,
                'prompt' => $prompt,
                'input_snapshot' => $inputSnapshot,
                'started_at' => now(),
            ]);
        });

        try {
            if (DB::transactionLevel() !== 0) {
                throw new RuntimeException('AI model calls must run outside an open database transaction so input snapshots are durable.');
            }
            $response = $this->generator->generate($run->aiModel()->firstOrFail(), $prompt);
            $output = (string) ($response->text ?? '');
            $this->completeStep($step, $response, $output);

            return ['ok' => true, 'output' => $output, 'step' => $step->fresh() ?? $step, 'exception' => new RuntimeException('')];
        } catch (Throwable $exception) {
            $message = $this->sanitize($exception->getMessage());
            $step->forceFill([
                'status' => ContentGenerationRunStep::STATUS_FAILED,
                'error_message' => $message,
                'completed_at' => now(),
            ])->save();

            return ['ok' => false, 'output' => '', 'step' => $step->fresh() ?? $step, 'exception' => $exception];
        }
    }

    private function completeStep(ContentGenerationRunStep $step, AgentResponse $response, string $output): void
    {
        $step->forceFill([
            'status' => ContentGenerationRunStep::STATUS_COMPLETED,
            'output' => $output,
            'metadata' => [
                'invocation_id' => $response->invocationId,
                'provider' => $response->meta->provider,
                'model' => $response->meta->model,
            ],
            'input_tokens' => $response->usage->promptTokens,
            'output_tokens' => $response->usage->completionTokens,
            'completed_at' => now(),
        ])->save();
    }

    /** @param array<string, mixed> $blueprint @param array<string, mixed> $requirements */
    private function deterministicReview(
        ContentGenerationRun $run,
        ContentGenerationRunStep $step,
        string $article,
        array $blueprint,
        array $requirements,
        bool $afterRevision = false,
    ): QualityGateResult {
        $result = $this->deterministicQualityGate->inspect($article, $blueprint, $requirements);
        $status = $result->passed
            ? ContentQualityReport::STATUS_PASSED
            : ($afterRevision ? ContentQualityReport::STATUS_MANUAL_REVIEW_REQUIRED : ContentQualityReport::STATUS_NEEDS_REVISION);
        $issues = $result->issuesAsArray();
        $this->qualityReport(
            $run,
            $step,
            'deterministic',
            $status,
            $result->passed,
            $result->score,
            ['passed' => $result->passed, 'score' => $result->score, 'issues' => $issues],
            null,
            $issues,
            $this->repairInstructions($issues),
        );

        return $result;
    }

    /**
     * @param  array<string, mixed>  $blueprint
     * @return array{passed: bool, score: float, issues: list<array{code: string, section_key: string|null, message: string, repair_instruction: string}>}|null
     */
    private function semanticReview(ContentGenerationRun $run, ContentGenerationRunStep $articleStep, array $blueprint, string $article): ?array
    {
        $prompt = $this->semanticReviewer->prompt(
            $blueprint,
            (array) ($run->variables_snapshot['resolved_variables']['evidence'] ?? []),
            $article,
        );
        $lastException = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $interaction = $this->modelInteraction($run, ContentGenerationRunStep::TYPE_SEMANTIC_QUALITY, $prompt, [
                'article_step_id' => (int) $articleStep->id,
                'attempt' => $attempt,
            ]);
            if (! $interaction['ok']) {
                $lastException = $interaction['exception'];

                continue;
            }

            try {
                $review = $this->semanticReviewer->parse($interaction['output']);
                $status = $review['passed']
                    ? ContentQualityReport::STATUS_PASSED
                    : ($this->semanticNeedsEvidence($review['issues'])
                        ? ContentQualityReport::STATUS_NEEDS_EVIDENCE
                        : ContentQualityReport::STATUS_NEEDS_REVISION);
                $this->qualityReport(
                    $run,
                    $interaction['step'],
                    'semantic',
                    $status,
                    $review['passed'],
                    $review['score'],
                    null,
                    $review,
                    $review['issues'],
                    $this->repairInstructions($review['issues']),
                );

                return $review;
            } catch (Throwable $exception) {
                $lastException = $exception;
                $interaction['step']->forceFill([
                    'status' => ContentGenerationRunStep::STATUS_FAILED,
                    'error_message' => $this->sanitize($exception->getMessage()),
                ])->save();
            }
        }

        $issue = [[
            'code' => 'semantic_review_parse_failed',
            'section_key' => null,
            'message' => 'Semantic review could not produce valid structured JSON after one retry.',
            'repair_instruction' => 'Complete semantic review manually.',
        ]];
        $this->qualityReport(
            $run,
            null,
            'semantic',
            ContentQualityReport::STATUS_MODEL_QUALITY_FAILED,
            false,
            null,
            null,
            ['error' => $this->sanitize($lastException?->getMessage() ?? 'Semantic review failed.')],
            $issue,
            ['Complete semantic review manually.'],
        );

        return null;
    }

    /** @param array<string, mixed> $blueprint @param array<string, mixed> $requirements @param list<array<string, mixed>> $issues */
    private function reviseOnce(ContentGenerationRun $run, array $blueprint, array $requirements, string $article, array $issues): ContentGenerationRun
    {
        $instructions = $this->repairInstructions($issues);
        $prompt = "Revise only the problems named in REPAIR INSTRUCTIONS. Preserve every other valid passage and return only the complete revised Markdown article.\n\n"
            ."ORIGINAL BLUEPRINT\n".$this->json($blueprint)
            ."\n\nORIGINAL ARTICLE\n".$article
            ."\n\nREPAIR INSTRUCTIONS\n".$this->json($instructions);
        $revision = $this->modelInteraction($run, ContentGenerationRunStep::TYPE_TARGETED_REVISION, $prompt, [
            'blueprint' => $blueprint,
            'repair_instructions' => $instructions,
        ]);
        if (! $revision['ok']) {
            return $this->failRun((int) $run->id, $revision['exception'], $revision['step']);
        }
        if (trim($revision['output']) === '') {
            return $this->failRun((int) $run->id, new RuntimeException('The model returned an empty response.'), $revision['step']);
        }

        $deterministic = $this->deterministicReview($run, $revision['step'], $revision['output'], $blueprint, $requirements, true);
        if (! $deterministic->passed) {
            return $this->finish($run, ContentGenerationRun::STATUS_MANUAL_REVIEW_REQUIRED);
        }

        $semantic = $this->semanticReview($run, $revision['step'], $blueprint, $revision['output']);
        if ($semantic !== null && $this->semanticNeedsEvidence($semantic['issues'])) {
            return $this->finish($run, ContentGenerationRun::STATUS_NEEDS_EVIDENCE, failureMessage: 'Semantic review requires additional evidence.');
        }
        if ($semantic === null || ! $semantic['passed']) {
            return $this->finish($run, ContentGenerationRun::STATUS_MANUAL_REVIEW_REQUIRED);
        }

        return $this->finish($run, ContentGenerationRun::STATUS_READY_FOR_REVIEW);
    }

    /** @param list<array<string, mixed>> $issues @return list<string> */
    private function repairInstructions(array $issues): array
    {
        $instructions = [];
        foreach ($issues as $issue) {
            $instruction = trim((string) ($issue['repair_instruction'] ?? ''));
            if ($instruction !== '' && ! in_array($instruction, $instructions, true)) {
                $instructions[] = $instruction;
            }
        }

        return $instructions;
    }

    /** @param list<array<string, mixed>> $issues */
    private function semanticNeedsEvidence(array $issues): bool
    {
        return $this->semanticReviewer->needsEvidence($issues);
    }

    /**
     * @param  array<string, mixed>|null  $deterministic
     * @param  array<string, mixed>|null  $semantic
     * @param  list<array<string, mixed>>  $issues
     * @param  list<string>  $instructions
     */
    private function qualityReport(
        ContentGenerationRun $run,
        ?ContentGenerationRunStep $step,
        string $type,
        string $status,
        bool $passed,
        int|float|null $score,
        ?array $deterministic,
        ?array $semantic,
        array $issues,
        array $instructions,
    ): ContentQualityReport {
        return ContentQualityReport::query()->create([
            'content_generation_run_id' => $run->id,
            'content_generation_run_step_id' => $step?->id,
            'report_type' => $type,
            'status' => $status,
            'passed' => $passed,
            'score' => $score,
            'deterministic_results' => $deterministic,
            'semantic_results' => $semantic,
            'issues' => $issues,
            'revision_instructions' => $instructions,
        ]);
    }

    private function finish(ContentGenerationRun $run, string $status, ?string $failureMessage = null): ContentGenerationRun
    {
        $fresh = DB::transaction(function () use ($run, $status, $failureMessage): ContentGenerationRun {
            $locked = ContentGenerationRun::query()->lockForUpdate()->findOrFail($run->id);
            $locked->forceFill([
                'status' => $status,
                'error_message' => $failureMessage === null ? null : $this->sanitize($failureMessage),
                'completed_at' => now(),
            ])->save();

            return $locked->fresh() ?? $locked;
        });

        if (in_array($status, [ContentGenerationRun::STATUS_FAILED, ContentGenerationRun::STATUS_NEEDS_EVIDENCE, ContentGenerationRun::STATUS_MODEL_QUALITY_FAILED], true)) {
            $this->dispatchSafely(new ContentGenerationFailed((int) $fresh->id, $status, (string) $fresh->error_message));
        } else {
            $this->dispatchSafely(new ContentGenerationCompleted((int) $fresh->id, $status));
        }

        return $fresh;
    }

    private function needsEvidence(int $runId): ContentGenerationRun
    {
        $run = ContentGenerationRun::query()->findOrFail($runId);
        $issue = [[
            'code' => 'needs_evidence',
            'severity' => 'error',
            'section_key' => null,
            'message' => 'The selected prompt policy requires evidence, but the run has no evidence snapshot.',
            'repair_instruction' => 'Attach at least one reviewed evidence source and create a new generation run.',
        ]];
        $this->qualityReport($run, null, 'preflight', ContentQualityReport::STATUS_NEEDS_EVIDENCE, false, 0, null, null, $issue, [
            'Attach reviewed evidence before generation.',
        ]);

        return $this->finish($run, ContentGenerationRun::STATUS_NEEDS_EVIDENCE, failureMessage: $issue[0]['message']);
    }

    private function failRun(int $runId, Throwable $exception, ?ContentGenerationRunStep $step = null): ContentGenerationRun
    {
        $message = $this->sanitize($exception->getMessage() !== '' ? $exception->getMessage() : 'Content generation failed.');
        $run = DB::transaction(function () use ($runId, $message, $step): ContentGenerationRun {
            $locked = ContentGenerationRun::query()->lockForUpdate()->findOrFail($runId);
            if (! in_array((string) $locked->status, self::TERMINAL_STATUSES, true)) {
                $locked->forceFill([
                    'status' => ContentGenerationRun::STATUS_FAILED,
                    'error_message' => $message,
                    'completed_at' => now(),
                ])->save();
            }
            if ($step !== null && $step->status !== ContentGenerationRunStep::STATUS_FAILED) {
                $step->forceFill([
                    'status' => ContentGenerationRunStep::STATUS_FAILED,
                    'error_message' => $message,
                    'completed_at' => now(),
                ])->save();
            }

            return $locked->fresh() ?? $locked;
        });

        $this->dispatchSafely(new ContentGenerationFailed((int) $run->id, (string) $run->status, $message));

        return $run;
    }

    private function dispatchSafely(object $event): void
    {
        try {
            event($event);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function sanitize(string $message): string
    {
        $message = preg_replace('/(authorization\s*:\s*bearer\s+)[^\s,;]+/iu', '$1[REDACTED]', $message) ?? $message;
        $message = preg_replace('/((?:api[_-]?key|access[_-]?token|secret)\s*[=:]\s*)[^\s,;]+/iu', '$1[REDACTED]', $message) ?? $message;
        $message = preg_replace('/\bsk-[A-Za-z0-9_-]+\b/u', '[REDACTED]', $message) ?? $message;

        return mb_substr(trim($message), 0, 2000);
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }
}
