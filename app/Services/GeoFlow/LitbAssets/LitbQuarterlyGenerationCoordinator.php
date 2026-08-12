<?php

namespace App\Services\GeoFlow\LitbAssets;

use App\Domain\ContentGeneration\ContentGenerationRequestService;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\ContentBlueprintVersion;
use App\Models\ContentBrief;
use App\Models\ContentGenerationRun;
use App\Models\ContentStrategyVersion;
use App\Models\KnowledgeChunk;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class LitbGenerationCandidate
{
    /** @param list<int> $knowledgeChunkIds */
    public function __construct(
        public readonly ContentBrief $brief,
        public readonly ContentStrategyVersion $strategy,
        public readonly ContentBlueprintVersion $blueprint,
        public readonly PromptPresetVersion $presetVersion,
        public readonly AiModel $model,
        public readonly array $knowledgeChunkIds,
    ) {}
}

final class LitbQuarterlyGenerationCoordinator
{
    private const MAX_EVIDENCE_CHUNKS_PER_GROUP = 6;

    public function __construct(
        private readonly LitbAssetConfig $config,
        private readonly ContentGenerationRequestService $requestService,
    ) {}

    /** @param array{quarter_key?: string, limit?: int, pillar?: string, brief?: string, model?: int} $filters */
    public function preview(array $filters): LitbGenerationBatchPreview
    {
        $limit = max(1, (int) ($filters['limit'] ?? 10));
        $eligible = [];
        $skipped = [];

        foreach ($this->candidateBriefs($filters) as $brief) {
            if (count($eligible) >= $limit) {
                break;
            }
            $outcome = $this->resolve($brief, $filters);
            if ($outcome instanceof LitbGenerationCandidate) {
                $eligible[] = ['asset_key' => $brief->asset_key, 'brief_id' => (int) $brief->id];
            } else {
                $skipped[] = ['asset_key' => $brief->asset_key, 'reason' => $outcome];
            }
        }

        return new LitbGenerationBatchPreview($eligible, $skipped);
    }

    /** @param array{quarter_key?: string, limit?: int, pillar?: string, brief?: string, model?: int} $filters */
    public function dispatch(array $filters, int $adminId): LitbGenerationBatchResult
    {
        $actor = Admin::query()->findOrFail($adminId);
        $limit = max(1, (int) ($filters['limit'] ?? 10));
        $runIds = [];
        $existingRunIds = [];
        $skipped = [];
        $dispatchedCount = 0;

        foreach ($this->candidateBriefs($filters) as $brief) {
            if ($dispatchedCount >= $limit) {
                break;
            }
            $outcome = $this->resolve($brief, $filters);
            if (! $outcome instanceof LitbGenerationCandidate) {
                $skipped[] = ['asset_key' => $brief->asset_key, 'reason' => $outcome];

                continue;
            }
            $dispatchedCount++;

            try {
                $result = $this->requestService->reserve($outcome->brief, [
                    'strategy_version_id' => $outcome->strategy->id,
                    'blueprint_version_id' => $outcome->blueprint->id,
                    'preset_version_id' => $outcome->presetVersion->id,
                    'ai_model_id' => $outcome->model->id,
                    'knowledge_chunk_ids' => $outcome->knowledgeChunkIds,
                    'idempotency_key' => $this->idempotencyKey($outcome),
                ], $actor);
            } catch (ValidationException $exception) {
                $skipped[] = ['asset_key' => $brief->asset_key, 'reason' => 'validation_error:'.$exception->getMessage()];

                continue;
            }

            if ($result['created']) {
                $runIds[] = (int) $result['run']->id;
            } else {
                $existingRunIds[] = (int) $result['run']->id;
            }
        }

        return new LitbGenerationBatchResult($runIds, $existingRunIds, $skipped);
    }

    /** @param array{quarter_key?: string, pillar?: string, brief?: string} $filters @return Collection<int, ContentBrief> */
    private function candidateBriefs(array $filters): Collection
    {
        $quarterKey = trim((string) ($filters['quarter_key'] ?? $this->config->quarterKey()));

        $query = ContentBrief::query()
            ->where('asset_key', 'like', $quarterKey.'.%')
            ->orderBy('asset_key');

        if (! empty($filters['brief'])) {
            $query->where('asset_key', (string) $filters['brief']);
        }

        return $query->get()->filter(function (ContentBrief $brief) use ($filters): bool {
            if (empty($filters['pillar'])) {
                return true;
            }

            return (string) ($brief->asset_provenance['pillar'] ?? '') === (string) $filters['pillar'];
        })->values();
    }

