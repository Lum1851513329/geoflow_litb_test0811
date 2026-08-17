<?php

namespace App\Policies;

use App\Models\Admin;
use App\Models\ContentGenerationRun;

final class ContentGenerationRunPolicy
{
    public function view(Admin $admin, ContentGenerationRun $run): bool
    {
        return $this->ownsParentBrief($admin, $run) || $this->isTaskDriven($admin, $run);
    }

    public function adopt(Admin $admin, ContentGenerationRun $run): bool
    {
        return $this->ownsParentBrief($admin, $run);
    }

    private function isTaskDriven(Admin $admin, ContentGenerationRun $run): bool
    {
        if ($admin->status !== 'active') {
            return false;
        }

        return $run->contentBrief()->whereNotNull('task_id')->exists();
    }

    private function ownsParentBrief(Admin $admin, ContentGenerationRun $run): bool
    {
        if ($admin->status !== 'active') {
            return false;
        }
        if ($admin->isSuperAdmin()) {
            return true;
        }

        return (int) $run->contentBrief()->value('created_by_admin_id') === (int) $admin->getKey();
    }
}
