<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Daily insight
    |--------------------------------------------------------------------------
    |
    | The nightly "Did you know?" dashboard card. Detection, selection, and
    | template phrasing are fully deterministic and always on; the optional
    | AI narration layer is doubly opt-in — the operator enables it here
    | (plus ANTHROPIC_API_KEY in services.php) AND each company flips its own
    | settings switch before any aggregate figures are sent to Anthropic.
    |
    */
    'ai' => [
        // Operator master switch. When false (default), narration is fully
        // deterministic regardless of company opt-in — nothing leaves the
        // server.
        'enabled' => (bool) env('INSIGHTS_AI_ENABLED', false),

        'model' => env('INSIGHTS_AI_MODEL', 'claude-sonnet-4-6'),

        // Nightly queue job — nobody is waiting on this request.
        'timeout' => (int) env('INSIGHTS_AI_TIMEOUT', 20),

        // How many top-ranked candidates Claude may choose between.
        'max_candidates' => (int) env('INSIGHTS_AI_MAX_CANDIDATES', 3),
    ],

];
