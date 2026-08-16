<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cloudflare Turnstile
    |--------------------------------------------------------------------------
    |
    | Turnstile is Cloudflare's CAPTCHA alternative. It is a JavaScript widget
    | plus a server-side token check, and works whether or not the site is
    | proxied through Cloudflare — it does not depend on DNS or the orange
    | cloud, only on a site key / secret key pair minted in the dashboard.
    |
    | The feature is OFF until both keys are present, so self-hosted installs,
    | local development, and the test suite are never gated. Setting
    | TURNSTILE_ENABLED=false is a hard kill switch that overrides the keys.
    |
    */

    'enabled' => env('TURNSTILE_ENABLED', true),

    /*
    | The site key is public (it ships in the page markup); only the secret is
    | sensitive. PUBLIC_TURNSTILE_SITE_KEY is accepted as a fallback because the
    | deployed environments already define the key pair under that name — the
    | alias avoids a rename that would have to land in local and Forge env at the
    | same moment to avoid a window with the challenge silently switched off.
    */
    'site_key' => env('TURNSTILE_SITE_KEY', env('PUBLIC_TURNSTILE_SITE_KEY')),

    'secret_key' => env('TURNSTILE_SECRET_KEY'),

    'verify_url' => env(
        'TURNSTILE_VERIFY_URL',
        'https://challenges.cloudflare.com/turnstile/v0/siteverify',
    ),

    /*
    | Seconds to wait on Cloudflare's siteverify endpoint before giving up.
    | Kept short: this call sits in the request path of a form submission.
    */
    'timeout' => env('TURNSTILE_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Protected routes
    |--------------------------------------------------------------------------
    |
    | Route name => behaviour when Cloudflare itself is unreachable (timeout or
    | connection error). A token that Cloudflare actively *rejects* is always a
    | failure; `fail_open` only decides what happens when we can't get an answer
    | at all.
    |
    | Registration fails closed — blocking fake signups is the whole point, and
    | a would-be customer who hits a Cloudflare outage can retry. Login and the
    | password-reset link fail open, because hard-failing there would lock real
    | users out of their own books during an outage they can't do anything about.
    |
    | `action` is a label Cloudflare reports back in analytics so you can see
    | solve rates per form.
    |
    */

    'routes' => [
        'register.store' => ['action' => 'register', 'fail_open' => false],
        'login.store' => ['action' => 'login', 'fail_open' => true],
        'password.email' => ['action' => 'password-reset', 'fail_open' => true],
    ],

];
