<?php

namespace App\Support\Access;

use App\Enums\Activity;

/**
 * The permissions an /api/v1 request acts with: its key's creator's, as
 * EnsureApiPermission binds them. A key with no recorded creator is limited
 * by its abilities alone, so nothing is bound and nothing is hidden.
 */
final class ApiViewer
{
    public const BINDING = 'current_api_permissions';

    public static function canViewBalances(Activity $activity): bool
    {
        if (! app()->bound(self::BINDING)) {
            return true;
        }

        $permissions = app(self::BINDING);

        return $permissions instanceof PermissionSet && $permissions->canViewBalances($activity);
    }
}
