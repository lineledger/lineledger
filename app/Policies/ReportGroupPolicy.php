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
     * Shared with co-members: any user who can open Reports in every member
     * company may view and run the group's reports.
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
     * Only the creator may edit the mapping or manage companies — and only while
     * they can still view the group, since the mapping page lists every member
     * company's accounts. A creator who has since lost access to a member
     * company (removed, or Reports taken away) can still delete the group.
     */
    public function update(User $user, ReportGroup $reportGroup): bool
    {
        return $reportGroup->user_id === $user->id && $reportGroup->isVisibleTo($user);
    }

    public function delete(User $user, ReportGroup $reportGroup): bool
    {
        return $reportGroup->user_id === $user->id;
    }
}
