{{-- Per-user operator actions. Shared by the mobile card and the desktop row. --}}
<flux:dropdown align="end">
    <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" data-test="user-actions" />

    <flux:menu>
        <flux:menu.item icon="pencil" wire:click="openEdit({{ $user->id }})" data-test="edit-user">
            {{ __('Edit') }}
        </flux:menu.item>

        {{-- @chisel-email-verification --}}
        @unless ($user->hasVerifiedEmail())
            <flux:menu.item
                icon="check-badge"
                wire:click="markEmailVerified({{ $user->id }})"
                data-test="mark-email-verified"
            >{{ __('Mark email verified') }}</flux:menu.item>

            <flux:menu.item
                icon="envelope"
                wire:click="resendVerification({{ $user->id }})"
                data-test="resend-verification"
            >{{ __('Re-send verification') }}</flux:menu.item>
        @endunless
        {{-- @end-chisel-email-verification --}}

        <flux:menu.item
            icon="key"
            wire:click="sendPasswordReset({{ $user->id }})"
            wire:confirm="{{ __('Email a password reset link to :email?', ['email' => $user->email]) }}"
            data-test="send-password-reset"
        >{{ __('Send password reset') }}</flux:menu.item>

        @if ($user->hasEnabledTwoFactorAuthentication())
            <flux:menu.item
                icon="device-phone-mobile"
                wire:click="resetTwoFactor({{ $user->id }})"
                wire:confirm="{{ __('Reset two-factor authentication for :name? Their account will be protected by password alone until they enrol again.', ['name' => $user->name]) }}"
                data-test="reset-two-factor"
            >{{ __('Reset 2FA') }}</flux:menu.item>
        @endif

        <flux:menu.separator />

        @if ($user->site_admin)
            <flux:menu.item
                icon="shield-exclamation"
                wire:click="toggleSiteAdmin({{ $user->id }})"
                wire:confirm="{{ __('Remove site admin access from :name?', ['name' => $user->name]) }}"
                data-test="revoke-site-admin"
            >{{ __('Revoke site admin') }}</flux:menu.item>
        @else
            <flux:menu.item
                icon="shield-check"
                wire:click="toggleSiteAdmin({{ $user->id }})"
                wire:confirm="{{ __('Grant site admin access to :name? They will be able to manage the whole platform.', ['name' => $user->name]) }}"
                data-test="grant-site-admin"
            >{{ __('Make admin') }}</flux:menu.item>
        @endif

        @if ($user->isDisabled())
            <flux:menu.item
                icon="lock-open"
                wire:click="toggleDisabled({{ $user->id }})"
                wire:confirm="{{ __('Let :name sign in again?', ['name' => $user->name]) }}"
                data-test="enable-user"
            >{{ __('Enable account') }}</flux:menu.item>
        @else
            <flux:menu.item
                icon="lock-closed"
                variant="danger"
                wire:click="toggleDisabled({{ $user->id }})"
                wire:confirm="{{ __('Disable :name? They are signed out everywhere, and their API keys and connected apps are revoked for good — re-enabling does not bring those back.', ['name' => $user->name]) }}"
                data-test="disable-user"
            >{{ __('Disable account') }}</flux:menu.item>
        @endif
    </flux:menu>
</flux:dropdown>
