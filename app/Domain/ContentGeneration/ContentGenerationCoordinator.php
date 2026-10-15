<?php

namespace App\Domain\ContentGeneration;

use App\Domain\ContentGeneration\ExternalEvidence\EvidencePreparationCoordinator;
use App\Domain\PromptComposition\CompiledPrompt;
use App\Domain\PromptComposition\MandatoryPromptModuleGuard;
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
use App\Models\KnowledgeSite;
use App\Models\PromptPresetVersion;
use App\Services\GeoFlow\ArticleContentGenerationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
        ContentGenerationRun::STATUS_AWAITING_EXTERNAL_OUTPUT,
    ];

    public function __construct(
        private readonly PromptModuleResolver $moduleResolver,
        private readonly MandatoryPromptModuleGuard $mandatoryModules,
        private readonly PromptCompiler $promptCompiler,
        private readonly ArticleContentGenerationService $generator,
        private readonly DeterministicQualityGate $deterministicQualityGate,
        private readonly SemanticQualityReviewer $semanticReviewer,
        private readonly SensitiveContentRedactor $redactor,
        private readonly EvidencePreparationCoordinator $evidencePreparation,
    ) {}

    public function generate(ContentGenerationRun $run): ContentGenerationRun
    {
        $claim = $this->claim((int) $run->id);
        if ($claim === null) {
            return $run->fresh() ?? $run;
        }

        try {
            $externalPreparation = $this->prepareExternalEvidence($run, $claim);
            $prepared = $this->prepare((int) $run->id, $claim, $externalPreparation);
        } catch (Throwable $exception) {
            if ($exception instanceof PromptCompilationException && $exception->codeName === 'required_evidence_unavailable') {
                return $this->needsEvidence((int) $run->id, $claim);
            }

            return $this->failRun((int) $run->id, $exception, null, $claim);
        }

        if ($prepared === null) {
            return $run->fresh() ?? $run;
        }

        $run = $prepared['run'];
        $blueprint = $prepared['blueprint'];
        $requirements = $prepared['requirements'];

        if ($prepared['manual_review']) {
            return $this->finish($run, ContentGenerationRun::STATUS_MANUAL_REVIEW_REQUIRED, $claim);
        }

        $requiredFactIds = [];
        foreach ((array) ($blueprint['sections'] ?? []) as $section) {
            if (is_array($section)) {
                $requiredFactIds = array_merge($requiredFactIds, array_filter((array) ($section['required_fact_ids'] ?? []), 'is_string'));
            }
        }
        $coverageIds = ($prepared['external_preparation_enabled'] ?? false)
            ? (array) ($requirements['supported_claim_ids'] ?? [])
            : (array) ($requirements['evidence_fact_ids'] ?? []);
        $missingFactIds = array_values(array_diff(array_unique($requiredFactIds), $coverageIds));
        if ((($requirements['evidence_required'] ?? false) === true && (array) ($requirements['evidence'] ?? []) === [])
            || $missingFactIds !== []) {
            $issue = [[
                'code' => $missingFactIds === [] ? 'needs_evidence' : 'required_fact_uncovered',
                'severity' => 'error',
                'section_key' => null,
                'message' => $missingFactIds === []
                    ? 'The selected prompt policy requires evidence, but the run has no evidence snapshot.'
                    : 'Required facts lack reviewed evidence snapshots: '.implode(', ', $missingFactIds).'.',
                'repair_instruction' => 'Attach reviewed evidence for every required fact and create a new generation run.',
            ]];
            $report = $this->qualityReport($run, null, 'preflight', ContentQualityReport::STATUS_NEEDS_EVIDENCE, false, 0, null, null, $issue, [
                'Attach reviewed evidence before generation.',
            ], $claim);
            if ($report === null) {
                return $run->fresh() ?? $run;
            }

            return $this->finish($run, ContentGenerationRun::STATUS_NEEDS_EVIDENCE, $claim, $issue[0]['message']);
        }

        // Prompt/evidence/knowledge are already compiled and persisted on $run
        // at this point (see prepare()) — for a task routed to an external
        // writer, stop here instead of spending a paid model call. WorkBuddy
        // reads compiled_prompt off this run and supplies raw_output through
        // WorkBuddyTaskController, which resumes at article adoption.
        if ($this->isExternalGenerationRun($run)) {
            return $this->finish($run, ContentGenerationRun::STATUS_AWAITING_EXTERNAL_OUTPUT, $claim);
        }

        [$initialPrompt, $initialSecrets] = $this->modelPayload($run);
        $initial = $prepared['initial_step'] === null
            ? $this->modelInteraction(
                $run,
                ContentGenerationRunStep::TYPE_INITIAL_GENERATION,
                $initialPrompt,
                ['context_digest' => $run->variables_snapshot['context_digest'] ?? null],
                $claim,
                (string) $run->compiled_prompt,
                $initialSecrets,
            )
            : [
                'ok' => true,
                'output' => (string) $prepared['initial_step']->output,
                'step' => $prepared['initial_step'],
                'exception' => new RuntimeException(''),
            ];
        if ($initial['lost'] ?? false) {
            return $run->fresh() ?? $run;
        }
        if (! $initial['ok']) {
            return $this->failRun((int) $run->id, $initial['exception'], $initial['step'], $claim);
        }
        if (trim($initial['output']) === '') {
            return $this->failRun(
                (int) $run->id,
                new RuntimeException('The model returned an empty response.'),
                $initial['step'],
                $claim,
            );
        }

        if (! $this->updateRawOutput($run, $initial['output'], $claim)) {
            return $run->fresh() ?? $run;
        }
        // Evidence is advisory for publication. The preparation phase may mark
        // a run as draft-only when the core question cannot be verified, but we
        // still continue through deterministic/semantic quality checks so the
        // article can be published. The prompt already receives the prohibited
        // claim list; unsupported high-risk facts are therefore omitted while
        // general guidance remains publishable.
        $deterministic = $this->deterministicReview($run, $initial['step'], $initial['output'], $blueprint, $requirements, $claim);
        if ($deterministic === null) {
            return $run->fresh() ?? $run;
        }
        if (! $deterministic->passed) {
            return $this->reviseOnce($run, $blueprint, $requirements, $initial['output'], $deterministic->issuesAsArray(), $claim);
        }

        $semantic = $this->semanticReview($run, $initial['step'], $blueprint, $initial['output'], $claim);
        if (($semantic['claim_lost'] ?? false) === true) {
            return $run->fresh() ?? $run;
        }
        if (isset($semantic['fatal_exception']) && $semantic['fatal_exception'] instanceof Throwable) {
            return $this->failRun((int) $run->id, $semantic['fatal_exception'], $semantic['step'] ?? null, $claim);
        }
        if ($semantic === null) {
            return $this->finish($run, ContentGenerationRun::STATUS_MANUAL_REVIEW_REQUIRED, $claim);
        }
        if ($semantic['passed']) {
            return $this->finish($run, ContentGenerationRun::STATUS_READY_FOR_REVIEW, $claim);
        }
        if ($this->semanticNeedsEvidence($semantic['issues'])) {
            return $this->finish($run, ContentGenerationRun::STATUS_NEEDS_EVIDENCE, $claim, 'Semantic review requires additional evidence.');
        }

        return $this->reviseOnce($run, $blueprint, $requirements, $initial['output'], $semantic['issues'], $claim);
    }

    private function isExternalGenerationRun(ContentGenerationRun $run): bool
    {
        $engine = $run->relationLoaded('contentBrief')
            ? $run->contentBrief?->task?->generation_engine
            : $run->contentBrief()->with('task:id,generation_engine')->first()?->task?->generation_engine;

        return (string) ($engine ?? '') === 'workbuddy';
    }

    /** @return array{token: string, version: int}|null */
    private function claim(int $runId): ?array
    {
        return DB::transaction(function () use ($runId): ?array {
            $run = ContentGenerationRun::query()->lockForUpdate()->findOrFail($runId);
            if (in_array((string) $run->status, self::TERMINAL_STATUSES, true)) {
                return null;
            }
            $active = in_array((string) $run->status, [
                ContentGenerationRun::STATUS_COMPILING,
                ContentGenerationRun::STATUS_GENERATING,
                ContentGenerationRun::STATUS_RUNNING,
            ], true);
            if ($active && ($run->lease_expires_at?->isFuture()
                ?? $run->updated_at?->greaterThan(now()->subSeconds(self::STALE_RUN_SECONDS)))) {
                return null;
            }
            if (! in_array((string) $run->status, [
                ContentGenerationRun::STATUS_PENDING,
                ContentGenerationRun::STATUS_QUEUED,
                ContentGenerationRun::STATUS_FAILED,
                ContentGenerationRun::STATUS_COMPILING,
                ContentGenerationRun::STATUS_GENERATING,
                ContentGenerationRun::STATUS_RUNNING,
            ], true)) {
                throw new RuntimeException('The generation run cannot be claimed.');
            }

            $claim = [
                'token' => (string) Str::uuid(),
                'version' => ((int) $run->lease_version) + 1,
            ];
            $run->forceFill([
                'claim_token' => $claim['token'],
                'lease_version' => $claim['version'],
                'lease_expires_at' => now()->addSeconds(self::STALE_RUN_SECONDS),
            ])->save();

            return $claim;
        });
    }

    /**
     * @return array{run: ContentGenerationRun, blueprint: array<string, mixed>, requirements: array<string, mixed>, initial_step: ContentGenerationRunStep|null, manual_review: bool}|null
     */
    private function prepare(int $runId, array $claim, ?array $externalPreparation = null): ?array
    {
        return DB::transaction(function () use ($runId, $claim, $externalPreparation): ?array {
            $run = $this->lockClaim($runId, $claim);
            if ($run === null) {
                return null;
            }
            if (in_array((string) $run->status, self::TERMINAL_STATUSES, true)) {
                return null;
            }
            if (in_array((string) $run->status, [
                ContentGenerationRun::STATUS_FAILED,
                ContentGenerationRun::STATUS_COMPILING,
                ContentGenerationRun::STATUS_GENERATING,
                ContentGenerationRun::STATUS_RUNNING,
            ], true)) {
                return $this->resumePreparedRun($run, $externalPreparation);
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
                'keywords' => $this->keywords($brief, $strategy->strategy, $blueprint->blueprint),
                'products' => $this->products($strategy->strategy, $blueprint->blueprint),
                'store_links' => $this->storeLinks($brief, $strategy->strategy, $blueprint->blueprint),
                'brand_scope' => $this->hasBrandScope($brief, $strategy->strategy, $blueprint->blueprint),
                'evidence_available' => $externalPreparation !== null
                    ? (array) ($externalPreparation['evidence'] ?? []) !== []
                    : $run->evidence->isNotEmpty(),
                'dynamic_claims' => $this->hasDynamicClaims($brief, $strategy->strategy, $blueprint->blueprint),
            ];
            $this->mandatoryModules->assertComplete($preset, $selectionContext);
            $modules = $this->moduleResolver->resolve($preset, $selectionContext);
            $generationPolicy = is_array($preset->generation_policy) ? $preset->generation_policy : [];
            $frozenPolicy = is_array($run->external_evidence_policy_snapshot) ? $run->external_evidence_policy_snapshot : [];
            $generationPolicy = array_merge($generationPolicy, $frozenPolicy);
            $budgets = $this->modelBudgets($model, $generationPolicy);
            $contextEvidence = $externalPreparation === null
                ? $run->evidence->pluck('evidence_snapshot')->values()->all()
                : (array) ($externalPreparation['evidence'] ?? []);
            $externalProhibitions = array_map(
                static fn (string $id): string => 'Do not state unsupported claim: '.$id,
                (array) ($externalPreparation['prohibited_claims'] ?? []),
            );
            if ($externalProhibitions !== []) {
                $generationPolicy['prohibited_information'] = array_values(array_merge((array) $brief->prohibited_information, $externalProhibitions));
            }
            $context = new PromptContext(
                strategy: $strategy->strategy,
                blueprint: $blueprint->blueprint,
                keywords: $this->keywords($brief, $strategy->strategy, $blueprint->blueprint),
                evidence: $contextEvidence,
                products: $this->products($strategy->strategy, $blueprint->blueprint),
                language: (string) $brief->language_code,
                market: (string) $brief->market_code,
                variables: $this->contextVariables($brief, $modules, $generationPolicy),
                modelTokenBudget: $budgets['input_token_budget'],
            );
            $compiled = $this->promptCompiler->compile($modules, $context);
            if ($externalPreparation !== null) {
                $citationRule = "\n\nEXTERNAL EVIDENCE CITATION RULE\nAfter each factual statement supported by an evidence item, add its exact marker [[evidence:FACT_ID]]. Never invent a FACT_ID. Do not create a Sources section; the system renders validated links after generation.";
                $modelPayload = rtrim($compiled->modelPayload).$citationRule;
                $compiled = new CompiledPrompt(
                    text: rtrim($compiled->text).$citationRule,
                    modelPayload: $modelPayload,
                    sensitiveValues: $compiled->sensitiveValues,
                    moduleSnapshots: $compiled->moduleSnapshots,
                    resolvedVariables: $compiled->resolvedVariables,
                    conflictDecisions: $compiled->conflictDecisions,
                    estimatedTokens: $compiled->estimatedTokens + 55,
                    contextDigest: hash('sha256', $modelPayload),
                );
            }

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
                    'prohibited_information' => array_values(array_merge((array) $brief->prohibited_information, $externalProhibitions)),
                    'market_code' => (string) $brief->market_code,
                    'target_length' => (int) ($blueprint->blueprint['target_length'] ?? 0),
                    'evidence_fact_ids' => $externalPreparation === null
                        ? $run->evidence->pluck('evidence_key')->values()->all()
                        : array_values(array_filter(array_column($contextEvidence, 'fact_id'))),
                    'supported_claim_ids' => array_values(array_filter(array_column((array) data_get($externalPreparation, 'assessment.supported', []), 'id'))),
                ],
            );
            $run->forceFill([
                'compiled_prompt' => $compiled->text,
                'encrypted_model_payload' => Crypt::encryptString(json_encode([
                    'prompt' => $compiled->modelPayload,
                    'sensitive_values' => $compiled->sensitiveValues,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
                'variables_snapshot' => [
                    'resolved_variables' => $compiled->resolvedVariables,
                    'conflict_decisions' => $compiled->conflictDecisions,
                    'estimated_tokens' => $compiled->estimatedTokens,
                    'context_digest' => $compiled->contextDigest,
                    'requirements' => $requirements,
                ],
                'model_snapshot' => $this->modelSnapshot($model, $budgets),
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
                'draft_only' => ($externalPreparation['state'] ?? null) === 'draft_only',
                'external_preparation_enabled' => $externalPreparation !== null,
            ];
        });
    }

    private function prepareExternalEvidence(ContentGenerationRun $run, array $claim): ?array
    {
        $run->forceFill(['claim_token' => $claim['token'], 'lease_version' => $claim['version']]);
        $parameters = is_array($run->external_evidence_policy_snapshot) ? $run->external_evidence_policy_snapshot : [];
        if (($parameters['external_evidence_policy'] ?? 'off') !== 'on_insufficient') {
            return null;
        }
        $run->loadMissing(['contentBrief', 'blueprintVersion', 'aiModel', 'evidence']);
        $brief = $run->contentBrief;
        $officialDomains = [];
        if ($brief?->knowledge_site_id !== null) {
            $host = KnowledgeSite::query()
                ->whereKey($brief->knowledge_site_id)
                ->where('brand_id', $brief->knowledge_brand_id)
                ->value('canonical_host');
            if (is_string($host) && trim($host) !== '') {
                $officialDomains[] = strtolower(trim($host, " .\t\n\r\0\x0B"));
            }
        }

        return $this->evidencePreparation->prepare($run, [
            'claims' => $this->evidenceClaims($brief, (array) $run->blueprintVersion->blueprint),
            'official_domains' => $officialDomains,
            'general_domains' => array_values((array) ($parameters['external_general_domains'] ?? [])),
            'claim_token' => $claim['token'],
            'lease_version' => $claim['version'],
        ]);
    }

    private function evidenceClaims(?ContentBrief $brief, array $blueprint): array
    {
        $claims = [[
            'id' => 'core-question',
            'question' => (string) ($brief?->core_question ?: $brief?->topic ?: 'What core facts must be verified?'),
            'core' => true,
            'source_scope' => $this->evidenceSourceScope($brief, (string) ($brief?->core_question ?: $brief?->topic ?: '')),
        ]];
        foreach ((array) ($blueprint['sections'] ?? []) as $sectionIndex => $section) {
            if (! is_array($section)) {
                continue;
            }
            foreach ((array) ($section['questions_to_answer'] ?? []) as $questionIndex => $question) {
                if (trim((string) $question) === '') {
                    continue;
                }
                $claims[] = [
                    'id' => 'section-'.($section['key'] ?? $sectionIndex).'-'.$questionIndex,
                    'question' => (string) $question,
                    'core' => false,
                    'source_scope' => $this->evidenceSourceScope($brief, (string) $question),
                ];
            }
            foreach ((array) ($section['required_fact_ids'] ?? []) as $factId) {
                $factId = trim((string) $factId);
                if ($factId === '' || collect($claims)->contains(static fn (array $claim): bool => $claim['id'] === $factId)) {
                    continue;
                }
                $claims[] = [
                    'id' => $factId,
                    'question' => 'Verify the required fact identified as "'.$factId.'" for this article.',
                    'core' => true,
                    'source_scope' => $this->evidenceSourceScope($brief, $factId),
                ];
            }
        }

        return $claims;
    }

    private function evidenceSourceScope(?ContentBrief $brief, string $question): string
    {
        $mode = (string) ($brief?->knowledge_mode ?? 'legacy_manual');
        if ($mode === 'brand_auto') {
            return 'official';
        }
        if ($mode !== 'mixed' || $brief?->knowledge_brand_id === null) {
            return 'general';
        }

        $brand = \App\Models\KnowledgeBrand::query()->find($brief->knowledge_brand_id);
        $brandText = mb_strtolower(trim((string) ($brand?->key ?? '').' '.(string) ($brand?->name ?? '')));
        $questionText = mb_strtolower($question);
        $brandTerms = array_values(array_filter(preg_split('/[^a-z0-9]+/i', $brandText, -1, PREG_SPLIT_NO_EMPTY) ?: []));
        foreach ($brandTerms as $term) {
            if ($term !== '' && str_contains($questionText, $term)) {
                return 'official';
            }
        }
        if (preg_match('/\b(shipping|delivery|tracking|return|refund|payment|warranty|official policy|customer service)\b/i', $question) === 1) {
            return 'official';
        }

        return 'general';
    }

    /** @return array{run: ContentGenerationRun, blueprint: array<string, mixed>, requirements: array<string, mixed>, initial_step: ContentGenerationRunStep|null, manual_review: bool}|null */
    private function resumePreparedRun(ContentGenerationRun $run, ?array $externalPreparation = null): ?array
    {
        if ($run->compiled_prompt === null || $run->variables_snapshot === null || $run->model_snapshot === null) {
            return null;
        }

        $run->load(['blueprintVersion']);
        if ($run->steps()->where('step_type', ContentGenerationRunStep::TYPE_TARGETED_REVISION)->exists()) {
            return [
                'run' => $run,
                'blueprint' => $run->blueprintVersion->blueprint,
                'requirements' => [],
                'initial_step' => null,
                'manual_review' => true,
                'draft_only' => ($externalPreparation['state'] ?? null) === 'draft_only',
                'external_preparation_enabled' => $externalPreparation !== null,
            ];
        }

        $run->forceFill([
            'status' => ContentGenerationRun::STATUS_GENERATING,
            'error_message' => null,
            'completed_at' => null,
        ])->save();
        $requirements = $run->variables_snapshot['requirements'] ?? null;
        if (! is_array($requirements)) {
            throw new RuntimeException('The generation run does not contain a complete requirements snapshot.');
        }
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
            'draft_only' => ($externalPreparation['state'] ?? null) === 'draft_only',
            'external_preparation_enabled' => $externalPreparation !== null,
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
        foreach (['cta_allowed', 'requested_cta', 'external_links_allowed', 'requested_external_links', 'evidence_required', 'allow_unsupported_claims', 'output_format', 'required_output_format', 'target_length', 'requested_target_length', 'prohibited_information'] as $name) {
            if (array_key_exists($name, $policy) && isset($declared[$name])) {
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

    /** @param array<string, mixed> $strategy @param array<string, mixed> $blueprint @return list<string> */
    private function storeLinks(ContentBrief $brief, array $strategy, array $blueprint): array
    {
        $values = [
            $brief->target_url,
            $strategy['target_url'] ?? null,
            $blueprint['target_url'] ?? null,
            ...(array) ($strategy['store_links'] ?? []),
            ...(array) ($blueprint['store_links'] ?? []),
        ];

        return array_values(array_unique(array_filter($values, static fn (mixed $value): bool => is_string($value) && trim($value) !== '')));
    }

    /** @param array<string, mixed> $strategy @param array<string, mixed> $blueprint */
    private function hasBrandScope(ContentBrief $brief, array $strategy, array $blueprint): bool
    {
        if ($brief->knowledge_brand_id !== null) {
            return true;
        }

        $values = [
            $strategy['brand'] ?? null,
            $strategy['brand_name'] ?? null,
            $blueprint['brand'] ?? null,
            ...((array) $brief->required_mentions),
        ];

        foreach ($values as $value) {
            if (is_string($value) && preg_match('/light\s*in\s*the\s*box/i', $value) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $strategy @param array<string, mixed> $blueprint */
    private function hasDynamicClaims(ContentBrief $brief, array $strategy, array $blueprint): bool
    {
        if ($brief->campaign_window !== null && $brief->campaign_window !== []) {
            return true;
        }

        return in_array((string) $brief->content_type, [
            'new_arrivals_weekly',
            'new_arrivals_monthly',
            'official_news',
            'trust_service',
            'seasonal_guide',
        ], true)
            || $this->products($strategy, $blueprint) !== []
            || (array) ($strategy['dynamic_facts'] ?? []) !== []
            || (array) ($blueprint['dynamic_facts'] ?? []) !== [];
    }

    /** @param array<string, mixed> $policy */
    /** @param array<string, mixed> $policy @return array{context_window_tokens: int, max_output_tokens: int, input_token_budget: int, safety_margin_tokens: int} */
    private function modelBudgets(AiModel $model, array $policy): array
    {
        $configuredOutput = $this->generator->maxTokens($model);
        $policyOutput = (int) ($policy['max_tokens'] ?? 0);
        $maxOutput = $policyOutput > 0 ? min($configuredOutput, $policyOutput) : $configuredOutput;
        $contextWindow = max(1, (int) ($model->context_window_tokens ?: config('geoflow.content_context_window_tokens', 32768)));
        $safetyMargin = max(0, (int) config('geoflow.content_context_safety_margin_tokens', 1024));
        $inputBudget = max(1, $contextWindow - $maxOutput - $safetyMargin);
        $policyInput = (int) ($policy['max_input_tokens'] ?? 0);
        if ($policyInput > 0) {
            $inputBudget = min($inputBudget, $policyInput);
        }

        return [
            'context_window_tokens' => $contextWindow,
            'max_output_tokens' => $maxOutput,
            'input_token_budget' => $inputBudget,
            'safety_margin_tokens' => $safetyMargin,
        ];
    }

    /** @param array<string, int> $budgets @return array<string, mixed> */
    private function modelSnapshot(AiModel $model, array $budgets): array
    {
        return [
            'id' => (int) $model->id,
            'name' => (string) $model->name,
            'version' => $model->version,
            'model_id' => (string) $model->model_id,
            'model_type' => $model->model_type,
            'api_url' => (string) $model->api_url,
            'max_tokens' => $budgets['max_output_tokens'],
            ...$budgets,
            'configuration_fingerprint' => $this->modelFingerprint($model),
        ];
    }

    private function modelFingerprint(AiModel $model): string
    {
        return hash('sha256', json_encode([
            'id' => (int) $model->id,
            'api_key' => (string) ($model->getRawOriginal('api_key') ?? ''),
            'api_url' => trim((string) $model->api_url),
            'model_id' => trim((string) $model->model_id),
            'model_type' => $model->model_type,
            'status' => $model->status,
            'max_tokens' => (int) $model->max_tokens,
            'context_window_tokens' => (int) $model->context_window_tokens,
            'daily_limit' => (int) $model->daily_limit,
            'failover_priority' => (int) $model->failover_priority,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $inputSnapshot
     * @param  array{token: string, version: int}  $claim
     * @return array{ok: bool, lost: bool, fatal: bool, output: string, step: ContentGenerationRunStep|null, exception: Throwable}
     */
    private function modelInteraction(
        ContentGenerationRun $run,
        string $type,
        string $prompt,
        array $inputSnapshot,
        array $claim,
        ?string $auditPrompt = null,
        array $sensitiveValues = [],
    ): array {
        $sensitiveValues = array_values(array_unique([
            ...$sensitiveValues,
            ...$this->redactor->valuesForRun($run),
        ]));
        $safePrompt = $this->redactor->string($auditPrompt ?? $prompt, $sensitiveValues);
        $safeInputSnapshot = $this->redactor->value($inputSnapshot, $sensitiveValues);
        try {
            $step = DB::transaction(function () use ($run, $type, $safePrompt, $safeInputSnapshot, $claim): ?ContentGenerationRunStep {
                $locked = $this->lockClaim((int) $run->id, $claim);
                if ($locked === null) {
                    return null;
                }
                $this->extendLease($locked);

                return ContentGenerationRunStep::query()->create([
                    'content_generation_run_id' => $run->id,
                    'sequence' => ((int) ContentGenerationRunStep::query()
                        ->where('content_generation_run_id', $run->id)->max('sequence')) + 1,
                    'step_type' => $type,
                    'status' => ContentGenerationRunStep::STATUS_RUNNING,
                    'prompt' => $safePrompt,
                    'input_snapshot' => $safeInputSnapshot,
                    'started_at' => now(),
                ]);
            });
        } catch (QueryException $exception) {
            return ['ok' => false, 'lost' => ! $this->ownsClaim((int) $run->id, $claim), 'fatal' => false, 'output' => '', 'step' => null, 'exception' => $exception];
        }

        if ($step === null) {
            return ['ok' => false, 'lost' => true, 'fatal' => false, 'output' => '', 'step' => null, 'exception' => new RuntimeException('Generation claim was superseded.')];
        }

        try {
            if (DB::transactionLevel() !== 0) {
                throw new RuntimeException('AI model calls must run outside an open database transaction so input snapshots are durable.');
            }
            $model = $run->aiModel()->firstOrFail();
            $expectedFingerprint = (string) ($run->model_snapshot['configuration_fingerprint'] ?? '');
            if ($expectedFingerprint === '' || ! hash_equals($expectedFingerprint, $this->modelFingerprint($model))) {
                throw new RuntimeException('Selected AI model configuration changed after run preparation.');
            }
            $response = $this->generator->generate($model, $prompt);
            $output = $this->redactor->string((string) ($response->text ?? ''), $sensitiveValues);
            if (! $this->completeStep($run, $step, $response, $output, $claim, $sensitiveValues)) {
                return ['ok' => false, 'lost' => true, 'fatal' => false, 'output' => '', 'step' => $step, 'exception' => new RuntimeException('Generation claim was superseded.')];
            }

            return ['ok' => true, 'lost' => false, 'fatal' => false, 'output' => $output, 'step' => $step->fresh() ?? $step, 'exception' => new RuntimeException('')];
        } catch (Throwable $exception) {
            $message = $this->sanitize($exception->getMessage(), $sensitiveValues);
            $fatal = $exception instanceof RuntimeException
                && $exception->getMessage() === 'Selected AI model configuration changed after run preparation.';
            $updated = DB::transaction(function () use ($run, $step, $claim, $message): bool {
                $locked = $this->lockClaim((int) $run->id, $claim);
                if ($locked === null) {
                    return false;
                }
                ContentGenerationRunStep::query()->whereKey($step->id)
                    ->whereIn('status', [ContentGenerationRunStep::STATUS_QUEUED, ContentGenerationRunStep::STATUS_RUNNING])
                    ->update([
                        'status' => ContentGenerationRunStep::STATUS_FAILED,
                        'error_message' => $message,
                        'completed_at' => now(),
                        'updated_at' => now(),
                    ]);
                $this->extendLease($locked);

                return true;
            });

            return [
                'ok' => false,
                'lost' => ! $updated,
                'fatal' => $fatal,
                'output' => '',
                'step' => $step->fresh() ?? $step,
                'exception' => new RuntimeException($message, (int) $exception->getCode(), $exception),
            ];
        }
    }

    /** @param array{token: string, version: int} $claim */
    private function completeStep(ContentGenerationRun $run, ContentGenerationRunStep $step, AgentResponse $response, string $output, array $claim, array $sensitiveValues): bool
    {
        return DB::transaction(function () use ($run, $step, $response, $output, $claim, $sensitiveValues): bool {
            $locked = $this->lockClaim((int) $run->id, $claim);
            if ($locked === null) {
                return false;
            }
            ContentGenerationRunStep::query()->whereKey($step->id)->update([
                'status' => ContentGenerationRunStep::STATUS_COMPLETED,
                'output' => $output,
                'metadata' => json_encode($this->redactor->value([
                    'invocation_id' => $response->invocationId,
                    'provider' => $response->meta->provider,
                    'model' => $response->meta->model,
                ], $sensitiveValues), JSON_THROW_ON_ERROR),
                'input_tokens' => $response->usage->promptTokens,
                'output_tokens' => $response->usage->completionTokens,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
            $this->extendLease($locked);

            return true;
        });
    }

    /** @param array<string, mixed> $blueprint @param array<string, mixed> $requirements */
    private function deterministicReview(
        ContentGenerationRun $run,
        ContentGenerationRunStep $step,
        string $article,
        array $blueprint,
        array $requirements,
        array $claim,
        bool $afterRevision = false,
    ): ?QualityGateResult {
        $result = $this->deterministicQualityGate->inspect($article, $blueprint, $requirements);
        $status = $result->passed
            ? ContentQualityReport::STATUS_PASSED
            : ($afterRevision ? ContentQualityReport::STATUS_MANUAL_REVIEW_REQUIRED : ContentQualityReport::STATUS_NEEDS_REVISION);
        $issues = $result->issuesAsArray();
        $report = $this->qualityReport(
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
            $claim,
        );

        return $report === null ? null : $result;
    }

    /**
     * @param  array<string, mixed>  $blueprint
     * @return array{passed: bool, score: float, issues: list<array{code: string, section_key: string|null, message: string, repair_instruction: string}>}|null
     */
    private function semanticReview(ContentGenerationRun $run, ContentGenerationRunStep $articleStep, array $blueprint, string $article, array $claim): ?array
    {
        $prompt = $this->semanticReviewer->prompt(
            $blueprint,
            (array) ($run->variables_snapshot['resolved_variables']['evidence'] ?? []),
            $article,
        );
        $lastException = null;
        $attempts = $run->steps()
            ->where('step_type', ContentGenerationRunStep::TYPE_SEMANTIC_QUALITY)
            ->get()
            ->filter(fn (ContentGenerationRunStep $step): bool => (int) ($step->input_snapshot['article_step_id'] ?? 0) === (int) $articleStep->id)
            ->values();
        $maxAttempt = 0;
        foreach ($attempts as $attemptStep) {
            $maxAttempt = max($maxAttempt, (int) ($attemptStep->input_snapshot['attempt'] ?? 0));
            $existingReport = $attemptStep->qualityReports()
                ->where('report_type', 'semantic')
                ->latest('id')
                ->first();
            if ($existingReport !== null && is_array($existingReport->semantic_results)) {
                return $existingReport->semantic_results;
            }
            if ($attemptStep->status !== ContentGenerationRunStep::STATUS_COMPLETED || $attemptStep->output === null) {
                continue;
            }

            try {
                $review = $this->semanticReviewer->parse((string) $attemptStep->output);
                if (! $this->persistSemanticReview($run, $attemptStep, $review, $claim)) {
                    return ['claim_lost' => true];
                }

                return $review;
            } catch (Throwable $exception) {
                $lastException = $exception;
                if (! $this->markStepParseFailed($run, $attemptStep, $exception, $claim)) {
                    return ['claim_lost' => true];
                }
            }
        }

        for ($attempt = $maxAttempt + 1; $attempt <= 2; $attempt++) {
            $interaction = $this->modelInteraction($run, ContentGenerationRunStep::TYPE_SEMANTIC_QUALITY, $prompt, [
                'article_step_id' => (int) $articleStep->id,
                'attempt' => $attempt,
            ], $claim);
            if ($interaction['lost']) {
                return ['claim_lost' => true];
            }
            if (! $interaction['ok']) {
                if ($interaction['fatal']) {
                    return ['fatal_exception' => $interaction['exception'], 'step' => $interaction['step']];
                }
                $lastException = $interaction['exception'];

                continue;
            }

            try {
                $review = $this->semanticReviewer->parse($interaction['output']);
                if (! $this->persistSemanticReview($run, $interaction['step'], $review, $claim)) {
                    return ['claim_lost' => true];
                }

                return $review;
            } catch (Throwable $exception) {
                $lastException = $exception;
                $marked = $this->markStepParseFailed($run, $interaction['step'], $exception, $claim);
                if (! $marked) {
                    return ['claim_lost' => true];
                }
            }
        }

        $existingFailure = $run->qualityReports()
            ->where('report_type', 'semantic')
            ->where('status', ContentQualityReport::STATUS_MODEL_QUALITY_FAILED)
            ->exists();
        if ($existingFailure) {
            return null;
        }

        $issue = [[
            'code' => 'semantic_review_parse_failed',
            'section_key' => null,
            'message' => 'Semantic review could not produce valid structured JSON after one retry.',
            'repair_instruction' => 'Complete semantic review manually.',
        ]];
        $report = $this->qualityReport(
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
            $claim,
        );

        return $report === null ? ['claim_lost' => true] : null;
    }

    /**
     * @param  array{passed: bool, score: float, issues: list<array<string, mixed>>}  $review
     * @param  array{token: string, version: int}  $claim
     */
    private function persistSemanticReview(ContentGenerationRun $run, ContentGenerationRunStep $step, array $review, array $claim): bool
    {
        $status = $review['passed']
            ? ContentQualityReport::STATUS_PASSED
            : ($this->semanticNeedsEvidence($review['issues'])
                ? ContentQualityReport::STATUS_NEEDS_EVIDENCE
                : ContentQualityReport::STATUS_NEEDS_REVISION);

        return $this->qualityReport(
            $run,
            $step,
            'semantic',
            $status,
            $review['passed'],
            $review['score'],
            null,
            $review,
            $review['issues'],
            $this->repairInstructions($review['issues']),
            $claim,
        ) !== null;
    }

    /** @param array<string, mixed> $blueprint @param array<string, mixed> $requirements @param list<array<string, mixed>> $issues */
    private function reviseOnce(ContentGenerationRun $run, array $blueprint, array $requirements, string $article, array $issues, array $claim): ContentGenerationRun
    {
        $instructions = $this->repairInstructions($issues);
        $prompt = "Revise only the problems named in REPAIR INSTRUCTIONS. Preserve every other valid passage and return only the complete revised Markdown article.\n\n"
            ."ORIGINAL BLUEPRINT\n".$this->json($blueprint)
            ."\n\nORIGINAL ARTICLE\n".$article
            ."\n\nREPAIR INSTRUCTIONS\n".$this->json($instructions);
        $revision = $this->modelInteraction($run, ContentGenerationRunStep::TYPE_TARGETED_REVISION, $prompt, [
            'blueprint' => $blueprint,
            'repair_instructions' => $instructions,
        ], $claim);
        if ($revision['lost']) {
            return $run->fresh() ?? $run;
        }
        if (! $revision['ok']) {
            return $this->failRun((int) $run->id, $revision['exception'], $revision['step'], $claim);
        }
        if (trim($revision['output']) === '') {
            return $this->failRun((int) $run->id, new RuntimeException('The model returned an empty response.'), $revision['step'], $claim);
        }

        if (! $this->updateRawOutput($run, $revision['output'], $claim)) {
            return $run->fresh() ?? $run;
        }
        $deterministic = $this->deterministicReview($run, $revision['step'], $revision['output'], $blueprint, $requirements, $claim, true);
        if ($deterministic === null) {
            return $run->fresh() ?? $run;
        }
        if (! $deterministic->passed) {
            return $this->finish($run, ContentGenerationRun::STATUS_MANUAL_REVIEW_REQUIRED, $claim);
        }

        $semantic = $this->semanticReview($run, $revision['step'], $blueprint, $revision['output'], $claim);
        if (($semantic['claim_lost'] ?? false) === true) {
            return $run->fresh() ?? $run;
        }
        if (isset($semantic['fatal_exception']) && $semantic['fatal_exception'] instanceof Throwable) {
            return $this->failRun((int) $run->id, $semantic['fatal_exception'], $semantic['step'] ?? null, $claim);
        }
        if ($semantic !== null && $this->semanticNeedsEvidence($semantic['issues'])) {
            return $this->finish($run, ContentGenerationRun::STATUS_NEEDS_EVIDENCE, $claim, 'Semantic review requires additional evidence.');
        }
        if ($semantic === null || ! $semantic['passed']) {
            return $this->finish($run, ContentGenerationRun::STATUS_MANUAL_REVIEW_REQUIRED, $claim);
        }

        return $this->finish($run, ContentGenerationRun::STATUS_READY_FOR_REVIEW, $claim);
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
        array $claim,
    ): ?ContentQualityReport {
        return DB::transaction(function () use ($run, $step, $type, $status, $passed, $score, $deterministic, $semantic, $issues, $instructions, $claim): ?ContentQualityReport {
            $locked = $this->lockClaim((int) $run->id, $claim);
            if ($locked === null) {
                return null;
            }
            $report = ContentQualityReport::query()->create([
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
            $this->extendLease($locked);

            return $report;
        });
    }

    /** @param array{token: string, version: int} $claim */
    private function finish(ContentGenerationRun $run, string $status, array $claim, ?string $failureMessage = null): ContentGenerationRun
    {
        $transitioned = false;
        $fresh = DB::transaction(function () use ($run, $status, $failureMessage, $claim, &$transitioned): ContentGenerationRun {
            $locked = $this->lockClaim((int) $run->id, $claim);
            if ($locked === null || in_array((string) $locked->status, self::TERMINAL_STATUSES, true)) {
                return ContentGenerationRun::query()->findOrFail($run->id);
            }
            $locked->forceFill([
                'status' => $status,
                'error_message' => $failureMessage === null ? null : $this->sanitize($failureMessage),
                'completed_at' => now(),
                'lease_expires_at' => null,
            ])->save();
            $transitioned = true;

            return $locked->fresh() ?? $locked;
        });

        if (! $transitioned) {
            return $fresh;
        }
        if ($status === ContentGenerationRun::STATUS_FAILED) {
            $this->dispatchSafely(new ContentGenerationFailed((int) $fresh->id, $status, (string) $fresh->error_message));
        } else {
            $this->dispatchSafely(new ContentGenerationCompleted((int) $fresh->id, $status));
        }

        return $fresh;
    }

    /** @param array{token: string, version: int} $claim */
    private function needsEvidence(int $runId, array $claim): ContentGenerationRun
    {
        $run = ContentGenerationRun::query()->findOrFail($runId);
        $issue = [[
            'code' => 'needs_evidence',
            'severity' => 'error',
            'section_key' => null,
            'message' => 'The selected prompt policy requires evidence, but the run has no evidence snapshot.',
            'repair_instruction' => 'Attach at least one reviewed evidence source and create a new generation run.',
        ]];
        $report = $this->qualityReport($run, null, 'preflight', ContentQualityReport::STATUS_NEEDS_EVIDENCE, false, 0, null, null, $issue, [
            'Attach reviewed evidence before generation.',
        ], $claim);
        if ($report === null) {
            return $run->fresh() ?? $run;
        }

        return $this->finish($run, ContentGenerationRun::STATUS_NEEDS_EVIDENCE, $claim, $issue[0]['message']);
    }

    /** @param array{token: string, version: int} $claim */
    private function failRun(int $runId, Throwable $exception, ?ContentGenerationRunStep $step, array $claim): ContentGenerationRun
    {
        $message = $this->sanitize($exception->getMessage() !== '' ? $exception->getMessage() : 'Content generation failed.');
        $transitioned = false;
        $run = DB::transaction(function () use ($runId, $message, $step, $claim, &$transitioned): ContentGenerationRun {
            $locked = $this->lockClaim($runId, $claim);
            if ($locked === null || in_array((string) $locked->status, self::TERMINAL_STATUSES, true)) {
                return ContentGenerationRun::query()->findOrFail($runId);
            }
            $locked->forceFill([
                'status' => ContentGenerationRun::STATUS_FAILED,
                'error_message' => $message,
                'completed_at' => now(),
                'lease_expires_at' => null,
            ])->save();
            if ($step !== null) {
                ContentGenerationRunStep::query()->whereKey($step->id)
                    ->whereIn('status', [ContentGenerationRunStep::STATUS_QUEUED, ContentGenerationRunStep::STATUS_RUNNING])
                    ->update([
                        'status' => ContentGenerationRunStep::STATUS_FAILED,
                        'error_message' => $message,
                        'completed_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
            $transitioned = true;

            return $locked->fresh() ?? $locked;
        });

        if ($transitioned) {
            $this->dispatchSafely(new ContentGenerationFailed((int) $run->id, (string) $run->status, $message));
        }

        return $run;
    }

    /** @param array{token: string, version: int} $claim */
    private function lockClaim(int $runId, array $claim): ?ContentGenerationRun
    {
        return ContentGenerationRun::query()
            ->whereKey($runId)
            ->where('claim_token', $claim['token'])
            ->where('lease_version', $claim['version'])
            ->lockForUpdate()
            ->first();
    }

    /** @param array{token: string, version: int} $claim */
    private function ownsClaim(int $runId, array $claim): bool
    {
        return ContentGenerationRun::query()
            ->whereKey($runId)
            ->where('claim_token', $claim['token'])
            ->where('lease_version', $claim['version'])
            ->exists();
    }

    private function extendLease(ContentGenerationRun $run): void
    {
        $run->forceFill(['lease_expires_at' => now()->addSeconds(self::STALE_RUN_SECONDS)])->save();
    }

    /** @param array{token: string, version: int} $claim */
    private function updateRawOutput(ContentGenerationRun $run, string $output, array $claim): bool
    {
        return DB::transaction(function () use ($run, $output, $claim): bool {
            $locked = $this->lockClaim((int) $run->id, $claim);
            if ($locked === null) {
                return false;
            }
            $locked->forceFill(['raw_output' => $output])->save();
            $this->extendLease($locked);

            return true;
        });
    }

    /** @param array{token: string, version: int} $claim */
    private function markStepParseFailed(ContentGenerationRun $run, ContentGenerationRunStep $step, Throwable $exception, array $claim): bool
    {
        return DB::transaction(function () use ($run, $claim): bool {
            $locked = $this->lockClaim((int) $run->id, $claim);
            if ($locked === null) {
                return false;
            }
            // Provider output and its completed step are immutable. The parse failure is
            // represented by the retry/final quality report instead of rewriting history.
            $this->extendLease($locked);

            return true;
        });
    }

    private function dispatchSafely(object $event): void
    {
        try {
            event($event);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** @param list<string> $sensitiveValues */
    private function sanitize(string $message, array $sensitiveValues = []): string
    {
        foreach ($sensitiveValues as $value) {
            if ($value !== '') {
                $message = str_replace($value, '[REDACTED]', $message);
            }
        }
        $message = preg_replace('/(authorization\s*:\s*bearer\s+)[^\s,;]+/iu', '$1[REDACTED]', $message) ?? $message;
        $message = preg_replace('/((?:api[_-]?key|access[_-]?token|secret)\s*[=:]\s*)[^\s,;]+/iu', '$1[REDACTED]', $message) ?? $message;
        $message = preg_replace('/\bsk-[A-Za-z0-9_-]+\b/u', '[REDACTED]', $message) ?? $message;

        return mb_substr(trim($message), 0, 2000);
    }

    /** @return array{0: string, 1: list<string>} */
    private function modelPayload(ContentGenerationRun $run): array
    {
        if ($run->encrypted_model_payload === null) {
            return [(string) $run->compiled_prompt, []];
        }
        $payload = json_decode(Crypt::decryptString((string) $run->encrypted_model_payload), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload) || ! is_string($payload['prompt'] ?? null)) {
            throw new RuntimeException('The encrypted model payload is invalid.');
        }

        return [
            $payload['prompt'],
            $this->redactor->valuesForRun($run),
        ];
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }
}
