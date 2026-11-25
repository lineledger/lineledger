<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Content-Security-Policy
    |--------------------------------------------------------------------------
    |
    | Violation reporting for the CSP emitted by App\Http\Middleware\SecurityHeaders.
    | When `reporting` is on, the policy carries report-uri/report-to pointing at
    | the POST /csp-report endpoint, which logs to the `csp` log channel. Sampling
    | keeps a noisy rollout (or a hostile flood) from filling the log — 1.0 keeps
    | every report, 0.1 keeps ~10%.
    |
    | `mode` controls how the policy is delivered:
    |   - 'off'          no CSP header at all (self-host escape hatch for custom assets)
    |   - 'report-only'  Content-Security-Policy-Report-Only (default — observe, don't block)
    |   - 'enforce'      Content-Security-Policy (blocks; flip only after the report
    |                    log is clean under the nonce policy)
    |
    */

    'csp' => [
        'mode' => env('CSP_MODE', 'report-only'),
        'reporting' => (bool) env('CSP_REPORTING', true),
        'report_sample' => (float) env('CSP_REPORT_SAMPLE', 1.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Features that need two-factor authentication
    |--------------------------------------------------------------------------
    |
    | A comma-separated list of App\Enums\SensitiveFeature values. A listed
    | feature runs only once the organization's Owner has confirmed two-factor
    | authentication, and, when someone is using it, that person has too:
    |
    |   customer_email   every email to a customer or employee
    |   ai               receipt OCR, AI categorization, AI bank import, AI insights
    |   invitations      inviting people into an organization
    |   api              creating API keys, connecting an assistant over MCP
    |   stripe_connect   connecting a Stripe account
    |
    | Empty (the default) changes nothing. See App\Support\Security\SensitiveFeatureGate.
    |
    */

    'two_factor_required_for' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TWO_FACTOR_REQUIRED_FOR', '')),
    ))),

];
