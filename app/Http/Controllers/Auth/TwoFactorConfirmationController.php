<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireTwoFactorConfirmation;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

/**
 * Step-up two-factor confirmation for sensitive settings pages, gated by
 * {@see RequireTwoFactorConfirmation}. Re-verifies the already-authenticated
 * user with a fresh authenticator or recovery code and stamps the session so
 * the gate lets them through for the configured window. Unlike the login
 * challenge, there is no "remember this device" option here — the step-up is
 * always required.
 */
class TwoFactorConfirmationController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        // Users without 2FA never reach the gate's redirect here; if one lands
        // on this page directly there is nothing to confirm.
        if (! $this->userHasTwoFactor($request)) {
            return redirect()->intended(route('security.edit'));
        }

        return view('pages::auth.two-factor-confirm');
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->hasEnabledTwoFactorAuthentication()) {
            return redirect()->intended(route('security.edit'));
        }

        $request->validate([
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        if ($this->confirmedWithRecoveryCode($user, $request) || $this->confirmedWithCode($user, $request)) {
            $request->session()->put(RequireTwoFactorConfirmation::CONFIRMED_AT, now()->timestamp);

            return redirect()->intended(route('security.edit'));
        }

        throw ValidationException::withMessages([
            $request->filled('recovery_code') ? 'recovery_code' : 'code' => [
                __('The provided two-factor authentication code was invalid.'),
            ],
        ]);
    }

    private function userHasTwoFactor(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->hasEnabledTwoFactorAuthentication();
    }

    private function confirmedWithCode(User $user, Request $request): bool
    {
        $code = (string) $request->input('code');

        if ($code === '') {
            return false;
        }

        return (bool) app(TwoFactorAuthenticationProvider::class)->verify(
            decrypt($user->two_factor_secret), $code
        );
    }

    private function confirmedWithRecoveryCode(User $user, Request $request): bool
    {
        $recoveryCode = (string) $request->input('recovery_code');

        if ($recoveryCode === '') {
            return false;
        }

        $matched = collect($user->recoveryCodes())
            ->first(fn ($code): bool => hash_equals((string) $code, $recoveryCode));

        if ($matched === null) {
            return false;
        }

        $user->replaceRecoveryCode($matched);

        return true;
    }
}
