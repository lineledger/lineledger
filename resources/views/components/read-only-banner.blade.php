{{-- The organization's notice from App\Contracts\CompanyAccess (why it is read-only, say), on every page inside it. Not dismissable. --}}
@php
    $readOnlyCompany = app()->bound('current_company') ? app('current_company') : null;
    $readOnlyNotice = $readOnlyCompany instanceof \App\Models\Company
        ? app(\App\Contracts\CompanyAccess::class)->readOnlyNotice($readOnlyCompany)
        : null;
@endphp
@if (is_string($readOnlyNotice) && trim($readOnlyNotice) !== '')
    <flux:callout variant="warning" icon="lock-closed" class="mb-4 py-2" data-test="read-only-banner">
        <flux:callout.text>{{ $readOnlyNotice }}</flux:callout.text>
    </flux:callout>
@endif
