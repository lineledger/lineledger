<?php

namespace App\Actions\Fortify;

use App\Providers\FortifyServiceProvider;
use App\Services\Security\TrustedDeviceManager;
use Illuminate\Http\Request;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable as FortifyRedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * Drop-in replacement for Fortify's login-pipeline 2FA gate that honours
 * "remember this device": when the credentials are valid, 2FA would normally be
 * required, and the request carries a valid trusted-device cookie, the user is
 * logged straight in without the challenge. Bound over the Fortify class in
 * {@see FortifyServiceProvider::register()}.
 *
 * The branching in {@see requiresTwoFactorChallenge()} mirrors the parent's
 * handle() exactly; we only short-circuit when a trusted device is present.
 */
class RedirectIfTwoFactorAuthenticatable extends FortifyRedirectIfTwoFactorAuthenticatable
{
    /**
     * @param  Request  $request
     * @param  callable  $next
     * @return mixed
     */
    public function handle($request, $next)
    {
        $user = $this->validateCredentials($request);

        if (! $this->requiresTwoFactorChallenge($user)) {
            return $next($request);
        }

        if (app(TrustedDeviceManager::class)->hasTrustedDevice($user, $request)) {
            return $next($request);
        }

        return $this->twoFactorChallengeResponse($request, $user);
    }

    /**
     * Whether the parent would issue a two-factor challenge for this user.
     *
     * @param  mixed  $user
     */
    protected function requiresTwoFactorChallenge($user): bool
    {
        if (! optional($user)->two_factor_secret
            || ! in_array(TwoFactorAuthenticatable::class, class_uses_recursive($user))) {
            return false;
        }

        // When confirmation is enforced, an unconfirmed secret is treated as if
        // 2FA is not yet set up (matches the parent action).
        if (Fortify::confirmsTwoFactorAuthentication()) {
            return ! is_null(optional($user)->two_factor_confirmed_at);
        }

        return true;
    }
}
