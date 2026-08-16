<?php

namespace App\Actions\Fortify;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

/**
 * Login-pipeline step that stops a site-admin-disabled account from signing in.
 *
 * Runs *after* AttemptToAuthenticate on purpose: credentials are validated
 * first, so the response can't be used as an oracle for whether a given email
 * belongs to a disabled account. The EnsureUserIsActive middleware is the
 * authoritative gate (it also covers the two-factor challenge, passkey login,
 * and already-open sessions); this step exists so the login form reports the
 * real reason instead of appearing to succeed and bouncing on the next request.
 */
class EnsureUserIsNotDisabled
{
    /**
     * @param  Closure(Request): mixed  $next
     */
    public function handle(Request $request, Closure $next): mixed
    {
        if (Auth::guard('web')->user()?->isDisabled()) {
            Auth::guard('web')->logout();

            throw ValidationException::withMessages([
                Fortify::username() => __('This account has been disabled. Contact support if you believe this is a mistake.'),
            ]);
        }

        return $next($request);
    }
}
