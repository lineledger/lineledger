<?php

namespace App\Support\Security;

use App\Enums\CompanyRole;
use App\Http\Middleware\EnforceTwoFactor;
use App\Http\Middleware\EnforceTwoFactorForApi;
use App\Models\Company;
use App\Models\User;

/**
 * Shared decision behind every "does this company's 2FA requirement apply
 * here" check (SOC 2 CC6.1 — stronger authentication for privileged access):
 * a company that opted into {@see Company::$require_two_factor} requires it
 * of its owners and admins specifically, not every role.
 *
 * Used by {@see EnforceTwoFactor} (web, redirects to the
 * security settings page) and {@see EnforceTwoFactorForApi}
 * (API keys / MCP tokens, which have no page to redirect to and must instead
 * reject the request outright).
 */
final class TwoFactorRequirement
{
    public static function isUnmet(Company $company, ?User $user): bool
    {
        return $user !== null
            && $company->require_two_factor
            && ! $user->hasEnabledTwoFactorAuthentication()
            && ($user->companyRole($company)?->isAtLeast(CompanyRole::Admin) ?? false);
    }
}
