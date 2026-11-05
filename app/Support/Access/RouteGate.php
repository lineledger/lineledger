<?php

namespace App\Support\Access;

use App\Enums\Section;
use App\Models\Company;
use App\Models\User;

/**
 * Whether a viewer may open a company route: the same two checks a request
 * makes — the route's area is switched on for the organization
 * (EnsureSectionEnabled: every area the route touches) and the viewer's permissions meet one of the route's
 * requirements (EnsureSectionAccess). Everything that shows a link — the
 * sidebar, contact and source links, buttons behind @reaches — asks this, so
 * a link is only shown when following it would succeed.
 */
final class RouteGate
{
    public static function reaches(string $routeName, Company $company, ?User $viewer): bool
    {
        $requirements = RoutePermissionMap::for($routeName);
        $sections = Section::forRouteName($routeName);

        foreach ($sections as $section) {
            if (! $company->sectionEnabled($section)) {
                return false;
            }
        }

        if ($requirements === []) {
            return true;
        }

        return $viewer !== null && $viewer->permissions($company)->allowsAny($requirements);
    }
}
