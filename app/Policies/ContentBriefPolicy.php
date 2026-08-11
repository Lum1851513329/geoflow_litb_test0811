<?php

namespace App\Policies;

use App\Models\Admin;
use App\Models\ContentBrief;

final class ContentBriefPolicy
{
    public function viewAny(Admin $admin): bool
    {
        return $this->isActive($admin);
    }

    public function view(Admin $admin, ContentBrief $brief): bool
    {
        return $this->owns($admin, $brief);
    }

    public function create(Admin $admin): bool
    {
        return $this->isActive($admin);
    }

    public function update(Admin $admin, ContentBrief $brief): bool
    {
        return $this->owns($admin, $brief);
    }

    private function owns(Admin $admin, ContentBrief $brief): bool
    {
        return $this->isActive($admin)
            && ($admin->isSuperAdmin()
                || (int) $brief->created_by_admin_id === (int) $admin->getKey());
    }

    private function isActive(Admin $admin): bool
    {
        return $admin->status === 'active';
    }
}
