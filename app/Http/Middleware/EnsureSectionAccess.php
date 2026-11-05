<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\User;
use App\Support\Access\RoutePermissionMap;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authorize a company page by what loading it needs.
 *
 * Runs after {@see EnsureCompanyMembership}, which has already verified the user
 * belongs to the company. Each route name maps to the activities it belongs to
 * and the action loading it takes ({@see RoutePermissionMap}: an invoice's edit
 * page needs Modify on invoices); the member's permissions must allow any one
 * of them. Ungated routes (the dashboard, attachment downloads) pass through.
 *
 * Persistent Livewire middleware: every update replays the page's route, so a
 * member who loses access can't keep working in a page already open.
 */
class EnsureSectionAccess
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requirements = RoutePermissionMap::for($request->route()?->getName());

        if ($requirements === []) {
            return $next($request);
        }

        [$user, $company] = [$request->user(), $this->company($request)];

        abort_if(! $user instanceof User || ! $company, 403);

        abort_unless($user->permissions($company)->allowsAny($requirements), 403);

        return $next($request);
    }

    protected function company(Request $request): ?Company
    {
        if (app()->bound('current_company')) {
            return app('current_company');
        }

        $company = $request->route('company');

        if (is_string($company)) {
            $company = Company::where('slug', $company)->first();
        }

        return $company;
    }
}
