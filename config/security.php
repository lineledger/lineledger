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

];
