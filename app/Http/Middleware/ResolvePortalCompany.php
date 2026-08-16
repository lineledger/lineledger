<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the current company for the customer payment portal from the {company}
 * slug in the URL. Unlike EnsureCompanyMembership this performs NO membership
 * check — the portal is for external customers, not staff. Binding happens here,
 * before the customer guard resolves a Contact from the session, so the Contact's
 * global CompanyScope confines the lookup to this company (a session carrying a
 * contact id from another tenant resolves to null).
 */
class ResolvePortalCompany
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $company = $this->company($request);

        abort_if($company === null, 404);

        app()->instance('current_company', $company);
        URL::defaults(['company' => $company->slug]);

        return $next($request);
    }

    protected function company(Request $request): ?Company
    {
        $company = $request->route('company');

        if ($company instanceof Company) {
            return $company;
        }

        if (is_string($company)) {
            return Company::where('slug', $company)->first();
        }

        return null;
    }
}
