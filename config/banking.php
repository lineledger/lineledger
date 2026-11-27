<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Bank statement import
    |--------------------------------------------------------------------------
    |
    | Settings for the manual bank-statement upload + auto-clearing flow (the
    | Plaid-free reconciliation pre-processor). The deterministic core works with
    | no configuration; the optional AI layer is opt-in and degrades to the manual
    | column wizard when disabled or unkeyed.
    |
    */
    'statement_import' => [

        'allowed_extensions' => ['csv', 'xlsx', 'xls', 'ofx', 'qfx', 'qbo', 'pdf'],

        'max_kilobytes' => (int) env('BANK_IMPORT_MAX_KILOBYTES', 20 * 1024),

        'match' => [
            // How many days apart a statement line and a book entry may be and
            // still be considered the same transaction.
            'date_tolerance_days' => (int) env('BANK_IMPORT_DATE_TOLERANCE_DAYS', 4),
        ],

        'ai' => [
            // Master switch. When false (default) the importer is fully
            // deterministic — no statement data ever leaves the server.
            'enabled' => (bool) env('BANK_IMPORT_AI_ENABLED', false),

            // null | prism | http — which outbound client backs the AI layer.
            'driver' => env('BANK_IMPORT_AI_DRIVER', 'prism'),

            // Any model that supports structured outputs.
            'model' => env('BANK_IMPORT_AI_MODEL', 'claude-sonnet-5-5'),

            // output_config.effort: low | medium | high | xhigh | max. Low suits
            // extraction and classification (the model skips thinking on most simple
            // requests). Set it empty to omit the field, for a model without effort
            // support (e.g. Claude Haiku 4.5).
            'effort' => env('BANK_IMPORT_AI_EFFORT', 'low'),

            // Only a small sample of rows is ever sent for CSV mapping inference.
            'max_sample_rows' => (int) env('BANK_IMPORT_AI_SAMPLE_ROWS', 15),

            'timeout' => (int) env('BANK_IMPORT_AI_TIMEOUT', 60),
        ],

        'pdf' => [
            // auto = use poppler's `pdftotext -layout` when available, else the
            // pure-PHP smalot/pdfparser fallback.
            'extractor' => env('BANK_IMPORT_PDF_EXTRACTOR', 'auto'),
        ],
    ],

];
