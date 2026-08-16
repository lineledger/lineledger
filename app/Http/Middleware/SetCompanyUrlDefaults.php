<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class SetCompanyUrlDefaults
{
    /**
     * Seed URL defaults from the user's most-recently-used company so that
     * named routes resolving outside a /{company}/... URL still work.
     * Within a /{company}/... URL the EnsureCompanyMembership middleware
     * overrides this with the route-bound company.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($currentCompany = $request->user('web')?->currentCompany) {
            URL::defaults(['company' => $currentCompany->slug]);
        }

        return $next($request);
    }
}
