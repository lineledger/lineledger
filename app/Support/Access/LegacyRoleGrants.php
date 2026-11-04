<?php

namespace App\Support\Access;

use App\Enums\Activity;
use App\Enums\CompanyRole;
use App\Enums\Section;

/**
 * The permissions the four original roles carry, expressed as activity grants,
 * so access is identical before and after roles become editable data:
 *
 *  - Owner: everything.
 *  - Admin: every activity except the reserved (Owner-only) ones — so the
 *    administrative ones too (company settings, closing date, API keys,
 *    invitations, all document folders, taking over an edit).
 *  - Accountant: every working activity outside Settings — no administrative
 *    or reserved ones.
 *  - Custom: every working activity in the sections ticked — no
 *    administrative or reserved ones.
 *
 * Each grant is Full: the original roles never distinguished viewing from
 * changing. Used for memberships not yet converted to roles, and as the
 * template for the Full Access and Accountant predefined roles.
 */
final class LegacyRoleGrants
{
    /**
     * @param  list<string>|null  $sections  the Custom role's section values
     */
    public static function for(CompanyRole $role, ?array $sections = null): PermissionSet
    {
        return match ($role) {
            CompanyRole::Owner => PermissionSet::owner(),
            CompanyRole::Admin => self::fullWhere(fn (Activity $activity): bool => ! $activity->isReserved()),
            CompanyRole::Accountant => self::fullWhere(fn (Activity $activity): bool => self::isWorking($activity)
                && $activity->area() !== Section::Settings),
            CompanyRole::Custom => self::fullWhere(fn (Activity $activity): bool => self::isWorking($activity)
                && in_array($activity->area()->value, $sections ?? [], true)),
        };
    }

    /**
     * An activity the section toggles always covered: neither administrative
     * nor reserved.
     */
    private static function isWorking(Activity $activity): bool
    {
        return ! $activity->isAdministrative() && ! $activity->isReserved();
    }

    /**
     * @param  callable(Activity): bool  $include
     */
    private static function fullWhere(callable $include): PermissionSet
    {
        $grants = [];

        foreach (Activity::cases() as $activity) {
            if ($include($activity)) {
                $grants[$activity->value] = Grant::full();
            }
        }

        return PermissionSet::fromGrants($grants);
    }
}
