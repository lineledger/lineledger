@php
    $logo = (string) config('brand.logo');
    $logoDark = (string) config('brand.logo_dark');
    $alt = config('app.name', 'Ledger');
@endphp

@if ($logoDark !== '')
    <img src="{{ $logo }}" alt="{{ $alt }}" {{ $attributes->merge(['class' => 'dark:hidden']) }} />
    <img src="{{ $logoDark }}" alt="{{ $alt }}" {{ $attributes->merge(['class' => 'hidden dark:block']) }} />
@else
    <img src="{{ $logo }}" alt="{{ $alt }}" {{ $attributes }} />
@endif
