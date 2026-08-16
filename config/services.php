<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'exchange_rates' => [
        'driver' => env('EXCHANGE_RATE_DRIVER', 'frankfurter'),
        'base_url' => env('FRANKFURTER_BASE_URL', 'https://api.frankfurter.dev/v1'),

        // Freshness monitoring (rates:health command + /health/fx endpoint).
        // The daily fetch runs at 06:00 and advances fetched_at every day, so a
        // rate older than ~24h (default 26h grace) means the fetch is failing.
        'health' => [
            'max_age_hours' => (int) env('EXCHANGE_RATE_HEALTH_MAX_AGE_HOURS', 26),
            'alert_email' => env('EXCHANGE_RATE_HEALTH_ALERT_EMAIL', 'hello@lineledger.ca'),
        ],
    ],

    // Nightly ledger integrity sweep (integrity:check command). Emails ops when a
    // company's books fail to reconcile: broken audit hash chain, unbalanced GL,
    // or drifted account-balance cache. Falls back to the FX health address.
    'ledger_integrity' => [
        'alert_email' => env('LEDGER_INTEGRITY_ALERT_EMAIL', env('EXCHANGE_RATE_HEALTH_ALERT_EMAIL', 'hello@lineledger.ca')),
    ],

    // Operational alerting for the scheduler and queue (scheduled-task crashes
    // via SchedulerFailureAlert, failed queued jobs via ops:monitor-failed-jobs).
    // These are the "did the plumbing break" alarms, distinct from the domain
    // alarms above. Falls back to the FX health address.
    'ops_alerts' => [
        'alert_email' => env('OPS_ALERT_EMAIL', env('EXCHANGE_RATE_HEALTH_ALERT_EMAIL', 'hello@lineledger.ca')),
        'failed_jobs_window_minutes' => (int) env('OPS_FAILED_JOBS_WINDOW_MINUTES', 60),
    ],

    // Scheduled security-log anomaly scan (security:monitor command). Emails ops
    // on failed-login spikes, account lockouts, mass API-key revocation, or
    // privilege escalation. The scheduled-run history is SOC 2 (CC7.2/CC7.3)
    // evidence that security events are monitored and responded to.
    'security_alerts' => [
        'alert_email' => env('SECURITY_ALERT_EMAIL', env('EXCHANGE_RATE_HEALTH_ALERT_EMAIL', 'hello@lineledger.ca')),
        'window_minutes' => (int) env('SECURITY_ALERT_WINDOW_MINUTES', 60),
        'failed_login_threshold' => (int) env('SECURITY_ALERT_FAILED_LOGIN_THRESHOLD', 10),
        'api_key_revocation_threshold' => (int) env('SECURITY_ALERT_API_KEY_REVOCATION_THRESHOLD', 5),
    ],

    // Optional. Backs the opt-in AI assist for bank-statement import (column-mapping
    // inference for messy CSVs, transaction extraction for PDFs). Off unless both
    // banking.statement_import.ai.enabled is true and a key is present.
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
    ],

    'stripe' => [
        // Platform-level credentials. Per-company funds flow through Stripe Connect
        // (companies link their own account); we never store a company's secret key.
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'client_id' => env('STRIPE_CLIENT_ID'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

];
