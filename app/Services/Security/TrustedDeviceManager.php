<?php

namespace App\Services\Security;

use App\Http\Middleware\RequireTwoFactorConfirmation;
use App\Models\TwoFactorRememberedDevice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * "Remember this device" for two-factor authentication: lets a user skip the
 * 2FA challenge at future logins on a device they trust, until the trust
 * expires ({@see config('auth.two_factor_remember_days')}).
 *
 * The browser holds a random token in an encrypted cookie; only its SHA-256
 * hash is persisted in {@see TwoFactorRememberedDevice}, so a
 * database leak never reveals a usable token. Trust is per-row and revocable.
 *
 * This NEVER bypasses the step-up 2FA gate guarding sensitive settings pages
 * ({@see RequireTwoFactorConfirmation}) — it only affects
 * the login challenge.
 */
class TrustedDeviceManager
{
    /**
     * Name of the encrypted cookie holding the device token. Not listed in the
     * `encryptCookies` except-list (bootstrap/app.php), so Laravel encrypts it.
     */
    public const COOKIE = 'two_factor_remember';

    /**
     * Does this request carry a cookie for a non-expired trusted device that
     * belongs to the given user? Refreshes the device's last-used timestamp on
     * a hit.
     */
    public function hasTrustedDevice(User $user, Request $request): bool
    {
        $token = $request->cookie(self::COOKIE);

        if (! is_string($token) || $token === '') {
            return false;
        }

        $device = $user->twoFactorRememberedDevices()
            ->where('token_hash', $this->hash($token))
            ->where('expires_at', '>', now())
            ->first();

        if ($device === null) {
            return false;
        }

        $device->forceFill(['last_used_at' => now()])->save();

        return true;
    }

    /**
     * Trust the current device for this user: persist a hashed token and queue
     * the matching long-lived cookie onto the outgoing response.
     */
    public function rememberDevice(User $user, Request $request): void
    {
        // Drop this user's expired rows so the table does not grow unbounded.
        $user->twoFactorRememberedDevices()->where('expires_at', '<=', now())->delete();

        $token = Str::random(40);

        $user->twoFactorRememberedDevices()->create([
            'token_hash' => $this->hash($token),
            'expires_at' => now()->addDays($this->trustDays()),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
        ]);

        Cookie::queue(self::COOKIE, $token, $this->trustDays() * 24 * 60);
    }

    /**
     * Revoke every trusted device for the user and clear the cookie on the
     * current device. Used by the security page and when 2FA is disabled.
     */
    public function forgetAllDevices(User $user): void
    {
        $user->twoFactorRememberedDevices()->delete();

        Cookie::queue(Cookie::forget(self::COOKIE));
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private function trustDays(): int
    {
        return (int) config('auth.two_factor_remember_days', 60);
    }
}
