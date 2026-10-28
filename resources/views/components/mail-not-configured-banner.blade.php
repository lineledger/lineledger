{{-- Shown to site admins until outgoing mail works (see App\Support\MailSettings). --}}
@if (auth()->user()?->site_admin && ! \App\Support\MailSettings::configured() && ! request()->routeIs('admin.mail'))
    <flux:callout variant="warning" icon="envelope" class="mb-6" data-test="mail-not-configured-banner">
        <flux:callout.heading>{{ __('Email isn\'t set up yet') }}</flux:callout.heading>
        <flux:callout.text>
            {{ __('LineLedger can\'t send invitations, password resets, reminders or alerts until it has a mail server, and new accounts can\'t verify their address. Keep registration closed until then.') }}
            {{-- The site admin area needs 2FA (EnsureSiteAdmin), so point an admin without it at that step first. --}}
            @if (auth()->user()->hasEnabledTwoFactorAuthentication())
                <flux:callout.link :href="route('admin.mail')" wire:navigate data-test="mail-banner-set-up">{{ __('Set up email') }}</flux:callout.link>
            @else
                {{ __('Email is set up in the site admin area, which needs two-factor authentication on your account.') }}
                <flux:callout.link :href="route('security.edit')" wire:navigate data-test="mail-banner-enable-2fa">{{ __('Enable two-factor authentication') }}</flux:callout.link>
            @endif
        </flux:callout.text>
    </flux:callout>
@endif
