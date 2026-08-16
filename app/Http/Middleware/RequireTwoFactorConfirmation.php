<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Security\TrustedDeviceManager;
use Closure;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step-up authentication for sensitive settings (security settings, company
 * settings). A user with two-factor enabled must re-confirm with a fresh 2FA
 * code, valid for {@see config('auth.two_factor_timeout')}, before these pages
 * load — even on a device that "remembered" the login challenge
 * ({@see TrustedDeviceManager}). The remembered-device
 * cookie is deliberately never consulted here.
 *
 * A user without 2FA falls back to Fortify's password confirmation, so the
 * security page stays reachable to enable 2FA in the first place. This mirrors
 * (and replaces) the `password.confirm` gate previously on these routes.
 */
class RequireTwoFactorConfirmation
{
    /** Session key holding the unix time of the last successful step-up. */
    public const CONFIRMED_AT = 'auth.two_factor_confirmed_at';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->hasEnabledTwoFactorAuthentication()) {
            return app(RequirePassword::class)->handle($request, $next);
        }

        if ($this->recentlyConfirmed($request)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(Response::HTTP_LOCKED, 'Two-factor confirmation required.');
        }

        return redirect()->guest(route('two-factor.reconfirm'));
    }

    private function recentlyConfirmed(Request $request): bool
    {
        $confirmedAt = (int) $request->session()->get(self::CONFIRMED_AT, 0);

        return (now()->timestamp - $confirmedAt) < (int) config('auth.two_factor_timeout', 900);
    }
}
