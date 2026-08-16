<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CRA electronic filing (T4 XML)
    |--------------------------------------------------------------------------
    |
    | Settings used when generating the CRA T4 XML submission. The transmitter
    | number ("MM" account) is assigned by the CRA and MUST be set before you
    | file. The generated XML follows the documented CRA T4 layout, but the
    | exact schema version changes yearly — validate it against the current CRA
    | schema (and your transmitter details) before submitting.
    |
    */

    'transmitter' => [
        'number' => env('CRA_TRANSMITTER_NUMBER', 'MM000000'),
        'type' => env('CRA_TRANSMITTER_TYPE', '1'), // 1=submitter filing its own, etc.
        'language' => env('CRA_TRANSMITTER_LANGUAGE', 'E'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Revenu Québec electronic filing (RL-1 XML)
    |--------------------------------------------------------------------------
    |
    | Settings used when generating the Revenu Québec RL-1 XML submission. The
    | transmitter number ("numéro d'identification", NPxxxxxx) and the slip
    | preparer/source numbers are assigned by Revenu Québec and MUST be set
    | before you file. As with the T4, the RL-1 XML layout is revised yearly —
    | validate against the current Revenu Québec schema before submitting.
    |
    */

    'rl1' => [
        'transmitter_number' => env('RQ_TRANSMITTER_NUMBER', 'NP000000'),
        'preparer_number' => env('RQ_PREPARER_NUMBER', 'NP000000'),
        'slip_type' => env('RQ_RL1_SLIP_TYPE', 'R'), // R = original

        // Printing OFFICIAL paper RL-1 slips requires a Revenu Québec
        // authorization number (FS-followed-by-seven-digits), obtained through
        // the "My Account for Partners" vendor process, printed on each copy.
        // Until one is configured, RL-1 PDFs print as a working copy stamped
        // "not for filing" — file via the RL-1 XML instead.
        'authorization_number' => env('RQ_AUTHORIZATION_NUMBER'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Official slip templates (T4 / T4A / RL-1 PDFs)
    |--------------------------------------------------------------------------
    |
    | Year-end slips print onto the OFFICIAL government form when a flattened
    | copy of the year's fillable PDF is installed (the app ships with the CRA
    | T4 for recent years). Resolution order, exact year only:
    |
    |   storage/app/slip-templates/{year}/{t4|t4a|rl1}.pdf   (per deployment)
    |   resources/pdf-templates/slips/{year}/{...}.pdf       (shipped)
    |
    | CRA's fillable PDFs are encrypted XFA documents; flatten them first:
    |   gs -q -o t4.pdf -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 t4-fill-25e.pdf
    |
    | Verify an installed year with: php artisan payroll:verify-slip-templates
    | A year without a template falls back to a clearly-labelled facsimile.
    |
    */

];
