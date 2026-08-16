<?php

namespace App\Policies;

use App\Models\ReportGroup;
use App\Models\User;

class ReportGroupPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Shared with co-members: any user who belongs to every member company may
     * view and run the group's reports.
     */
    public function view(User $user, ReportGroup $reportGroup): bool
    {
        return $reportGroup->isVisibleTo($user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Only the creator may edit the mapping, manage companies, or delete the group.
     */
    public function update(User $user, ReportGroup $reportGroup): bool
    {
        return $reportGroup->user_id === $user->id;
    }

    public function delete(User $user, ReportGroup $reportGroup): bool
    {
        return $reportGroup->user_id === $user->id;
    }
}
