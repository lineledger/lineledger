<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\User;
use App\Support\Access\ApiRoutePermissionMap;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Caps an API key at what the person who created it may do in the company,
 * in the same activity terms as the web app (ApiRoutePermissionMap): a key
 * minted by someone who can't delete invoices can't delete them over the API
 * either, whatever scopes the key carries, and a key stops working the moment
 * its creator loses the permission.
 *
 * Runs after `auth.api_key` and `api.ability` (the key's own scopes) and
 * before route-model binding. Keys with no recorded creator (made before
 * creators were recorded, or minted from the console) keep their scopes as
 * the only limit.
 */
class EnsureApiPermission
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requirements = ApiRoutePermissionMap::for($request->route()?->getName());

        $key = app()->bound('current_api_key') ? app('current_api_key') : null;
        $company = app()->bound('current_company') ? app('current_company') : null;

        if ($requirements === null || ! $key instanceof CompanyApiKey || ! $company instanceof Company || $key->created_by_user_id === null) {
            return $next($request);
        }

        $creator = User::query()->find($key->created_by_user_id);
        $permissions = $creator?->permissions($company);

        foreach ($requirements as $anyOf) {
            if ($permissions === null || ! $permissions->allowsAny($anyOf)) {
                return response()->json([
                    'message' => 'The person who created this API key may not do this in the organization: '
                        .implode(' or ', array_map('strval', $anyOf)).'.',
                ], Response::HTTP_FORBIDDEN);
            }
        }

        return $next($request);
    }
}
