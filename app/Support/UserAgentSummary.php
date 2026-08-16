<?php

namespace App\Support;

/**
 * Turns a raw User-Agent string into a short, human-readable label like
 * "Chrome on macOS" for the new-device email and the active-sessions list.
 * Keyword matching only — no dependency, no fingerprinting claims.
 */
class UserAgentSummary
{
    public static function label(?string $userAgent): string
    {
        $ua = (string) $userAgent;

        if (trim($ua) === '') {
            return 'Unknown device';
        }

        $browser = match (true) {
            str_contains($ua, 'Edg') => 'Edge',
            str_contains($ua, 'OPR'), str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Firefox') => 'Firefox',
            str_contains($ua, 'Chrome') => 'Chrome',
            str_contains($ua, 'Safari') => 'Safari',
            default => null,
        };

        $platform = match (true) {
            str_contains($ua, 'iPhone'), str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS'), str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        return match (true) {
            $browser !== null && $platform !== null => "{$browser} on {$platform}",
            $browser !== null => $browser,
            $platform !== null => $platform,
            default => 'Unknown device',
        };
    }
}
