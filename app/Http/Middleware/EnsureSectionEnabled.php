<?php

namespace App\Http\Middleware;

use App\Enums\Section;
use App\Models\Company;
use App\Support\Navigation\SidebarNavCatalog;
use App\Support\SiteSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform-wide section kill switch. The site admin can disable a whole section
 * (e.g. Payroll) across every tenant from the admin portal; this enforces that
 * at the route layer by 404-ing any request whose section is globally off.
 *
 * Runs in the {company} group immediately before {@see EnsureSectionAccess} (the
 * per-user authorization), so a disabled section reads as "not here" before any
 * membership check. Routes that map to no section, and Settings, always pass —
 * mirroring the gating in {@see SidebarNavCatalog}.
 *
 * When a current company is bound, per-company admin overrides
 * ({@see Company::sectionEnabled()}) layer on top of the global kill switch —
 * the operator can re-enable a section for a single tenant.
 */
class EnsureSectionEnabled
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $sections = Section::forRouteName($request->route()?->getName());
        $company = app()->bound('current_company') ? app('current_company') : null;

        foreach ($sections as $section) {
            $enabled = $company instanceof Company
                ? $company->sectionEnabled($section)
                : SiteSettings::sectionEnabled($section);

            abort_unless($enabled, 404);
        }

        return $next($request);
    }
}
