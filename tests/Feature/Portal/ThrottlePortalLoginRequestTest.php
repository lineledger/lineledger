<?php

use App\Actions\Portal\ThrottlePortalLoginRequest;
use Illuminate\Validation\ValidationException;

/**
 * Finding #8 (2026-08-18 security review): confirms the portal magic-link
 * request endpoint is rate-limited, and closes a gap the per-IP-only limiter
 * would otherwise leave — an attacker rotating source IPs could keep
 * bombing one victim's inbox with sign-in emails since each new IP gets its
 * own bucket. The company+email-only cap (max 10/hour) has no IP dimension,
 * so it still trips regardless of how many distinct IPs are used.
 */
it('allows requests under both the per-IP and per-email caps', function () {
    $throttle = new ThrottlePortalLoginRequest;

    for ($i = 0; $i < 5; $i++) {
        $throttle->handle(1, 'victim@example.test', '10.0.0.1');
    }

    expect(true)->toBeTrue(); // no exception thrown
});

it('blocks once the per-IP cap is exceeded', function () {
    $throttle = new ThrottlePortalLoginRequest;

    for ($i = 0; $i < 5; $i++) {
        $throttle->handle(1, 'victim@example.test', '10.0.0.1');
    }

    expect(fn () => $throttle->handle(1, 'victim@example.test', '10.0.0.1'))
        ->toThrow(ValidationException::class);
});

it('blocks a mass-mail-bomb attempt that rotates IPs to dodge the per-IP cap', function () {
    $throttle = new ThrottlePortalLoginRequest;

    // Ten distinct IPs, one request each — every one clears the per-IP cap
    // (max 5) individually, but the shared per-email cap (max 10) is still
    // accumulating across all of them.
    for ($i = 0; $i < 10; $i++) {
        $throttle->handle(1, 'victim@example.test', "203.0.113.{$i}");
    }

    expect(fn () => $throttle->handle(1, 'victim@example.test', '203.0.113.99'))
        ->toThrow(ValidationException::class);
});

it('scopes limits per company so one tenant cannot exhaust another\'s cap', function () {
    $throttle = new ThrottlePortalLoginRequest;

    for ($i = 0; $i < 10; $i++) {
        $throttle->handle(1, 'shared@example.test', "203.0.113.{$i}");
    }

    // Same email, different company — a fresh bucket, not blocked.
    $throttle->handle(2, 'shared@example.test', '203.0.113.1');

    expect(true)->toBeTrue();
});
