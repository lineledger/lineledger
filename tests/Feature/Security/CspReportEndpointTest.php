<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config()->set('security.csp.reporting', true);
    config()->set('security.csp.report_sample', 1.0);
});

/**
 * POST a body to the CSP report endpoint with a given content type.
 */
function postCspReport(string $body, string $contentType): TestResponse
{
    return test()->call(
        'POST',
        '/csp-report',
        [], [], [],
        ['CONTENT_TYPE' => $contentType],
        $body,
    );
}

test('accepts a legacy application/csp-report body and logs the violation', function () {
    Log::shouldReceive('channel')->with('csp')->andReturnSelf();
    Log::shouldReceive('info')->once()->withArgs(function (string $message, array $context) {
        return $message === 'csp-violation'
            && $context['violated_directive'] === 'script-src'
            && $context['blocked_uri'] === 'https://evil.example.com/x.js';
    });

    $body = json_encode(['csp-report' => [
        'document-uri' => 'https://app.test/dashboard',
        'violated-directive' => 'script-src',
        'blocked-uri' => 'https://evil.example.com/x.js',
    ]]);

    postCspReport($body, 'application/csp-report')->assertNoContent();
});

test('accepts an application/reports+json array body', function () {
    Log::shouldReceive('channel')->with('csp')->andReturnSelf();
    Log::shouldReceive('info')->once()->withArgs(function (string $message, array $context) {
        return $message === 'csp-violation' && $context['violated_directive'] === 'style-src';
    });

    $body = json_encode([[
        'type' => 'csp-violation',
        'body' => [
            'documentURL' => 'https://app.test/x',
            'effectiveDirective' => 'style-src',
            'blockedURL' => 'inline',
        ],
    ]]);

    postCspReport($body, 'application/reports+json')->assertNoContent();
});

test('works without a CSRF token', function () {
    Log::shouldReceive('channel')->with('csp')->andReturnSelf();
    Log::shouldReceive('info')->once();

    // No session/token supplied at all — a 419 here would mean CSRF wasn't exempt.
    postCspReport(json_encode(['csp-report' => ['violated-directive' => 'img-src']]), 'application/csp-report')
        ->assertNoContent();
});

test('drops an oversized body without logging', function () {
    Log::shouldReceive('channel')->never();

    $huge = json_encode(['csp-report' => ['blocked-uri' => str_repeat('a', 20000)]]);

    postCspReport($huge, 'application/csp-report')->assertNoContent();
});

test('ignores malformed JSON without logging', function () {
    Log::shouldReceive('channel')->never();

    postCspReport('{not valid json', 'application/csp-report')->assertNoContent();
});

test('is throttled per IP', function () {
    Log::shouldReceive('channel')->with('csp')->andReturnSelf();
    Log::shouldReceive('info')->andReturnNull();

    $body = json_encode(['csp-report' => ['violated-directive' => 'script-src']]);

    for ($i = 0; $i < 10; $i++) {
        postCspReport($body, 'application/csp-report')->assertNoContent();
    }

    postCspReport($body, 'application/csp-report')->assertStatus(429);
});
