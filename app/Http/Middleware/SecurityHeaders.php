<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attaches baseline HTTP security response headers to every web response —
 * the boundary-protection and transport controls a SOC 2 reviewer checks
 * under the Common Criteria (logical access / data in transit).
 *
 * HSTS is only emitted over HTTPS so local plain-HTTP development is
 * unaffected. The Content-Security-Policy carries a per-session nonce and is
 * delivered per `config('security.csp.mode')` — Report-Only by default so it
 * observes without blocking; flip to `enforce` once the report log is clean.
 * The nonce is session-scoped (not per-request) because `wire:navigate` swaps
 * the DOM under the first document's CSP, so a fresh per-request nonce would
 * stop matching after the first client-side navigation.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Resolve the nonce and hand it to Vite BEFORE rendering, so @vite,
        // Livewire, and Flux tags emit it on their script elements.
        $nonce = $this->cspNonce($request);

        if ($nonce !== null) {
            Vite::useCspNonce($nonce);
        }

        $response = $next($request);

        $headers = $response->headers;

        // Stop browsers from MIME-sniffing a response away from its declared type.
        $headers->set('X-Content-Type-Options', 'nosniff');

        // Clickjacking protection. The app never frames its own pages (no
        // <iframe>/<embed>/<object> in any view), so a full deny is safe.
        $headers->set('X-Frame-Options', 'DENY');

        // Send the origin (not the full path/query) on cross-origin navigations.
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // The app uses none of these powerful features; deny them outright.
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), browsing-topics=()');

        // Only meaningful — and only honoured — over HTTPS.
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        $mode = (string) config('security.csp.mode', 'report-only');

        if ($mode !== 'off') {
            if (config('security.csp.reporting', true)) {
                // report-to names the endpoint group declared by Reporting-Endpoints;
                // report-uri is the deprecated-but-widely-delivered fallback. Browsers
                // that understand report-to ignore report-uri.
                $headers->set('Reporting-Endpoints', 'csp="/csp-report"');
            }

            $header = $mode === 'enforce'
                ? 'Content-Security-Policy'
                : 'Content-Security-Policy-Report-Only';

            $headers->set($header, $this->contentSecurityPolicy($nonce));
        }

        return $response;
    }

    /**
     * The per-session CSP nonce, or null when there is no session to store it on
     * (so the policy simply omits the nonce token rather than minting a fresh one
     * every request).
     */
    protected function cspNonce(Request $request): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        $session = $request->session();
        $nonce = $session->get('csp_nonce');

        if (! is_string($nonce) || $nonce === '') {
            $nonce = Str::random(32);
            $session->put('csp_nonce', $nonce);
        }

        return $nonce;
    }

    /**
     * Build the policy. The nonce authorizes our own inline/script tags;
     * `'unsafe-eval'` stays because standard Alpine evaluates expressions with
     * `new Function` (the CSP-safe Alpine build would break Flux). `'unsafe-inline'`
     * stays as a pre-CSP2 fallback only — a CSP2+ browser ignores it once a nonce
     * is present, so the nonce is what actually constrains inline script.
     */
    protected function contentSecurityPolicy(?string $nonce = null): string
    {
        // Cloudflare Turnstile loads api.js from challenges.cloudflare.com and
        // renders the challenge inside an iframe served from the same host, so
        // it needs script-src, frame-src and connect-src entries. Listed
        // unconditionally: the policy is a static string, and naming a host the
        // app may not use costs nothing while keeping the report-only stream
        // clean wherever Turnstile *is* configured.
        $turnstile = 'https://challenges.cloudflare.com';

        $nonceToken = $nonce !== null ? "'nonce-{$nonce}' " : '';

        // Local dev serves assets and the HMR socket from the Vite dev server;
        // allow it so `composer run dev` doesn't drown the report log (or break
        // under enforce). Never emitted in production.
        $dev = app()->isLocal() && is_file(public_path('hot'))
            ? ' http://localhost:5173 http://127.0.0.1:5173'
            : '';
        $devWs = $dev !== '' ? ' ws://localhost:5173 ws://127.0.0.1:5173' : '';

        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
            "img-src 'self' data: blob: https:",
            "font-src 'self' data:",
            "style-src 'self' 'unsafe-inline'".$dev,
            "script-src 'self' {$nonceToken}'unsafe-inline' 'unsafe-eval' ".$turnstile.$dev,
            "frame-src 'self' ".$turnstile,
            "connect-src 'self' ".$turnstile.$dev.$devWs,
        ];

        if (config('security.csp.reporting', true)) {
            $directives[] = 'report-uri /csp-report';
            $directives[] = 'report-to csp';
        }

        return implode('; ', $directives);
    }
}
