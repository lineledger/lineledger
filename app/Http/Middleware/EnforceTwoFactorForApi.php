<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\User;
use App\Support\Security\TwoFactorRequirement;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The API-key/MCP-token counterpart to {@see EnforceTwoFactor}: a company that
 * requires 2FA for its owners/admins gets that protection only on the web UI
 * unless this also runs on the non-interactive auth paths (Finding #3, 2026-08-18
 * security review). There's no page to redirect to here, so this rejects the
 * request outright (403) instead.
 *
 * Runs after `auth.api_key` (static `CompanyApiKey`, no live request user — the
 * relevant "who" is whoever minted the key) or after `auth:api` + `mcp.company`
 * (Passport OAuth, `$request->user()` is the authenticated staff member).
 */
class EnforceTwoFactorForApi
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $company = app()->bound('current_company') ? app('current_company') : null;

        if ($company instanceof Company && TwoFactorRequirement::isUnmet($company, $this->resolveUser($request))) {
            throw new HttpException(403, 'This company requires two-factor authentication for owners and admins. '
                .'Enable it in Security settings before using the API or MCP with this account.');
        }

        return $next($request);
    }

    protected function resolveUser(Request $request): ?User
    {
        if (app()->bound('current_api_key')) {
            $key = app('current_api_key');

            return $key instanceof CompanyApiKey ? $key->createdByUser : null;
        }

        return $request->user();
    }
}
