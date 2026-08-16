<?php

use App\Concerns\PasswordValidationRules;
use App\Enums\SecurityEvent;
use App\Services\Audit\SecurityLogRecorder;
use App\Services\Security\TrustedDeviceManager;
use App\Support\UserAgentSummary;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Livewire\Attributes\Title;
use Livewire\Component;
/* @chisel-passkeys */
use Laravel\Passkeys\Actions\DeletePasskey;
use Livewire\Attributes\Locked;
/* @end-chisel-passkeys */
/* @chisel-2fa */
use Livewire\Attributes\On;
/* @end-chisel-2fa */

new #[Title('Security settings')] class extends Component {
    use PasswordValidationRules;

    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    /* @chisel-2fa */
    public bool $canManageTwoFactor;

    public bool $twoFactorEnabled;

    public bool $requiresConfirmation;

    /** @var array<int, array<string, mixed>> */
    public array $trustedDevices = [];
    /* @end-chisel-2fa */

    /** @var array<int, array<string, mixed>> */
    public array $sessions = [];

    public bool $showLogoutOthersModal = false;

    public string $logoutPassword = '';

    /* @chisel-passkeys */
    #[Locked]
    public bool $canManagePasskeys;

    #[Locked]
    public array $passkeys = [];

    public bool $showDeleteModal = false;

    #[Locked]
    public ?int $deletingPasskeyId = null;

    #[Locked]
    public string $deletingPasskeyName = '';
    /* @end-chisel-passkeys */

    /**
     * Mount the component.
     */
    public function mount(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        /* @chisel-2fa */
        $this->canManageTwoFactor = Features::canManageTwoFactorAuthentication();

        if ($this->canManageTwoFactor) {
            if (Fortify::confirmsTwoFactorAuthentication() && is_null(auth()->user()->two_factor_confirmed_at)) {
                $disableTwoFactorAuthentication(auth()->user());
            }

            $this->twoFactorEnabled = auth()->user()->hasEnabledTwoFactorAuthentication();
            $this->requiresConfirmation = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');

            if ($this->twoFactorEnabled) {
                $this->loadTrustedDevices();
            }
        }
        /* @end-chisel-2fa */

        /* @chisel-passkeys */
        $this->canManagePasskeys = Features::canManagePasskeys();

        if ($this->canManagePasskeys) {
            $this->loadPasskeys();
        }
        /* @end-chisel-passkeys */

        $this->loadSessions();
    }

    /**
     * List this user's active database sessions, newest activity first.
     */
    public function loadSessions(): void
    {
        $currentId = session()->getId();

        $this->sessions = DB::table('sessions')
            ->where('user_id', auth()->id())
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'ip_address' => $row->ip_address,
                'agent' => UserAgentSummary::label($row->user_agent),
                'last_active_diff' => \Carbon\CarbonImmutable::createFromTimestamp((int) $row->last_activity)->diffForHumans(),
                'is_current' => $row->id === $currentId,
            ])
            ->toArray();
    }

    /**
     * Revoke a single other session (never the current one).
     */
    public function revokeSession(string $id, SecurityLogRecorder $recorder): void
    {
        if ($id === session()->getId()) {
            return;
        }

        $deleted = DB::table('sessions')
            ->where('id', $id)
            ->where('user_id', auth()->id())
            ->delete();

        if ($deleted > 0) {
            $recorder->record(SecurityEvent::SessionRevoked, auth()->user());
        }

        $this->loadSessions();

        Flux::toast(variant: 'success', text: __('Session signed out.'));
    }

    public function confirmLogoutOthers(): void
    {
        $this->logoutPassword = '';
        $this->showLogoutOthersModal = true;
    }

    /**
     * Sign out every other session after a password re-challenge. Auth::logoutOtherDevices
     * rotates the session password hash (invalidating the others) and dispatches
     * OtherDeviceLogout, which SecurityLogListener already records; we then drop the
     * other rows so they disappear from this list immediately.
     */
    public function logoutOtherSessions(): void
    {
        if (! Hash::check($this->logoutPassword, auth()->user()->password)) {
            throw ValidationException::withMessages([
                'logoutPassword' => __('The provided password is incorrect.'),
            ]);
        }

        Auth::logoutOtherDevices($this->logoutPassword);

        DB::table('sessions')
            ->where('user_id', auth()->id())
            ->where('id', '!=', session()->getId())
            ->delete();

        $this->showLogoutOthersModal = false;
        $this->logoutPassword = '';
        $this->loadSessions();

        Flux::toast(variant: 'success', text: __('All other sessions signed out.'));
    }

    /**
     * Update the password for the currently authenticated user.
     */
    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => $this->currentPasswordRules(),
                'password' => $this->passwordRules(),
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        Auth::user()->update([
            'password' => $validated['password'],
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');

        Flux::toast(variant: 'success', text: __('Password updated.'));
    }

    /* @chisel-passkeys */
    /**
     * Load the user's passkeys.
     */
    public function loadPasskeys(): void
    {
        $this->passkeys = auth()->user()->passkeys()
            ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
            ->latest()
            ->get()
            ->map(fn ($passkey) => [
                'id' => $passkey->id,
                'name' => $passkey->name,
                'authenticator' => $passkey->authenticator,
                'created_at_diff' => $passkey->created_at->diffForHumans(),
                'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
            ])
            ->toArray();
    }

    /**
     * Show the delete confirmation modal.
     */
    public function confirmDelete(int $passkeyId): void
    {
        $passkey = auth()->user()->passkeys()->findOrFail($passkeyId);

        $this->deletingPasskeyId = $passkey->id;
        $this->deletingPasskeyName = $passkey->name;
        $this->showDeleteModal = true;
    }

    /**
     * Delete the passkey.
     */
    public function deletePasskey(DeletePasskey $deletePasskey): void
    {
        if (! $this->deletingPasskeyId) {
            return;
        }

        $passkey = auth()->user()->passkeys()->findOrFail($this->deletingPasskeyId);

        $deletePasskey(auth()->user(), $passkey);

        $this->closeDeleteModal();
        $this->loadPasskeys();
    }

    /**
     * Close the delete confirmation modal.
     */
    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->deletingPasskeyId = null;
        $this->deletingPasskeyName = '';
    }
    /* @end-chisel-passkeys */

    /* @chisel-2fa */
    /**
     * Handle the two-factor authentication enabled event.
     */
    #[On('two-factor-enabled')]
    public function onTwoFactorEnabled(): void
    {
        $this->twoFactorEnabled = true;
    }

    /**
     * Disable two-factor authentication for the user.
     */
    public function disable(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        $disableTwoFactorAuthentication(auth()->user());

        // Disabling 2FA invalidates every "remember this device" trust.
        app(TrustedDeviceManager::class)->forgetAllDevices(auth()->user());

        $this->twoFactorEnabled = false;
        $this->trustedDevices = [];
    }

    /**
     * Load the user's active "remember this device" trusts for display.
     */
    public function loadTrustedDevices(): void
    {
        $this->trustedDevices = auth()->user()->twoFactorRememberedDevices()
            ->where('expires_at', '>', now())
            ->latest('last_used_at')
            ->get()
            ->map(fn ($device) => [
                'id' => $device->id,
                'ip_address' => $device->ip_address,
                'last_used_at_diff' => $device->last_used_at?->diffForHumans(),
                'created_at_diff' => $device->created_at->diffForHumans(),
                'expires_at_diff' => $device->expires_at->diffForHumans(),
            ])
            ->toArray();
    }

    /**
     * Revoke every trusted device, forcing 2FA at the next login everywhere.
     */
    public function forgetTrustedDevices(): void
    {
        app(TrustedDeviceManager::class)->forgetAllDevices(auth()->user());

        $this->loadTrustedDevices();

        Flux::toast(variant: 'success', text: __('Trusted devices forgotten.'));
    }
    /* @end-chisel-2fa */
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Security settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Update password')" :subheading="__('Ensure your account is using a long, random password to stay secure')">
        <form method="POST" wire:submit="updatePassword" class="mt-6 space-y-6">
            <flux:input
                wire:model="current_password"
                :label="__('Current password')"
                type="password"
                required
                autocomplete="current-password"
                viewable
            />
            <flux:input
                wire:model="password"
                :label="__('New password')"
                type="password"
                required
                autocomplete="new-password"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />
            <flux:input
                wire:model="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                viewable
            />

            <div class="flex items-center gap-4">
                <flux:button variant="primary" type="submit" data-test="update-password-button">
                    {{ __('Save') }}
                </flux:button>
            </div>
        </form>

        {{-- @chisel-2fa --}}
        @if ($canManageTwoFactor)
            <section class="mt-12">
                <flux:heading>{{ __('Two-factor authentication') }}</flux:heading>
                <flux:subheading>{{ __('Manage your two-factor authentication settings') }}</flux:subheading>

                <div class="flex flex-col w-full mx-auto space-y-6 text-sm" wire:cloak>
                    @if ($twoFactorEnabled)
                        <div class="space-y-4">
                            <flux:text>
                                {{ __('You will be prompted for a secure, random pin during login, which you can retrieve from the TOTP-supported application on your phone.') }}
                            </flux:text>

                            <div class="flex justify-start">
                                <flux:button
                                    variant="danger"
                                    wire:click="disable"
                                >
                                    {{ __('Disable 2FA') }}
                                </flux:button>
                            </div>

                            <livewire:pages::settings.two-factor.recovery-codes :$requiresConfirmation />

                            <div class="py-6 space-y-6 border shadow-sm rounded-xl border-border" wire:cloak>
                                <div class="px-6 space-y-2">
                                    <div class="flex items-center gap-2">
                                        <flux:icon.computer-desktop variant="outline" class="size-4" />
                                        <flux:heading size="lg" level="3">{{ __('Trusted devices') }}</flux:heading>
                                    </div>
                                    <flux:text variant="subtle">
                                        {{ __('Devices where you chose to skip the two-factor prompt at login. They never bypass the extra prompt on this page or in company settings.') }}
                                    </flux:text>
                                </div>

                                <div class="px-6 space-y-4">
                                    @forelse ($trustedDevices as $device)
                                        <div class="flex items-center gap-4 {{ ! $loop->last ? 'pb-4 border-b border-border' : '' }}">
                                            <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted">
                                                <flux:icon.computer-desktop class="size-5 text-muted-foreground" />
                                            </div>
                                            <div class="space-y-1">
                                                <p class="font-medium tracking-tight">{{ $device['ip_address'] ?? __('Unknown IP') }}</p>
                                                <p class="text-muted-foreground text-xs">
                                                    @if ($device['last_used_at_diff'])
                                                        {{ __('Last used :time', ['time' => $device['last_used_at_diff']]) }}
                                                    @else
                                                        {{ __('Added :time', ['time' => $device['created_at_diff']]) }}
                                                    @endif
                                                    <span class="opacity-50 mx-1">/</span>
                                                    {{ __('Expires :time', ['time' => $device['expires_at_diff']]) }}
                                                </p>
                                            </div>
                                        </div>
                                    @empty
                                        <flux:text variant="subtle" class="text-sm">
                                            {{ __('No trusted devices.') }}
                                        </flux:text>
                                    @endforelse

                                    @if (filled($trustedDevices))
                                        <flux:button
                                            variant="danger"
                                            icon="trash"
                                            wire:click="forgetTrustedDevices"
                                            wire:confirm="{{ __('Forget all trusted devices? Each will require two-factor at the next login.') }}"
                                        >
                                            {{ __('Forget all trusted devices') }}
                                        </flux:button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @else
                        <div class="space-y-4">
                            <flux:text variant="subtle">
                                {{ __('When you enable two-factor authentication, you will be prompted for a secure pin during login. This pin can be retrieved from a TOTP-supported application on your phone.') }}
                            </flux:text>

                            <flux:modal.trigger name="two-factor-setup-modal">
                                <flux:button
                                    variant="primary"
                                    wire:click="$dispatch('start-two-factor-setup')"
                                >
                                    {{ __('Enable 2FA') }}
                                </flux:button>
                            </flux:modal.trigger>

                            <livewire:pages::settings.two-factor-setup-modal :requires-confirmation="$requiresConfirmation" />
                        </div>
                    @endif
                </div>
            </section>
        @endif
        {{-- @end-chisel-2fa --}}

        {{-- @chisel-passkeys --}}
        @if ($canManagePasskeys)
            <section class="mt-12">
                <flux:heading>{{ __('Passkeys') }}</flux:heading>
                <flux:subheading>{{ __('Manage your passkeys for passwordless sign-in') }}</flux:subheading>

                <div class="mt-6 flex flex-col w-full mx-auto space-y-6 text-sm" wire:cloak>
                    <div class="border rounded-lg border-border overflow-hidden">
                        @forelse ($passkeys as $passkey)
                            <div class="flex items-center justify-between p-4 {{ ! $loop->last ? 'border-b border-border' : '' }}">
                                <div class="flex items-center gap-4">
                                    <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted">
                                        <flux:icon.key class="size-5 text-muted-foreground" />
                                    </div>
                                    <div class="space-y-1">
                                        <div class="flex items-center gap-2.5">
                                            <p class="font-medium tracking-tight">{{ $passkey['name'] }}</p>
                                            @if ($passkey['authenticator'])
                                                <flux:badge size="sm">{{ $passkey['authenticator'] }}</flux:badge>
                                            @endif
                                        </div>
                                        <p class="text-muted-foreground text-xs">
                                            {{ __('Added :time', ['time' => $passkey['created_at_diff']]) }}
                                            @if ($passkey['last_used_at_diff'])
                                                <span class="opacity-50 mx-1">/</span>
                                                {{ __('Last used :time', ['time' => $passkey['last_used_at_diff']]) }}
                                            @endif
                                        </p>
                                    </div>
                                </div>

                                <flux:button
                                    variant="ghost"
                                    size="sm"
                                    icon="trash"
                                    icon:variant="outline"
                                    wire:click="confirmDelete({{ $passkey['id'] }})"
                                    class="text-red-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-950/50"
                                />
                            </div>
                        @empty
                            <div class="p-8 text-center">
                                <div class="mx-auto mb-4 flex size-14 items-center justify-center rounded-2xl bg-muted">
                                    <flux:icon.key class="size-7 text-muted-foreground" />
                                </div>
                                <p class="font-medium">{{ __('No passkeys yet') }}</p>
                                <flux:text class="mt-1">{{ __('Add a passkey to sign in without a password') }}</flux:text>
                            </div>
                        @endforelse
                    </div>

                    <x-passkey-registration />
                </div>
            </section>
        @endif
        {{-- @end-chisel-passkeys --}}

        <section class="mt-10">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <flux:heading size="lg" level="3">{{ __('Browser sessions') }}</flux:heading>
                    <flux:text variant="subtle">{{ __('Devices where you are signed in. Sign out any you do not recognise.') }}</flux:text>
                </div>
                <flux:button variant="outline" wire:click="confirmLogoutOthers">
                    {{ __('Sign out other sessions') }}
                </flux:button>
            </div>

            <div class="mt-4 border rounded-lg border-border overflow-hidden">
                @forelse ($sessions as $session)
                    <div class="flex items-center justify-between p-4 {{ ! $loop->last ? 'border-b border-border' : '' }}">
                        <div class="space-y-1">
                            <div class="flex items-center gap-2">
                                <p class="font-medium">{{ $session['agent'] }}</p>
                                @if ($session['is_current'])
                                    <flux:badge size="sm" color="emerald">{{ __('This device') }}</flux:badge>
                                @endif
                            </div>
                            <p class="text-muted-foreground text-xs">
                                {{ $session['ip_address'] ?? __('unknown IP') }}
                                <span class="opacity-50 mx-1">/</span>
                                {{ __('Active :time', ['time' => $session['last_active_diff']]) }}
                            </p>
                        </div>

                        @unless ($session['is_current'])
                            <flux:button variant="ghost" size="sm" wire:click="revokeSession('{{ $session['id'] }}')">
                                {{ __('Sign out') }}
                            </flux:button>
                        @endunless
                    </div>
                @empty
                    <div class="p-4 text-muted-foreground text-sm">{{ __('No active sessions.') }}</div>
                @endforelse
            </div>
        </section>

        <livewire:pages::settings.api-keys />

        <livewire:pages::settings.authorized-apps />
    </x-pages::settings.layout>

    {{-- @chisel-passkeys --}}
    <flux:modal
        name="delete-passkey-modal"
        class="max-w-md md:min-w-md"
        @close="closeDeleteModal"
        wire:model="showDeleteModal"
    >
        <div class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('Remove passkey') }}</flux:heading>
                <flux:text>
                    {{ __('Are you sure you want to remove the passkey ":name"? You will no longer be able to use it to sign in.', ['name' => $deletingPasskeyName]) }}
                </flux:text>
            </div>

            <div class="flex gap-3 justify-end">
                <flux:button
                    variant="outline"
                    wire:click="closeDeleteModal"
                >
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button
                    variant="danger"
                    wire:click="deletePasskey"
                >
                    {{ __('Remove passkey') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
    {{-- @end-chisel-passkeys --}}

    <flux:modal name="logout-others-modal" class="max-w-md md:min-w-md" wire:model="showLogoutOthersModal">
        <form wire:submit="logoutOtherSessions" class="space-y-6">
            <div class="space-y-2">
                <flux:heading size="lg">{{ __('Sign out other sessions') }}</flux:heading>
                <flux:text>{{ __('Enter your password to sign out of all sessions on your other devices.') }}</flux:text>
            </div>

            <flux:input
                type="password"
                wire:model="logoutPassword"
                :label="__('Password')"
                required
                autocomplete="current-password"
            />

            <div class="flex gap-3 justify-end">
                <flux:button variant="outline" type="button" x-on:click="$wire.showLogoutOthersModal = false">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button variant="danger" type="submit">
                    {{ __('Sign out others') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>
