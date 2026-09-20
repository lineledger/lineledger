<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application logo
    |--------------------------------------------------------------------------
    |
    | The mark shown on the sign-in, registration and onboarding screens, and in
    | the sidebar until an organization uploads a logo of its own. Anything the
    | browser can fetch works: a file under public/, a storage:link path such as
    | /storage/brand/logo.png (which survives a deploy, unlike a file dropped in
    | public/), or an absolute URL on your object store.
    |
    | BRAND_LOGO_DARK is optional. Set it and that variant is swapped in under
    | the dark theme; leave it unset and the one logo serves both themes.
    |
    | The name beside the logo — and in the browser tab and outgoing mail — is
    | APP_NAME, not a key here.
    |
    | Self-hosting note: the project's trademark notice asks you to replace the
    | LineLedger marks with your own branding. These keys, APP_NAME, and the
    | favicons in public/ are the whole of it — no fork required.
    |
    */

    'logo' => env('BRAND_LOGO', '/logo/line-ledger-logo.png'),

    'logo_dark' => env('BRAND_LOGO_DARK'),

    /*
    |--------------------------------------------------------------------------
    | Footer
    |--------------------------------------------------------------------------
    |
    | "owner" is whoever operates this deployment — the name in the copyright
    | line on the dashboard, the docs, and the sign-in page.
    |
    | "project_links" controls the upstream links beside it: the running version
    | (linked to its release notes), the licence, the source repository, and the
    | legal documents on the marketing site. Turn them off and the footer keeps
    | the copyright line and the plain version number.
    |
    | Read this before you turn them off: the source link is how this app offers
    | its Corresponding Source to the people using it over the network, which
    | AGPL-3.0 section 13 requires of anyone who runs a modified version as a
    | network service. Hiding the links does not remove the obligation — if you
    | have modified the code, make the offer somewhere your users can find it.
    |
    */

    'footer' => [

        'owner' => env('BRAND_FOOTER_OWNER', 'Local Foundry Inc.'),

        'project_links' => (bool) env('BRAND_SHOW_PROJECT_LINKS', true),

    ],

];
