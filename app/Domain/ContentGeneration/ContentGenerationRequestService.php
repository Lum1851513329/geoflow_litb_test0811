<?php

namespace App\Domain\ContentGeneration;

use App\Domain\PromptComposition\PromptPresetSelector;
use App\Models\Admin;
use App\Models\ContentBlueprintVersion;
use App\Models\ContentBrief;
use App\Models\ContentGenerationRun;
use App\Models\ContentStrategyVersion;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ContentGenerationRequestService
{
    public function __construct(private readonly PromptPresetSelector $presetSelector) {}

    /**
     * @param array{strategy_version_id: int, blueprint_version_id: int, preset_version_id: int, ai_model_id: int, idempotency_key?: string|null} $data
     * @return array{run: ContentGenerationRun, created: bool}
     */
    public function reserve(ContentBrief $brief, array $data, Admin $actor): array
    {
        $idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''));
        if ($idempotencyKey === '') {
            $idempotencyKey = 'content-generation-'.Str::uuid();
        }
        $fingerprint = $this->fingerprint((int) $brief->id, $data);

        try {
            return DB::transaction(function () use ($brief, $data, $actor, $idempotencyKey, $fingerprint): array {
                $lockedBrief = ContentBrief::query()->whereKey($brief->id)->lockForUpdate()->firstOrFail();
                $existing = ContentGenerationRun::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing !== null) {
                    $this->assertReplayMatches($existing, $actor, $fingerprint);

                    return ['run' => $existing, 'created' => false];
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
                if ($this->presetSelector->eligibleVersion($lockedBrief, $data['preset_version_id']) === null) {
                    throw ValidationException::withMessages([
                        'preset_version_id' => __('admin.content_strategy.validation.preset_unavailable'),
                    ]);
                }

                $run = ContentGenerationRun::forceCreate([
                    'content_brief_id' => $lockedBrief->id,
                    'strategy_version_id' => $strategy->id,
                    'blueprint_version_id' => $blueprint->id,
                    'preset_version_id' => $data['preset_version_id'],
                    'ai_model_id' => $data['ai_model_id'],
                    'compiled_prompt' => null,
                    'status' => ContentGenerationRun::STATUS_PENDING,
                    'idempotency_key' => $idempotencyKey,
                    'created_by_admin_id' => $actor->id,
                ]);

                return ['run' => $run, 'created' => true];
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = ContentGenerationRun::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing === null) {
                throw $exception;
            }
            $this->assertReplayMatches($existing, $actor, $fingerprint);

            return ['run' => $existing, 'created' => false];
        }
    }

    /** @param array{strategy_version_id: int, blueprint_version_id: int, preset_version_id: int, ai_model_id: int} $data */
    private function fingerprint(int $briefId, array $data): string
    {
        return hash('sha256', json_encode([
            'content_brief_id' => $briefId,
            'strategy_version_id' => (int) $data['strategy_version_id'],
            'blueprint_version_id' => (int) $data['blueprint_version_id'],
            'preset_version_id' => (int) $data['preset_version_id'],
            'ai_model_id' => (int) $data['ai_model_id'],
        ], JSON_THROW_ON_ERROR));
    }

    private function assertReplayMatches(ContentGenerationRun $existing, Admin $actor, string $fingerprint): void
    {
        if ((int) $existing->created_by_admin_id !== (int) $actor->id) {
            throw ValidationException::withMessages([
                'idempotency_key' => __('admin.content_strategy.validation.idempotency_actor_conflict'),
            ]);
        }
        $existingFingerprint = $this->fingerprint((int) $existing->content_brief_id, [
            'strategy_version_id' => (int) $existing->strategy_version_id,
            'blueprint_version_id' => (int) $existing->blueprint_version_id,
            'preset_version_id' => (int) $existing->preset_version_id,
            'ai_model_id' => (int) $existing->ai_model_id,
        ]);
        if (! hash_equals($existingFingerprint, $fingerprint)) {
            throw ValidationException::withMessages([
                'idempotency_key' => __('admin.content_strategy.validation.idempotency_configuration_conflict'),
            ]);
        }
    }
}
