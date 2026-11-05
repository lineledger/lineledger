<?php

namespace App\Support\Security;

use App\Http\Middleware\RequireTwoFactorConfirmation;
use App\Models\User;

/**
 * Whether the person's step-up confirmation (a fresh two-factor code, or the
 * password for someone without two-factor) is still within its window — the
 * check `2fa.confirm` makes when a page loads.
 *
 * That middleware isn't persistent, so once a page is open its Livewire
 * actions never pass it again: a privileged action on such a page asks this
 * before it changes anything, and sends the person back through the page
 * (which asks again) when the window has lapsed.
 */
final class StepUp
{
    public static function isFresh(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->hasEnabledTwoFactorAuthentication()
            ? now()->timestamp - (int) session(RequireTwoFactorConfirmation::CONFIRMED_AT, 0) < (int) config('auth.two_factor_timeout', 900)
            : now()->timestamp - (int) session('auth.password_confirmed_at', 0) < (int) config('auth.password_timeout', 10800);
    }
}
