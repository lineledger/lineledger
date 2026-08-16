<?php

namespace App\Http\Middleware;

use App\Enums\Section;
use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authorize access to the section a route belongs to.
 *
 * Runs after {@see EnsureCompanyMembership}, which has already verified the user
 * belongs to the company. Routes are mapped to their section(s) via
 * {@see Section::forRouteName()}; ungated routes (the dashboard, downloads) pass
 * through. Access is granted when the user can reach any of the route's sections.
 */
class EnsureSectionAccess
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $sections = Section::forRouteName($request->route()?->getName());

        if ($sections === []) {
            return $next($request);
        }

        [$user, $company] = [$request->user(), $this->company($request)];

        abort_if(! $user || ! $company, 403);

        $canAccess = collect($sections)->contains(
            fn (Section $section) => $user->canAccessSection($company, $section),
        );

        abort_unless($canAccess, 403);

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
