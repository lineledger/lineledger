<?php

namespace App\Http\Middleware;

use App\Enums\CompanyRole;
use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When a company opts into {@see Company::$require_two_factor}, its owners and
 * admins must have two-factor authentication enabled before they can use the
 * app (SOC 2 CC6.1 — stronger authentication for privileged access). Runs after
 * {@see EnsureCompanyMembership} on the {company} route group, so
 * `current_company` and the user's role are known.
 *
 * This is an enrolment nudge, not a lockout: an un-enrolled privileged user is
 * bounced to the security settings page to turn 2FA on. That page lives outside
 * the {company} prefix, so there is no redirect loop, and lower-privilege roles
 * are unaffected.
 */
class EnforceTwoFactor
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $company = app()->bound('current_company') ? app('current_company') : null;
        $user = $request->user();

        if ($company instanceof Company
            && $user !== null
            && $company->require_two_factor
            && ! $user->hasEnabledTwoFactorAuthentication()
            && ($user->companyRole($company)?->isAtLeast(CompanyRole::Admin) ?? false)
        ) {
            return redirect()
                ->route('security.edit')
                ->with('status', __('This company requires two-factor authentication for owners and admins. Enable it to continue.'));
        }

        return $next($request);
    }
}
