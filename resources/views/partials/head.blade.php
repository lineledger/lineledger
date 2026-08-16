<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

@php
    $companyName = app()->bound('current_company') ? app('current_company')->brandDisplayName() : null;
    $appName = 'Line Ledger';
    $sectionTitle = filled($title ?? null) ? $title : null;
    $documentTitle = match (true) {
        $companyName !== null && $sectionTitle !== null => $companyName.' - '.$sectionTitle,
        $companyName !== null => $companyName,
        $sectionTitle !== null => $sectionTitle.' - '.$appName,
        default => $appName,
    };
@endphp
<title>{{ $documentTitle }}</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])

{{--
    Default to the light theme on a user's first visit while keeping the
    Light / Dark / System switcher fully functional. Flux encodes "System" as
    the *absence* of the flux.appearance key, so a one-time "seeded" marker lets
    us seed light once without re-applying it after the user explicitly picks
    System. Must run before the appearance directive, which reads flux.appearance.
--}}
<script nonce="{{ Vite::cspNonce() }}">
    (function () {
        try {
            if (! localStorage.getItem('flux.appearance') && ! localStorage.getItem('flux.appearance.seeded')) {
                localStorage.setItem('flux.appearance', 'light');
            }
            localStorage.setItem('flux.appearance.seeded', '1');
        } catch (e) {}
    })();
</script>
@fluxAppearance(['nonce' => Vite::cspNonce()])
