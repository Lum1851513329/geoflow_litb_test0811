<?php

namespace App\Domain\ContentGeneration;

use App\Domain\PromptComposition\MandatoryPromptModuleGuard;
use App\Domain\PromptComposition\PromptModuleResolver;
use App\Domain\PromptComposition\PromptPresetSelector;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\ContentBlueprintVersion;
use App\Models\ContentBrief;
use App\Models\ContentGenerationRun;
use App\Models\ContentStrategyVersion;
use App\Models\PromptModule;
use App\Models\PromptModuleVersion;
use App\Models\PromptPreset;
use App\Models\PromptPresetModuleVersion;
use App\Models\PromptPresetVersion;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ContentGenerationRequestService
{
    public function __construct(
        private readonly PromptPresetSelector $presetSelector,
        private readonly PromptModuleResolver $moduleResolver,
        private readonly GenerationEvidenceSnapshotter $evidenceSnapshotter,
        private readonly MandatoryPromptModuleGuard $mandatoryModules,
        private readonly ContentGenerationDispatcher $dispatcher,
    ) {}

    /**
     * @param  array{strategy_version_id: int, blueprint_version_id: int, preset_version_id: int, ai_model_id: int, idempotency_key?: string|null}  $data
     * @return array{run: ContentGenerationRun, created: bool, dispatch_required: bool, dispatched: bool}
     */
    public function reserve(ContentBrief $brief, array $data, Admin $actor): array
    {
        $idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') {
            $idempotencyKey = 'content-generation-'.Str::uuid();
        }
        $fingerprint = $this->fingerprint((int) $brief->id, $data, true);
        $baseFingerprint = $this->fingerprint((int) $brief->id, $data, false);

        try {
            return DB::transaction(function () use ($brief, $data, $actor, $idempotencyKey, $fingerprint, $baseFingerprint): array {
                $lockedBrief = ContentBrief::query()->whereKey($brief->id)->lockForUpdate()->firstOrFail();
                $existing = ContentGenerationRun::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing !== null) {
                    $this->assertReplayMatches($existing, $actor, $fingerprint, $baseFingerprint);

                    $dispatched = $this->dispatcher->dispatch($existing);

                    return [
                        'run' => $existing,
                        'created' => false,
                        'dispatch_required' => false,
                        'dispatched' => $dispatched,
                    ];
                }

                $strategy = $lockedBrief->strategyVersions()
                    ->whereKey($data['strategy_version_id'])
                    ->where('lifecycle_status', ContentStrategyVersion::STATUS_CONFIRMED)
                    ->first();
                if ($strategy === null) {
                    throw ValidationException::withMessages([
                        'strategy_version_id' => __('admin.content_strategy.validation.strategy_required'),
                    ]);
                }
                $blueprint = $lockedBrief->blueprintVersions()
                    ->whereKey($data['blueprint_version_id'])
                    ->where('content_strategy_version_id', $strategy->id)
                    ->where('lifecycle_status', ContentBlueprintVersion::STATUS_CONFIRMED)
                    ->first();
                if ($blueprint === null) {
                    throw ValidationException::withMessages([
                        'blueprint_version_id' => __('admin.content_strategy.validation.blueprint_required'),
                    ]);
                }
                $presetVersion = $this->lockAndResolvePresetModules($lockedBrief, (int) $data['preset_version_id']);
                $model = AiModel::query()->whereKey($data['ai_model_id'])->lockForUpdate()->first();
                if ($model === null
                    || (string) $model->status !== 'active'
                    || (string) $model->model_type === 'embedding') {
                    throw ValidationException::withMessages([
                        'ai_model_id' => __('admin.content_strategy.validation.model_unavailable'),
                    ]);
                }

                $run = ContentGenerationRun::forceCreate([
                    'content_brief_id' => $lockedBrief->id,
                    'strategy_version_id' => $strategy->id,
                    'blueprint_version_id' => $blueprint->id,
                    'preset_version_id' => $presetVersion->id,
                    'ai_model_id' => $model->id,
                    'compiled_prompt' => null,
                    'status' => ContentGenerationRun::STATUS_PENDING,
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $fingerprint,
                    'created_by_admin_id' => $actor->id,
                ]);
                $this->evidenceSnapshotter->snapshot(
                    $run,
                    array_values((array) ($data['knowledge_chunk_ids'] ?? [])),
                    array_values((array) ($data['evidence'] ?? [])),
                    $this->requiredFactIds($blueprint->blueprint),
                    $actor,
                    array_values((array) ($data['excluded_knowledge_chunk_ids'] ?? [])),
                    true,
                );
                $this->dispatcher->dispatch($run);

                return ['run' => $run->fresh() ?? $run, 'created' => true, 'dispatch_required' => false, 'dispatched' => true];
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = ContentGenerationRun::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing === null) {
                throw $exception;
            }
            $this->assertReplayMatches($existing, $actor, $fingerprint, $baseFingerprint);

            return DB::transaction(function () use ($existing): array {
                $dispatched = $this->dispatcher->dispatch($existing);

                return ['run' => $existing->fresh() ?? $existing, 'created' => false, 'dispatch_required' => false, 'dispatched' => $dispatched];
            });
        }
    }

    /** @param array<string, mixed> $blueprint @return list<string> */
    private function requiredFactIds(array $blueprint): array
    {
        $ids = [];
        foreach ((array) ($blueprint['sections'] ?? []) as $section) {
            if (! is_array($section)) {
                continue;
            }
            foreach ((array) ($section['required_fact_ids'] ?? []) as $id) {
                if (is_string($id) && trim($id) !== '') {
                    $ids[trim($id)] = true;
                }
            }
        }

        return array_keys($ids);
    }

    private function lockAndResolvePresetModules(ContentBrief $brief, int $presetVersionId): PromptPresetVersion
    {
        $presetId = PromptPresetVersion::query()
            ->whereKey($presetVersionId)
            ->value('prompt_preset_id');
        if ($presetId === null) {
            throw ValidationException::withMessages([
                'preset_version_id' => __('admin.content_strategy.validation.preset_unavailable'),
            ]);
        }

        $preset = PromptPreset::query()->whereKey($presetId)->lockForUpdate()->first();
        $version = PromptPresetVersion::query()
            ->whereKey($presetVersionId)
            ->where('prompt_preset_id', $presetId)
            ->lockForUpdate()
            ->first();
        if ($preset === null || $version === null) {
            throw ValidationException::withMessages([
                'preset_version_id' => __('admin.content_strategy.validation.preset_unavailable'),
            ]);
        }
        $version->setRelation('preset', $preset);
        if (! $this->presetSelector->isEligible($brief, $version)) {
            throw ValidationException::withMessages([
                'preset_version_id' => __('admin.content_strategy.validation.preset_unavailable'),
            ]);
        }

        $bindings = PromptPresetModuleVersion::query()
            ->where('prompt_preset_version_id', $version->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $versionIds = $bindings->pluck('prompt_module_version_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();
        if ($versionIds->isNotEmpty()) {
            $modules = PromptModule::query()
                ->whereHas('versions', fn ($query) => $query->whereIn('id', $versionIds))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $versions = PromptModuleVersion::query()
                ->whereIn('id', $versionIds)
                ->whereIn('prompt_module_id', $modules->modelKeys())
                ->orderBy('prompt_module_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($versions->count() !== $versionIds->count()) {
                throw ValidationException::withMessages([
                    'preset_version_id' => __('admin.content_strategy.validation.modules_unavailable'),
                ]);
            }
        }

        try {
            $this->mandatoryModules->assertComplete($version, $this->presetSelector->context($brief));
            $this->moduleResolver->resolve($version, $this->presetSelector->context($brief));
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages([
                'preset_version_id' => __('admin.content_strategy.validation.modules_unavailable'),
            ]);
        }

        return $version;
    }

    /** @param array{strategy_version_id: int, blueprint_version_id: int, preset_version_id: int, ai_model_id: int} $data */
    private function fingerprint(int $briefId, array $data, bool $includeEvidence): string
    {
        $payload = [
            'content_brief_id' => $briefId,
            'strategy_version_id' => (int) $data['strategy_version_id'],
            'blueprint_version_id' => (int) $data['blueprint_version_id'],
            'preset_version_id' => (int) $data['preset_version_id'],
            'ai_model_id' => (int) $data['ai_model_id'],
        ];
        if ($includeEvidence) {
            $knowledgeIds = array_values(array_unique(array_map('intval', (array) ($data['knowledge_chunk_ids'] ?? []))));
            sort($knowledgeIds, SORT_NUMERIC);
            $payload['knowledge_chunk_ids'] = $knowledgeIds;
            $payload['evidence'] = array_values((array) ($data['evidence'] ?? []));
            if (! empty($data['excluded_knowledge_chunk_ids'])) {
                $excluded = array_values(array_unique(array_map('intval', (array) $data['excluded_knowledge_chunk_ids'])));
                sort($excluded, SORT_NUMERIC);
                $payload['excluded_knowledge_chunk_ids'] = $excluded;
            }
        }

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function assertReplayMatches(ContentGenerationRun $existing, Admin $actor, string $fingerprint, string $baseFingerprint): void
    {
        if ((int) $existing->created_by_admin_id !== (int) $actor->id) {
            throw ValidationException::withMessages([
                'idempotency_key' => __('admin.content_strategy.validation.idempotency_actor_conflict'),
            ]);
        }
        if (is_string($existing->request_fingerprint) && $existing->request_fingerprint !== '') {
            $existingFingerprint = $existing->request_fingerprint;
            $expectedFingerprint = $fingerprint;
        } else {
            $existingFingerprint = $this->fingerprint((int) $existing->content_brief_id, [
                'strategy_version_id' => (int) $existing->strategy_version_id,
                'blueprint_version_id' => (int) $existing->blueprint_version_id,
                'preset_version_id' => (int) $existing->preset_version_id,
                'ai_model_id' => (int) $existing->ai_model_id,
            ], false);
            $expectedFingerprint = $baseFingerprint;
        }
        if (! hash_equals($existingFingerprint, $expectedFingerprint)) {
            throw ValidationException::withMessages([
                'idempotency_key' => __('admin.content_strategy.validation.idempotency_configuration_conflict'),
            ]);
        }
    }
}
