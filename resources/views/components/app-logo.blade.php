@props([
    'sidebar' => false,
])

@php
    $company = auth()->user()?->currentCompany;
    $brandName = $company?->brandDisplayName() ?? 'Ledger';
    $logoUrl = $company?->logoUrl();
    $initials = $company?->brandInitials() ?? 'L';
    $textColor = $company?->brandTextColor() ?? '#ffffff';
    $bgColor = $company?->brandBackgroundColor() ?? '#18181b';
@endphp

@if ($sidebar)
    <flux:sidebar.brand :name="$brandName" {{ $attributes }}>
        <x-slot
            name="logo"
            class="flex aspect-square size-8 items-center justify-center overflow-hidden rounded-md"
            style="background-color: {{ $bgColor }}; color: {{ $textColor }};"
        >
            @if ($logoUrl)
                <img src="{{ $logoUrl }}" alt="{{ $brandName }}" class="size-full object-cover" />
            @elseif ($company)
                <span class="text-xs font-semibold leading-none">{{ $initials }}</span>
            @else
                <x-app-logo-icon class="size-5 fill-current" />
            @endif
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="$brandName" {{ $attributes }}>
        <x-slot
            name="logo"
            class="flex aspect-square size-8 items-center justify-center overflow-hidden rounded-md"
            style="background-color: {{ $bgColor }}; color: {{ $textColor }};"
        >
            @if ($logoUrl)
                <img src="{{ $logoUrl }}" alt="{{ $brandName }}" class="size-full object-cover" />
            @elseif ($company)
                <span class="text-xs font-semibold leading-none">{{ $initials }}</span>
            @else
                <x-app-logo-icon class="size-5 fill-current" />
            @endif
        </x-slot>
    </flux:brand>
@endif
