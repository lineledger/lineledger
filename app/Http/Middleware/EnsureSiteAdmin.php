<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the site admin portal. Only a platform site admin
 * ({@see User::$site_admin}) may enter, and they must have
 * two-factor authentication enabled first — privileged cross-tenant access
 * warrants stronger authentication (SOC 2 CC6.1).
 *
 * A non-admin gets a 404 (the portal stays undiscoverable). An admin without
 * 2FA is bounced to the security settings page to enrol — an enrolment nudge,
 * not a lockout, mirroring {@see EnforceTwoFactor}. Password re-confirmation is
 * layered on separately via the `password.confirm` middleware on the route group.
 */
class EnsureSiteAdmin
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user !== null && $user->site_admin, 404);

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return redirect()
                ->route('security.edit')
                ->with('status', __('Enable two-factor authentication to access the site admin area.'));
        }

        return $next($request);
    }
}
