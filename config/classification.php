<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Transaction auto-classification
    |--------------------------------------------------------------------------
    |
    | Suggests a category (and, where available, a tax code) for imported
    | statement lines and uploaded receipts, based first on the company's own
    | history and — for unseen merchants — on an AI fallback. Suggest-only: the
    | review screens are pre-filled; nothing is ever posted automatically.
    |
    | The AI fallback deliberately has NO on/off switch of its own: it rides the
    | same doubly opt-in gate as the document inbox OCR (config('inbox.ai') plus
    | ANTHROPIC_API_KEY, and each company's inbox.ocr_enabled toggle), so there
    | is a single AI opt-in surface for the operator and the tenant.
    |
    */

    // How far back history is consulted. Headline is "based on how you
    // categorized this before"; a year gives far better recall than one month.
    'history_days' => (int) env('CLASSIFICATION_HISTORY_DAYS', 365),

    // Cap on prior bills/expenses scanned for a single contact's history.
    'max_history_rows' => (int) env('CLASSIFICATION_MAX_HISTORY_ROWS', 200),

    // Cap on committed statement lines scanned for description history per lookup.
    'description_history_limit' => (int) env('CLASSIFICATION_DESCRIPTION_HISTORY_LIMIT', 1000),

    'ai' => [
        // Most descriptions sent to Anthropic in a single batched request; larger
        // batches are chunked across calls.
        'max_descriptions' => (int) env('CLASSIFICATION_AI_MAX_DESCRIPTIONS', 200),
    ],

];
