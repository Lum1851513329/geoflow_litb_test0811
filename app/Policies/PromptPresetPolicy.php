<?php

namespace App\Policies;

use App\Models\Admin;
use App\Models\PromptPreset;
use App\Models\PromptPresetVersion;

final class PromptPresetPolicy
{
    public function viewAny(Admin $admin): bool
    {
        return $admin->status === 'active';
    }

    public function view(Admin $admin, PromptPreset $preset): bool
    {
        return $admin->status === 'active';
    }

    public function create(Admin $admin): bool
    {
        return $admin->status === 'active';
    }

    public function update(Admin $admin, PromptPreset $preset): bool
    {
        return $admin->status === 'active'
            && ($admin->isSuperAdmin() || (! $preset->is_system && (int) $preset->owned_by_admin_id === (int) $admin->id));
    }

    public function publishVersion(Admin $admin, PromptPreset $preset, PromptPresetVersion $version): bool
    {
        return (int) $version->prompt_preset_id === (int) $preset->id
            && $admin->status === 'active'
            && $admin->canManageProtectedWorkflows();
    }

    public function deprecateVersion(Admin $admin, PromptPreset $preset, PromptPresetVersion $version): bool
    {
        return $this->publishVersion($admin, $preset, $version);
    }

    public function archiveVersion(Admin $admin, PromptPreset $preset, PromptPresetVersion $version): bool
    {
        return $this->publishVersion($admin, $preset, $version);
    }
}
