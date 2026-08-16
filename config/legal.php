<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Legal documents
    |--------------------------------------------------------------------------
    |
    | The legal documents published on the marketing site. Keyed by a stable
    | document key (used in legal_acceptances.document_key — never rename a key
    | once shipped). Each entry declares:
    |
    |   title               Human label shown in the app.
    |   slug                Path on the marketing site (joined to the region's
    |                       marketing base URL from config('app.marketing_urls')).
    |   version             The document's current version. Mirror the site's
    |                       `lastUpdated` date. Bump this whenever the document
    |                       changes — users who accepted an older version are then
    |                       forced to re-accept on their next request.
    |   requires_acceptance Whether users must explicitly agree (and re-agree on
    |                       change). Reference-only docs are false: shown in the
    |                       Legal settings tab, but never gated.
    |
    */

    'documents' => [
        'terms' => [
            'title' => 'Terms of Service',
            'slug' => '/terms',
            'version' => '2026-07-28',
            'requires_acceptance' => true,
        ],
        'privacy' => [
            'title' => 'Privacy Policy',
            'slug' => '/privacy',
            'version' => '2026-07-28',
            'requires_acceptance' => true,
        ],
        'dpa' => [
            'title' => 'Data Processing Addendum',
            'slug' => '/dpa',
            'version' => '2026-07-28',
            'requires_acceptance' => false,
        ],
        'security' => [
            'title' => 'Security',
            'slug' => '/security',
            'version' => '2026-07-28',
            'requires_acceptance' => false,
        ],
        'subprocessors' => [
            'title' => 'Sub-processors',
            'slug' => '/subprocessors',
            'version' => '2026-07-28',
            'requires_acceptance' => false,
        ],
    ],

];
