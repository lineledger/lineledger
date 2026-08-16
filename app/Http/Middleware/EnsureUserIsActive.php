<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out any user a site admin has disabled.
 *
 * This is the authoritative gate for the account lockout: it catches every way
 * into the app — password login, the two-factor challenge, passkey login, and
 * sessions that were already open when the account was disabled. The login
 * pipeline step (EnsureUserIsNotDisabled) only exists so the login form itself
 * shows a proper error instead of bouncing on the following request.
 *
 * Livewire's XHR endpoint is in the `web` group too, so an in-flight component
 * action is bounced as well. This cannot help on routes/api.php: API keys
 * resolve to a company, never to a user — disabling revokes the user's keys
 * instead (see AccessRevoker::revokeForAccountDisabled).
 */
class EnsureUserIsActive
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Explicitly the `web` guard: the customer and employee portals run on
        // their own guards and authenticate a Contact, which has no lockout of
        // its own and must not be probed for one.
        if (! $request->user('web')?->isDisabled()) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors([
            Fortify::username() => __('This account has been disabled. Contact support if you believe this is a mistake.'),
        ]);
    }
}
