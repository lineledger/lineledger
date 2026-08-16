<?php

namespace App\Http\Middleware;

use App\Services\Security\Turnstile;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the Cloudflare Turnstile challenge on the public, unauthenticated
 * forms listed in `config('turnstile.routes')` — registration, login, and the
 * password-reset link request.
 *
 * Implemented as middleware rather than three separate hooks because Fortify
 * owns all three routes and offers an interception point for none of them:
 * registration goes through CreatesNewUsers, login through the auth pipeline,
 * and the reset link through a controller with no extension seam at all. One
 * middleware in the `web` group covers all of them identically, and new forms
 * are protected by adding a route name to the config.
 *
 * Inert unless Turnstile is configured, so self-hosted installs, local
 * development, and the test suite are unaffected.
 */
class VerifyTurnstile
{
    public function __construct(private readonly Turnstile $turnstile) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only guard the actual submission. The GET that renders the form
        // carries no token, and a token is single-use, so verifying anything
        // but the POST would burn it.
        if (! $request->isMethod('POST')) {
            return $next($request);
        }

        if (! $this->turnstile->enabled()) {
            return $next($request);
        }

        $config = $this->configFor($request);

        if ($config === null) {
            return $next($request);
        }

        $passed = $this->turnstile->verify(
            $request->input(Turnstile::RESPONSE_FIELD),
            $request->ip(),
            (bool) ($config['fail_open'] ?? false),
        );

        if ($passed) {
            return $next($request);
        }

        throw ValidationException::withMessages([
            Turnstile::RESPONSE_FIELD => __('Please complete the human-verification challenge and try again.'),
        ]);
    }

    /**
     * The Turnstile settings for the route being requested, or null when the
     * route isn't protected.
     *
     * @return array{action?: string, fail_open?: bool}|null
     */
    protected function configFor(Request $request): ?array
    {
        foreach ((array) config('turnstile.routes', []) as $routeName => $settings) {
            if ($request->routeIs($routeName)) {
                return (array) $settings;
            }
        }

        return null;
    }
}
