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
        return $admin->status === 'active' && (! $preset->is_system || $admin->isSuperAdmin());
    }

    public function publishVersion(Admin $admin, PromptPreset $preset, PromptPresetVersion $version): bool
    {
        return (int) $version->prompt_preset_id === (int) $preset->id
            && $this->update($admin, $preset);
    }
}
