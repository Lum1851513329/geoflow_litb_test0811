<?php

namespace App\Domain\PromptComposition;

use App\Models\Admin;
use App\Models\PromptModule;
use App\Models\PromptModuleVersion;
use App\Models\PromptPreset;
use App\Models\PromptPresetModuleVersion;
use App\Models\PromptPresetVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class PromptPresetService
{
    /**
     * @param array<string, mixed> $identity
     * @param array<string, mixed> $version
     * @param list<array<string, mixed>> $bindings
     */
    public function createPreset(array $identity, array $version, array $bindings, Admin $actor): PromptPreset
    {
        return DB::transaction(function () use ($identity, $version, $bindings, $actor): PromptPreset {
            $preset = PromptPreset::query()->create([
                'preset_key' => $identity['preset_key'],
                'display_name' => $identity['display_name'],
                'description' => $identity['description'] ?? null,
                'is_system' => $identity['is_system'] ?? false,
                'is_enabled' => $identity['is_enabled'] ?? true,
                'owned_by_admin_id' => $actor->id,
            ]);
            $lockedPreset = $this->lockPreset($preset);
            $createdVersion = $this->createVersionForLockedPreset($lockedPreset, $version, $bindings, $actor);
            $lockedPreset->forceFill(['current_version_id' => $createdVersion->id])->save();

            return $lockedPreset->fresh();
        });
    }

    /**
     * @param array<string, mixed> $data
     * @param list<array<string, mixed>> $bindings
     */
    public function createVersion(PromptPreset $preset, array $data, array $bindings, Admin $actor): PromptPresetVersion
    {
        return DB::transaction(function () use ($preset, $data, $bindings, $actor): PromptPresetVersion {
            return $this->createVersionForLockedPreset($this->lockPreset($preset), $data, $bindings, $actor);
        });
    }

    public function publish(PromptPresetVersion $version, Admin $actor, string $reason): PromptPresetVersion
    {
        return DB::transaction(function () use ($version, $reason): PromptPresetVersion {
            $preset = PromptPreset::query()->whereKey($version->prompt_preset_id)->lockForUpdate()->firstOrFail();
            $lockedVersion = PromptPresetVersion::query()
                ->whereKey($version->getKey())
                ->where('prompt_preset_id', $preset->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($lockedVersion->lifecycle_status === PromptPresetVersion::STATUS_ARCHIVED) {
                throw new InvalidArgumentException("Archived prompt preset version {$lockedVersion->id} cannot be published.");
            }

            $bindings = PromptPresetModuleVersion::query()
                ->where('prompt_preset_version_id', $lockedVersion->id)
                ->orderBy('sort_order')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
            $boundVersions = $this->lockBoundModuleVersions($bindings);
            $this->assertNonArchived($boundVersions);

            PromptPresetVersion::query()
                ->where('prompt_preset_id', $preset->id)
                ->where('lifecycle_status', PromptPresetVersion::STATUS_PRODUCTION)
                ->whereKeyNot($lockedVersion->id)
                ->lockForUpdate()
                ->get()
                ->each(fn (PromptPresetVersion $production) => $production->update([
                    'lifecycle_status' => PromptPresetVersion::STATUS_DEPRECATED,
                ]));

            $lockedVersion->update([
                'lifecycle_status' => PromptPresetVersion::STATUS_PRODUCTION,
                'change_note' => $reason,
                'published_at' => now(),
            ]);
            $preset->forceFill(['current_version_id' => $lockedVersion->id])->save();

            return $lockedVersion->fresh();
        });
    }

    /**
     * @param array<string, mixed> $data
     * @param list<array<string, mixed>> $bindings
     */
    private function createVersionForLockedPreset(
        PromptPreset $preset,
        array $data,
        array $bindings,
        Admin $actor,
    ): PromptPresetVersion {
        $boundVersions = $this->lockBoundModuleVersionsFromData($bindings);
        $this->assertNonArchived($boundVersions);
        $createdVersion = PromptPresetVersion::forceCreate([
            'prompt_preset_id' => $preset->id,
            'version' => (int) $preset->versions()->max('version') + 1,
            'matching_rules' => $data['matching_rules'],
            'generation_policy' => $data['generation_policy'],
            'conflict_policy' => $data['conflict_policy'] ?? null,
            'lifecycle_status' => PromptPresetVersion::STATUS_DRAFT,
            'change_note' => $data['change_note'] ?? null,
            'created_by_admin_id' => $actor->id,
        ]);

        foreach ($bindings as $binding) {
            PromptPresetModuleVersion::query()->create([
                'prompt_preset_version_id' => $createdVersion->id,
                'prompt_module_version_id' => $binding['prompt_module_version_id'],
                'sort_order' => $binding['sort_order'],
                'is_required' => $binding['is_required'] ?? false,
                'selection_source' => $binding['selection_source'] ?? 'preset',
                'configuration' => $binding['configuration'] ?? null,
            ]);
        }

        return $createdVersion;
    }

    private function lockPreset(PromptPreset $preset): PromptPreset
    {
        return PromptPreset::query()->whereKey($preset->getKey())->lockForUpdate()->firstOrFail();
    }

    /** @param list<array<string, mixed>> $bindings @return Collection<int, PromptModuleVersion> */
    private function lockBoundModuleVersionsFromData(array $bindings): Collection
    {
        return $this->lockBoundModuleVersions(collect($bindings)->map(
            fn (array $binding): PromptPresetModuleVersion => new PromptPresetModuleVersion([
                'prompt_module_version_id' => $binding['prompt_module_version_id'],
            ]),
        ));
    }

    /** @param Collection<int, PromptPresetModuleVersion> $bindings @return Collection<int, PromptModuleVersion> */
    private function lockBoundModuleVersions(Collection $bindings): Collection
    {
        $versionIds = $bindings->pluck('prompt_module_version_id')->map(
            fn (mixed $id): int => (int) $id,
        )->unique()->sort()->values();
        if ($versionIds->isEmpty()) {
            return collect();
        }

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
            ->get()
            ->keyBy('id');
        if ($versions->count() !== $versionIds->count()) {
            throw new InvalidArgumentException('Prompt preset bindings must reference existing module versions.');
        }

        return $versions;
    }

    /** @param Collection<int, PromptModuleVersion> $versions */
    private function assertNonArchived(Collection $versions): void
    {
        $archived = $versions->first(
            fn (PromptModuleVersion $version): bool => $version->lifecycle_status === PromptModuleVersion::STATUS_ARCHIVED,
        );
        if ($archived !== null) {
            throw new InvalidArgumentException("Archived prompt module version {$archived->id} cannot be bound to a preset.");
        }
    }
}
