<?php

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Records the devices a user logs in from and reports whether a login came from
 * an unseen device worth notifying about.
 */
class LoginDeviceTracker
{
    /**
     * Record this login's device. Returns true only when it is a NEW device AND
     * the user already had at least one device on file — so a user's first-ever
     * login (and every existing user's first login after this ships) seeds
     * silently, and no notification fires for it.
     */
    public function track(User $user, Request $request): bool
    {
        $userAgent = (string) $request->userAgent();
        $hash = hash('sha256', $this->normalize($userAgent));

        $hadDevices = $user->loginDevices()->exists();

        $device = $user->loginDevices()->where('device_hash', $hash)->first();

        if ($device !== null) {
            $device->forceFill([
                'last_seen_at' => now(),
                'ip_address' => $request->ip(),
            ])->save();

            return false;
        }

        $user->loginDevices()->create([
            'device_hash' => $hash,
            'user_agent' => mb_substr($userAgent, 0, 512),
            'ip_address' => $request->ip(),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        return $hadDevices;
    }

    /**
     * Strip version numbers from the user-agent before hashing so a routine
     * browser upgrade (Chrome 140 → 141) is still the same device and does not
     * re-trigger the new-device email. The IP is intentionally excluded from the
     * fingerprint — carrier/VPN churn would make it noisy.
     */
    private function normalize(string $userAgent): string
    {
        return preg_replace('/[\d._]+/', '', $userAgent) ?? $userAgent;
    }
}
