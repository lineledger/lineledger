<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the tenant for an OAuth-authenticated MCP request.
 *
 * The Business Q&A MCP server is exposed per company at `mcp/business/{company}`.
 * OAuth (Passport, the `api` guard) authenticates the staff *user*; this middleware
 * resolves the `{company}` slug from the URL, verifies the authenticated user is a
 * member, and binds `current_company` so the global CompanyScope (and every tool)
 * is tenant-scoped. A user may connect any company they belong to; membership is
 * re-checked on every request, so the access token is not itself company-scoped.
 *
 * Runs after `auth:api`, which resolves the user from the bearer access token.
 */
class BindMcpCompany
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_if($user === null, 401, 'Unauthenticated.');

        $slug = $request->route('company');

        $company = is_string($slug)
            ? Company::query()->where('slug', $slug)->first()
            : null;

        abort_if($company === null || ! $user->belongsToCompany($company), 403, 'You are not a member of this company.');

        app()->instance('current_company', $company);

        return $next($request);
    }
}
