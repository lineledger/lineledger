<?php

namespace App\Support\Access;

use App\Enums\Activity;
use App\Enums\PermissionAction;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * The signed-in member's permissions in the organization being viewed — what
 * the @permits and @reaches Blade conditionals and the pages that hide
 * figures ask. Nobody signed in, or no organization bound, sees nothing.
 */
final class CurrentViewer
{
    public static function permissions(?Company $company = null): PermissionSet
    {
        $company ??= app()->bound('current_company') ? app('current_company') : null;
        $user = Auth::user();

        return $company instanceof Company && $user instanceof User
            ? $user->permissions($company)
            : PermissionSet::none();
    }

    public static function permits(Activity $activity, PermissionAction $action = PermissionAction::View): bool
    {
        return self::permissions()->allows($activity, $action);
    }

    public static function canViewBalances(Activity $activity): bool
    {
        return self::permissions()->canViewBalances($activity);
    }

    /**
     * Whether following a link to the route would succeed ({@see RouteGate}).
     */
    public static function reaches(string $routeName): bool
    {
        $company = app()->bound('current_company') ? app('current_company') : null;
        $user = Auth::user();

        return $company instanceof Company && RouteGate::reaches($routeName, $company, $user instanceof User ? $user : null);
    }
}
