<?php

namespace App\Services\Deadlines;

use App\Enums\Activity;
use App\Enums\PermissionAction;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who a deadline can be assigned to, and who completes it: members of the
 * organization whose roles let them open Deadlines and mark one done (View and
 * Modify). Assigning to anyone else would send them reminders for a page they
 * can't act on.
 */
final class DeadlineAssignees
{
    /**
     * The eligible members, by name.
     *
     * @return Collection<int, User>
     */
    public static function for(Company $company): Collection
    {
        return $company->members()
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user): bool => self::canAct($user, $company))
            ->values();
    }

    /**
     * Whether the user (a member of the organization, or not) may be assigned
     * deadlines there.
     */
    public static function isEligible(Company $company, ?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        $user = $company->members()->whereKey($userId)->first();

        return $user instanceof User && self::canAct($user, $company);
    }

    /**
     * Whether a member can open Deadlines and mark one done.
     */
    public static function canAct(User $user, Company $company): bool
    {
        if ($user->isDisabled()) {
            return false;
        }

        $permissions = $user->permissions($company);

        return $permissions->allows(Activity::Deadlines, PermissionAction::View)
            && $permissions->allows(Activity::Deadlines, PermissionAction::Modify);
    }

    /**
     * Whether a member can see Deadlines at all.
     */
    public static function canView(User $user, Company $company): bool
    {
        return ! $user->isDisabled()
            && $user->permissions($company)->allows(Activity::Deadlines, PermissionAction::View);
    }
}
