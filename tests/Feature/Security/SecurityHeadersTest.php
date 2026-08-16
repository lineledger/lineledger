<?php

/**
 * The SecurityHeaders middleware attaches baseline browser security headers to
 * every web response (SOC 2 Common Criteria: boundary protection / data in
 * transit). HSTS is intentionally suppressed over plain HTTP.
 */
test('web responses carry baseline security headers', function () {
    $response = $this->get('/login');

    $response->assertOk();
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

    expect($response->headers->get('Permissions-Policy'))->not->toBeNull();
    expect($response->headers->get('Content-Security-Policy-Report-Only'))->toContain("default-src 'self'");
});

test('HSTS is not advertised over plain HTTP', function () {
    $response = $this->get('/login');

    expect($response->headers->has('Strict-Transport-Security'))->toBeFalse();
});

test('HSTS is advertised when a trusted proxy forwards the request as HTTPS', function () {
    // Behind a TLS-terminating balancer the connection to the app is plain HTTP
    // but X-Forwarded-Proto is https. With trusted proxies configured (bootstrap
    // /app.php), $request->isSecure() honours it and HSTS is emitted. Without the
    // proxy trust this header would be silently missing in production.
    $response = $this->get('/login', ['X-Forwarded-Proto' => 'https']);

    $response->assertHeader('Strict-Transport-Security');
});

test('CSP advertises the report endpoint when reporting is on', function () {
    config()->set('security.csp.reporting', true);

    $response = $this->get('/login');

    $response->assertHeader('Reporting-Endpoints', 'csp="/csp-report"');
    expect($response->headers->get('Content-Security-Policy-Report-Only'))
        ->toContain('report-uri /csp-report')
        ->toContain('report-to csp');
});

test('CSP reporting directives are omitted when reporting is off', function () {
    config()->set('security.csp.reporting', false);

    $response = $this->get('/login');

    expect($response->headers->has('Reporting-Endpoints'))->toBeFalse();
    expect($response->headers->get('Content-Security-Policy-Report-Only'))
        ->not->toContain('report-uri');
});

test('the report-only policy carries a nonce', function () {
    $response = $this->get('/login');

    expect($response->headers->get('Content-Security-Policy-Report-Only'))
        ->toMatch("/script-src [^;]*'nonce-[A-Za-z0-9]+'/");
});

test('enforce mode sends the enforcing header and no report-only header', function () {
    config()->set('security.csp.mode', 'enforce');

    $response = $this->get('/login');

    expect($response->headers->get('Content-Security-Policy'))
        ->toContain('script-src')
        ->toContain("'unsafe-eval'")
        ->toContain('challenges.cloudflare.com')
        ->toMatch("/'nonce-[A-Za-z0-9]+'/");
    expect($response->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse();
});

test('off mode sends no CSP header at all', function () {
    config()->set('security.csp.mode', 'off');

    $response = $this->get('/login');

    expect($response->headers->has('Content-Security-Policy'))->toBeFalse();
    expect($response->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse();
});

test('the CSP nonce is stable across requests in one session', function () {
    $extract = function (string $header): ?string {
        preg_match("/'nonce-([A-Za-z0-9]+)'/", $header, $m);

        return $m[1] ?? null;
    };

    $first = $this->get('/login');
    $second = $this->get('/login');

    $a = $extract((string) $first->headers->get('Content-Security-Policy-Report-Only'));
    $b = $extract((string) $second->headers->get('Content-Security-Policy-Report-Only'));

    expect($a)->not->toBeNull()->and($b)->toBe($a);
});
