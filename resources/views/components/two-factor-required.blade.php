{{-- The locked state of a control whose feature this installation keeps behind two-factor authentication (App\Support\Security\SensitiveFeatureGate): renders nothing once the feature may be used. --}}
@props(['feature', 'company'])

@php
    $twoFactorRefusal = $company instanceof \App\Models\Company
        ? app(\App\Support\Security\SensitiveFeatureGate::class)->refusal($feature, $company, auth()->user())
        : null;
@endphp

@if ($twoFactorRefusal !== null)
    <div {{ $attributes->class('flex items-start gap-2 rounded-md bg-zinc-50 p-3 text-sm dark:bg-white/5') }} data-test="two-factor-required-{{ $feature->value }}">
        <flux:icon.lock-closed variant="mini" class="mt-0.5 shrink-0 text-muted-foreground" />
        <div>
            <p class="font-medium">{{ __('Requires two-factor authentication') }}</p>
            <p class="text-muted-foreground">{{ $twoFactorRefusal }}</p>
            <flux:link :href="route('security.edit')" wire:navigate class="text-sm">{{ __('Open Settings → Security') }}</flux:link>
        </div>
    </div>
@endif