    /** @param array{model?: int} $filters */
    private function resolve(ContentBrief $brief, array $filters): LitbGenerationCandidate|string
    {
        $strategy = $brief->strategyVersions()
            ->where('lifecycle_status', ContentStrategyVersion::STATUS_CONFIRMED)
            ->latest('version')
            ->first();
        if ($strategy === null) {
            return 'strategy_not_confirmed';
        }
        $blueprint = $brief->blueprintVersions()
            ->where('content_strategy_version_id', $strategy->id)
            ->where('lifecycle_status', ContentBlueprintVersion::STATUS_CONFIRMED)
            ->latest('version')
            ->first();
        if ($blueprint === null) {
            return 'blueprint_not_confirmed';
        }

        $riskFlags = (array) ($brief->asset_provenance['risk_flags'] ?? []);
        if (in_array('needs_current_product_evidence', $riskFlags, true)
            || in_array('needs_current_official_evidence', $riskFlags, true)) {
            return 'needs_evidence';
        }

        $knowledgeChunkIds = $this->reviewedEvidenceFor($brief);
        if ($knowledgeChunkIds === null) {
            return 'needs_evidence';
        }

        $presetKey = trim((string) ($brief->asset_provenance['prompt_preset_key'] ?? ''));
        $presetVersion = $presetKey === '' ? null : PromptPresetVersion::query()
            ->whereHas('preset', fn ($query) => $query->where('preset_key', $presetKey)->where('is_enabled', true))
            ->where('lifecycle_status', PromptPresetVersion::STATUS_PRODUCTION)
            ->latest('version')
            ->first();
        if ($presetVersion === null) {
            return 'preset_unavailable';
        }

        $model = $this->activeModel($filters['model'] ?? null);
        if ($model === null) {
            return 'no_active_model';
        }

        if ($this->alreadyGenerated($brief, $strategy, $blueprint, $presetVersion, $model)) {
            return 'already_generated';
        }

        return new LitbGenerationCandidate($brief, $strategy, $blueprint, $presetVersion, $model, $knowledgeChunkIds);
    }

    /** @return list<int>|null */
    private function reviewedEvidenceFor(ContentBrief $brief): ?array
    {
        $groupKeys = (array) ($brief->asset_provenance['evidence_group_keys'] ?? []);
        if ($groupKeys === []) {
            return [];
        }

        $chunkIds = [];
        foreach ($groupKeys as $groupKey) {
            $ids = KnowledgeChunk::query()
                ->whereHas('knowledgeBase', function ($query) use ($groupKey): void {
                    $query->where('review_status', 'reviewed')
                        ->where('asset_provenance->group_key', (string) $groupKey);
                })
                ->orderBy('id')
                ->limit(self::MAX_EVIDENCE_CHUNKS_PER_GROUP)
                ->pluck('id');
            if ($ids->isEmpty()) {
                return null;
            }
            foreach ($ids as $id) {
                $chunkIds[(int) $id] = true;
            }
        }

        return array_keys($chunkIds);
    }

    private function activeModel(?int $preferredModelId): ?AiModel
    {
        $query = AiModel::query()->where('status', 'active')->where('model_type', '!=', 'embedding');
        if ($preferredModelId !== null) {
            $query->whereKey($preferredModelId);
        }

        return $query->orderBy('id')->first();
    }

    private function alreadyGenerated(
        ContentBrief $brief,
        ContentStrategyVersion $strategy,
        ContentBlueprintVersion $blueprint,
        PromptPresetVersion $presetVersion,
        AiModel $model,
    ): bool {
        return ContentGenerationRun::query()
            ->where('content_brief_id', $brief->id)
            ->where('strategy_version_id', $strategy->id)
            ->where('blueprint_version_id', $blueprint->id)
            ->where('preset_version_id', $presetVersion->id)
            ->where('ai_model_id', $model->id)
            ->whereIn('status', [
                ContentGenerationRun::STATUS_READY_FOR_REVIEW,
                ContentGenerationRun::STATUS_COMPLETED,
                ContentGenerationRun::STATUS_ADOPTED,
            ])
            ->exists();
    }

    private function idempotencyKey(LitbGenerationCandidate $candidate): string
    {
        $key = $candidate->brief->asset_key.'.'.$candidate->presetVersion->id.'.'.$candidate->model->id;
        if (strlen($key) > 150) {
            throw new InvalidArgumentException('litb_generation.idempotency_key_too_long');
        }

        return $key;
    }
}
