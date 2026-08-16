<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Document inbox
    |--------------------------------------------------------------------------
    |
    | The receipt/bill inbox: drag-drop (and, later, email-forwarded) documents
    | are staged, OCR'd, reviewed and promoted into draft bills/expenses. The
    | inbox itself works with no configuration; the OCR layer is doubly opt-in —
    | the operator enables it here (plus ANTHROPIC_API_KEY in services.php) AND
    | each company flips its own settings switch (inbox.ocr_enabled) before any
    | document is sent to Anthropic.
    |
    */

    'allowed_extensions' => ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif'],

    'max_kilobytes' => (int) env('INBOX_MAX_KILOBYTES', 10 * 1024),

    'ai' => [
        // Operator master switch. When false (default), no document is ever sent
        // to Anthropic — uploads go straight to manual review.
        'enabled' => (bool) env('INBOX_OCR_ENABLED', false),

        // null | http — which outbound client backs the OCR layer.
        'driver' => env('INBOX_OCR_DRIVER', 'http'),

        'model' => env('INBOX_OCR_MODEL', 'claude-sonnet-4-6'),

        'timeout' => (int) env('INBOX_OCR_TIMEOUT', 60),
    ],

    /*
    | Inbound email ingest (P4.2). Greenfield: an inbound provider posts a signed
    | webhook to a public route, which resolves the tenant from the recipient
    | address token and stages the attachments as inbox items.
    */
    'email' => [
        'enabled' => (bool) env('INBOUND_EMAIL_ENABLED', false),

        // The domain inbox addresses are minted under, e.g. "inbox.example.com"
        // → "{token}@inbox.example.com".
        'domain' => env('INBOUND_DOMAIN'),

        // HMAC secret the inbound webhook is verified against before processing.
        'signing_secret' => env('INBOUND_EMAIL_SIGNING_SECRET'),
    ],

];
