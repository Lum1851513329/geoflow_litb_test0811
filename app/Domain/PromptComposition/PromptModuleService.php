<?php

namespace App\Domain\PromptComposition;

use App\Exceptions\ImmutablePromptVersionException;
use App\Models\Admin;
use App\Models\PromptModule;
use App\Models\PromptModuleVersion;
use App\Models\PromptPresetVersion;
use App\Support\AdminActivityLogger;
use Illuminate\Support\Facades\DB;

final class PromptModuleService
{
    /** @param array<string, mixed> $identity @param array<string, mixed> $version */
    public function createModule(array $identity, array $version, Admin $actor): PromptModule
    {
        $module = DB::transaction(function () use ($identity, $version, $actor): PromptModule {
            $module = PromptModule::query()->create([
                'module_key' => $identity['module_key'],
                'display_name' => $identity['display_name'],
                'module_type' => $identity['module_type'],
                'description' => $identity['description'] ?? null,
                'is_system' => $identity['is_system'] ?? false,
                'applicability' => $identity['applicability'] ?? null,
                'is_mandatory' => $identity['is_mandatory'] ?? false,
                'default_priority' => $identity['default_priority'] ?? 0,
                'is_enabled' => $identity['is_enabled'] ?? true,
                'owned_by_admin_id' => $actor->id,
            ]);

            $lockedModule = PromptModule::query()->whereKey($module->getKey())->lockForUpdate()->firstOrFail();
            $createdVersion = $this->createVersionForLockedModule($lockedModule, $version, $actor);
            $module->forceFill(['current_version_id' => $createdVersion->id])->save();

            return $module;
        });

        $this->log($actor, 'prompt_module:created', $module, $module->currentVersion);

        return $module;
    }

    /** @param array<string, mixed> $data */
    public function createVersion(PromptModule $module, array $data, Admin $actor): PromptModuleVersion
    {
        $version = DB::transaction(function () use ($module, $data, $actor): PromptModuleVersion {
            $lockedModule = PromptModule::query()->whereKey($module->getKey())->lockForUpdate()->firstOrFail();

            return $this->createVersionForLockedModule($lockedModule, $data, $actor);
        });

        $this->log($actor, 'prompt_module_version:created', $module, $version);

        return $version;
    }

    public function publish(PromptModuleVersion $version, Admin $actor, string $reason): PromptModuleVersion
    {
        $published = DB::transaction(function () use ($version, $reason): PromptModuleVersion {
            $lockedVersion = PromptModuleVersion::query()->whereKey($version->getKey())->lockForUpdate()->firstOrFail();
            $module = PromptModule::query()->whereKey($lockedVersion->prompt_module_id)->lockForUpdate()->firstOrFail();
            if ($lockedVersion->lifecycle_status === PromptModuleVersion::STATUS_ARCHIVED) {
                throw ImmutablePromptVersionException::cannotPublishArchived((int) $lockedVersion->id);
            }

            PromptModuleVersion::query()
                ->where('prompt_module_id', $module->id)
                ->where('lifecycle_status', PromptModuleVersion::STATUS_PRODUCTION)
                ->whereKeyNot($lockedVersion->id)
                ->lockForUpdate()
                ->get()
                ->each(fn (PromptModuleVersion $production) => $production->update([
                    'lifecycle_status' => PromptModuleVersion::STATUS_DEPRECATED,
                ]));

            $lockedVersion->update([
                'lifecycle_status' => PromptModuleVersion::STATUS_PRODUCTION,
                'change_note' => $reason,
                'published_at' => now(),
            ]);
            $module->forceFill(['current_version_id' => $lockedVersion->id])->save();

            return $lockedVersion->fresh();
        });

        $this->log($actor, 'prompt_module_version:published', $published->module, $published, $reason);

        return $published;
    }

    public function archive(PromptModuleVersion $version, Admin $actor, string $reason): PromptModuleVersion
    {
        $archived = DB::transaction(function () use ($version, $reason): PromptModuleVersion {
            $lockedVersion = PromptModuleVersion::query()->whereKey($version->getKey())->lockForUpdate()->firstOrFail();
            $module = PromptModule::query()->whereKey($lockedVersion->prompt_module_id)->lockForUpdate()->firstOrFail();

            $hasActivePresetDependency = $lockedVersion->presetBindings()
                ->whereHas('presetVersion', fn ($query) => $query->where(
                    'lifecycle_status',
                    PromptPresetVersion::STATUS_PRODUCTION,
                ))
                ->exists();
            if ($hasActivePresetDependency) {
                throw ImmutablePromptVersionException::hasActivePresetDependency((int) $lockedVersion->id);
            }

            $lockedVersion->update([
                'lifecycle_status' => PromptModuleVersion::STATUS_ARCHIVED,
                'change_note' => $reason,
            ]);
            if ((int) $module->current_version_id === (int) $lockedVersion->id) {
                $module->forceFill(['current_version_id' => null])->save();
            }

            return $lockedVersion->fresh();
        });

        $this->log($actor, 'prompt_module_version:archived', $archived->module, $archived, $reason);

        return $archived;
    }

    /** @param array<string, mixed> $data */
    private function createVersionForLockedModule(PromptModule $module, array $data, Admin $actor): PromptModuleVersion
    {
        $nextVersion = (int) $module->versions()->max('version') + 1;
        $content = (string) $data['content'];

        return PromptModuleVersion::forceCreate([
            'prompt_module_id' => $module->id,
            'version' => $nextVersion,
            'content' => $content,
            'variable_contract' => $data['variable_contract'],
            'output_contract' => $data['output_contract'] ?? null,
            'parameter_defaults' => $data['parameter_defaults'] ?? null,
            'content_sha256' => hash('sha256', $content),
            'lifecycle_status' => PromptModuleVersion::STATUS_DRAFT,
            'change_note' => $data['change_note'] ?? null,
            'created_by_admin_id' => $actor->id,
        ]);
    }

    private function log(
        Admin $actor,
        string $action,
        PromptModule $module,
        ?PromptModuleVersion $version,
        ?string $reason = null,
    ): void {
        AdminActivityLogger::log($actor, $action, [
            'target_type' => 'prompt_module_version',
            'target_id' => $version?->id,
            'details' => array_filter([
                'module_key' => $module->module_key,
                'version' => $version?->version,
                'lifecycle_status' => $version?->lifecycle_status,
                'content_sha256' => $version?->content_sha256,
                'content_length' => $version === null ? null : mb_strlen($version->content),
                'reason_sha256' => $reason === null ? null : hash('sha256', $reason),
                'reason_length' => $reason === null ? null : mb_strlen($reason),
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }
}
