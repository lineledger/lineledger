<?php

namespace App\Actions\Portal;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Two independent caps on magic-link requests for the customer portal:
 * one scoped to company + email + IP (catches casual single-source abuse),
 * one scoped to company + email alone (higher ceiling, longer window) so an
 * attacker can't defeat the IP-scoped cap by rotating source IPs to keep
 * bombing a single victim's inbox with sign-in emails.
 */
final class ThrottlePortalLoginRequest
{
    public function handle(int $companyId, string $email, string $ip): void
    {
        $email = mb_strtolower($email);

        foreach ($this->limits($companyId, $email, $ip) as [$key, $maxAttempts]) {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                throw ValidationException::withMessages([
                    'email' => __('Too many attempts. Please try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)]),
                ]);
            }
        }

        foreach ($this->limits($companyId, $email, $ip) as [$key, , $decaySeconds]) {
            RateLimiter::increment($key, decaySeconds: $decaySeconds);
        }
    }

    /**
     * @return list<array{0: string, 1: int, 2: int}> [key, maxAttempts, decaySeconds]
     */
    private function limits(int $companyId, string $email, string $ip): array
    {
        return [
            ["portal-login:{$companyId}|{$email}|{$ip}", 5, 900],
            ["portal-login-email:{$companyId}|{$email}", 10, 3600],
        ];
    }
}
