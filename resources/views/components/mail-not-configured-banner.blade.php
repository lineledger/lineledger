{{-- Shown to site admins until outgoing mail works (see App\Support\MailSettings). --}}
@if (auth()->user()?->site_admin && ! \App\Support\MailSettings::configured() && ! request()->routeIs('admin.mail'))
    <flux:callout variant="warning" icon="envelope" class="mb-6" data-test="mail-not-configured-banner">
        <flux:callout.heading>{{ __('Email isn\'t set up yet') }}</flux:callout.heading>
        <flux:callout.text>
            {{ __('LineLedger can\'t send invitations, password resets, reminders or alerts until it has a mail server, and new accounts can\'t verify their address. Keep registration closed until then.') }}
            <flux:callout.link :href="route('admin.mail')" wire:navigate>{{ __('Set up email') }}</flux:callout.link>
        </flux:callout.text>
    </flux:callout>
@endif
