<?php

namespace App\Policies;

use App\Models\Admin;
use App\Models\PromptModule;
use App\Models\PromptModuleVersion;

final class PromptModulePolicy
{
    public function viewAny(Admin $admin): bool
    {
        return $admin->status === 'active';
    }

    public function view(Admin $admin, PromptModule $module): bool
    {
        return $admin->status === 'active';
    }

    public function viewContent(Admin $admin, PromptModule $module): bool
    {
        return $this->canManage($admin, $module);
    }

    public function create(Admin $admin): bool
    {
        return $admin->status === 'active';
    }

    public function createVersion(Admin $admin, PromptModule $module): bool
    {
        return $this->canManage($admin, $module);
    }

    public function update(Admin $admin, PromptModule $module): bool
    {
        return $this->canManage($admin, $module);
    }

    public function publishVersion(Admin $admin, PromptModule $module, PromptModuleVersion $version): bool
    {
        return (int) $version->prompt_module_id === (int) $module->id
            && $admin->status === 'active'
            && $admin->canManageProtectedWorkflows();
    }

    public function archiveVersion(Admin $admin, PromptModule $module, PromptModuleVersion $version): bool
    {
        return $this->publishVersion($admin, $module, $version);
    }

    public function deprecateVersion(Admin $admin, PromptModule $module, PromptModuleVersion $version): bool
    {
        return $this->publishVersion($admin, $module, $version);
    }

    public function deleteVersion(Admin $admin, PromptModule $module, PromptModuleVersion $version): bool
    {
        return (int) $version->prompt_module_id === (int) $module->id
            && $this->canManage($admin, $module);
    }

    private function canManage(Admin $admin, PromptModule $module): bool
    {
        return $admin->status === 'active'
            && ($admin->isSuperAdmin() || (! $module->is_system && (int) $module->owned_by_admin_id === (int) $admin->id));
    }
}
