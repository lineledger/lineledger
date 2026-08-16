<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Server-side half of the Cloudflare Turnstile challenge.
 *
 * The widget in `<x-turnstile>` produces a single-use token (valid ~5 minutes)
 * in the `cf-turnstile-response` field; this class trades that token with
 * Cloudflare's siteverify endpoint for a pass/fail. A token is never trusted
 * without that round trip — the client-side widget alone proves nothing.
 */
class Turnstile
{
    /** The form field the widget writes its token into. */
    public const RESPONSE_FIELD = 'cf-turnstile-response';

    /**
     * Turnstile only runs when it is switched on *and* both keys are
     * configured, so an install that never sets them behaves exactly as it did
     * before the feature existed.
     */
    public function enabled(): bool
    {
        return (bool) config('turnstile.enabled')
            && filled(config('turnstile.site_key'))
            && filled(config('turnstile.secret_key'));
    }

    public function siteKey(): ?string
    {
        return config('turnstile.site_key');
    }

    /**
     * Verify a widget token against Cloudflare.
     *
     * @param  bool  $failOpen  Whether an unreachable Cloudflare (timeout,
     *                          connection error, 5xx) should be treated as a
     *                          pass. A token Cloudflare actively rejects always
     *                          fails regardless.
     */
    public function verify(?string $token, ?string $ip = null, bool $failOpen = false): bool
    {
        // No token at all means the widget never ran (scripted POST, or JS
        // disabled). That is a client-side failure, not a Cloudflare outage, so
        // fail_open does not apply.
        if (blank($token)) {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout((int) config('turnstile.timeout', 5))
                ->post(config('turnstile.verify_url'), array_filter([
                    'secret' => config('turnstile.secret_key'),
                    'response' => $token,
                    'remoteip' => $ip,
                ]));
        } catch (\Throwable $e) {
            Log::warning('Turnstile verification could not reach Cloudflare.', [
                'exception' => $e->getMessage(),
                'fail_open' => $failOpen,
            ]);

            return $failOpen;
        }

        if ($response->serverError()) {
            Log::warning('Turnstile verification returned a server error.', [
                'status' => $response->status(),
                'fail_open' => $failOpen,
            ]);

            return $failOpen;
        }

        if ($response->json('success') === true) {
            return true;
        }

        Log::info('Turnstile rejected a submission.', [
            'error_codes' => $response->json('error-codes', []),
        ]);

        return false;
    }
}
