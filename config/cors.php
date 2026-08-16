<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | LineLedger serves a same-origin web app; the `/api/v1` surface is
    | authenticated by a bearer API key (not cookies/session), so it does not
    | need — and deliberately does not grant — browser cross-origin access.
    |
    | `paths` is intentionally empty: HandleCors then matches nothing and adds
    | no `Access-Control-Allow-*` headers, keeping the surface closed. This file
    | exists so that posture is explicit and versioned rather than incidental on
    | the absence of a config. To expose the token API to a browser front-end on
    | another origin later, add `'api/*'` to `paths` and pin `allowed_origins`
    | to that origin — never `['*']` together with `supports_credentials`.
    |
    */

    'paths' => [],

    'allowed_methods' => ['*'],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
