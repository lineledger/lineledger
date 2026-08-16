<?php

namespace App\Http\Middleware;

use App\Support\SiteSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Operator-controlled maintenance mode, toggled from the site admin portal.
 *
 * When on, everyone is shown a 503 maintenance page except platform site admins,
 * who pass through untouched — so the operator can always log in and switch it
 * back off. This is deliberately a custom flag rather than Laravel's native
 * `php artisan down`, which would also lock out the admin and the portal.
 *
 * The authentication surface (login, logout, password reset/confirm, two-factor)
 * and Livewire's XHR endpoint stay reachable so an admin can sign in to recover.
 */
class CheckSiteMaintenance
{
    /**
     * Path patterns that remain reachable during maintenance so an admin can
     * authenticate. {@see Request::is()} globs, matched against the path.
     *
     * @var list<string>
     */
    private const ALLOWED_PATHS = [
        'login',
        'logout',
        'two-factor-challenge',
        'forgot-password',
        'reset-password',
        'reset-password/*',
        'user/*',          // Fortify: confirm-password, two-factor enable/confirm, …
        'livewire/*',      // Livewire/Volt XHR + assets used by the auth pages.
        'up',              // Framework health check.
    ];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! SiteSettings::maintenanceMode()) {
            return $next($request);
        }

        if ($request->user()?->site_admin) {
            return $next($request);
        }

        if ($request->is(...self::ALLOWED_PATHS)) {
            return $next($request);
        }

        return response()->view('errors.maintenance', [], Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
