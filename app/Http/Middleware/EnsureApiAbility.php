<?php

namespace App\Http\Middleware;

use App\Models\CompanyApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces a `{domain}:{action}` ability on the authenticated API key. Used as a
 * route-parameter middleware, e.g. `->middleware('api.ability:sales:write')`.
 * Runs after `auth.api_key`, which binds `current_api_key`.
 */
class EnsureApiAbility
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $key = app()->bound('current_api_key') ? app('current_api_key') : null;

        if (! $key instanceof CompanyApiKey || ! $key->hasAbility($ability)) {
            return response()->json([
                'message' => "This API key is not permitted to perform the \"{$ability}\" action.",
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
