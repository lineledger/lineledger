<?php

namespace App\Http\Middleware;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class EnsureCompanyMembership
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $minimumRole = null): Response
    {
        [$user, $company] = [$request->user(), $this->company($request)];

        abort_if(! $user || ! $company || ! $user->belongsToCompany($company), 403);

        $this->ensureCompanyMemberHasRequiredRole($user, $company, $minimumRole);

        app()->instance('current_company', $company);
        URL::defaults(['company' => $company->slug]);

        if (! $user->isCurrentCompany($company)) {
            $user->switchCompany($company);
        }

        return $next($request);
    }

    protected function ensureCompanyMemberHasRequiredRole(User $user, Company $company, ?string $minimumRole): void
    {
        if ($minimumRole === null) {
            return;
        }

        $role = $user->companyRole($company);

        $requiredRole = CompanyRole::tryFrom($minimumRole);

        abort_if(
            $requiredRole === null ||
            $role === null ||
            ! $role->isAtLeast($requiredRole),
            403,
        );
    }

    protected function company(Request $request): ?Company
    {
        $company = $request->route('company');

        if (is_string($company)) {
            $company = Company::where('slug', $company)->first();
        }

        return $company;
    }
}
