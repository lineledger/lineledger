<?php

use App\Concerns\ProfileValidationRules;
use App\Enums\SecurityEvent;
use App\Models\User;
use App\Services\Audit\SecurityLogRecorder;
use App\Services\Security\AccessRevoker;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Site Admin — Users')] class extends Component {
    use ProfileValidationRules;
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    /** '' = all, 'active' = signed-in-able, 'disabled' = locked out. */
    #[Url(as: 'status')]
    public string $statusFilter = '';

    /** The user being edited. Locked — the client must not be able to retarget it. */
    #[Locked]
    public ?int $editingId = null;

    /**
     * Deliberately named for the columns they map to, not the `f_`-prefixed
     * convention used elsewhere: ProfileValidationRules' Rule::unique() infers
     * the column from the property name, so `f_email` would check a column that
     * doesn't exist and let duplicates through.
     */
    public string $name = '';

    public string $email = '';

    public string $disableReason = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->site_admin, 404);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Grant or revoke the site-admin role. Refuses to remove the last remaining
     * usable admin — a disabled admin can't sign in, so they don't count.
     */
    public function toggleSiteAdmin(int $userId): void
    {
        $user = $this->authorizedUser($userId);

        if ($user->site_admin && $this->usableSiteAdminCount() <= 1 && ! $user->isDisabled()) {
            Flux::toast(variant: 'warning', text: __('At least one site admin is required.'));

            return;
        }

        $user->forceFill(['site_admin' => ! $user->site_admin])->save();

        Flux::toast(variant: 'success', text: $user->site_admin
            ? __(':name is now a site admin.', ['name' => $user->name])
            : __('Removed site admin from :name.', ['name' => $user->name]));
    }

    public function openEdit(int $userId): void
    {
        $user = $this->authorizedUser($userId);

        $this->resetValidation();
        $this->editingId = $user->id;
        $this->name = $user->name;
        $this->email = $user->email;

        Flux::modal('user-form')->show();
    }

    /**
     * Save the name/email edit. Both columns are ordinary fillable profile
     * fields — the privilege columns are never touched from here.
     */
    public function saveUser(SecurityLogRecorder $recorder): void
    {
        abort_unless(auth()->user()?->site_admin, 404);

        $user = User::findOrFail($this->editingId);

        $validated = $this->validate($this->profileRules($user->id));

        $user->fill($validated);

        /* @chisel-email-verification */
        // A changed address is unproven until the new one is confirmed — same
        // rule the user's own profile page applies to themselves.
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }
        /* @end-chisel-email-verification */

        $user->save();

        $recorder->record(SecurityEvent::UserUpdatedByAdmin, $user, metadata: [
            'action' => 'profile_updated',
            'actor' => auth()->user()?->email,
        ]);

        Flux::modal('user-form')->close();
        Flux::toast(variant: 'success', text: __('User updated.'));
    }

    /* @chisel-email-verification */
    public function markEmailVerified(int $userId, SecurityLogRecorder $recorder): void
    {
        $user = $this->authorizedUser($userId);

        if ($user->hasVerifiedEmail()) {
            return;
        }

        // markEmailAsVerified() fires Verified, which the security log listener
        // already records against the user.
        $user->markEmailAsVerified();

        $recorder->record(SecurityEvent::UserUpdatedByAdmin, $user, metadata: [
            'action' => 'email_marked_verified',
            'actor' => auth()->user()?->email,
        ]);

        Flux::toast(variant: 'success', text: __(':name\'s email is now verified.', ['name' => $user->name]));
    }

    public function resendVerification(int $userId): void
    {
        $user = $this->authorizedUser($userId);

        if ($user->hasVerifiedEmail()) {
            return;
        }

        $user->sendEmailVerificationNotification();

        Flux::toast(variant: 'success', text: __('Verification link sent to :email.', ['email' => $user->email]));
    }
    /* @end-chisel-email-verification */

    /**
     * Send the standard password-reset link. The admin never sets, sees, or
     * bypasses the password. PasswordResetLinkSent is logged by the listener.
     */
    public function sendPasswordReset(int $userId, SecurityLogRecorder $recorder): void
    {
        $user = $this->authorizedUser($userId);

        Password::sendResetLink(['email' => $user->email]);

        $recorder->record(SecurityEvent::UserUpdatedByAdmin, $user, metadata: [
            'action' => 'password_reset_sent',
            'actor' => auth()->user()?->email,
        ]);

        Flux::toast(variant: 'success', text: __('Password reset link sent to :email.', ['email' => $user->email]));
    }

    /**
     * Clear a user's two-factor enrolment when they've lost their authenticator
     * and recovery codes. Weakens the account, so it is logged twice: Fortify's
     * TwoFactorAuthenticationDisabled event (via the security log listener) and
     * an admin-attributed row here. Passkeys are untouched.
     */
    public function resetTwoFactor(int $userId, DisableTwoFactorAuthentication $disable, SecurityLogRecorder $recorder): void
    {
        $user = $this->authorizedUser($userId);

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return;
        }

        // The admin portal requires 2FA, so clearing the last enrolled admin
        // would lock every operator out of /admin with no way back in.
        if ($user->site_admin && $this->twoFactorSiteAdminCount() <= 1) {
            Flux::toast(variant: 'warning', text: __('At least one site admin must keep two-factor authentication.'));

            return;
        }

        $disable($user);
        $user->twoFactorRememberedDevices()->delete();

        $recorder->record(SecurityEvent::UserUpdatedByAdmin, $user, metadata: [
            'action' => 'two_factor_reset',
            'actor' => auth()->user()?->email,
        ]);

        Flux::toast(variant: 'success', text: __('Two-factor authentication reset for :name.', ['name' => $user->name]));
    }

    /**
     * Lock the account out of the platform, or let it back in.
     *
     * Disabling tears down sessions, OAuth tokens and the API keys the user
     * minted; re-enabling does not bring those back — they sign in again and
     * re-mint them.
     */
    public function toggleDisabled(int $userId, AccessRevoker $revoker, SecurityLogRecorder $recorder): void
    {
        $user = $this->authorizedUser($userId);

        if ($user->isDisabled()) {
            $user->forceFill([
                'disabled_at' => null,
                'disabled_by' => null,
                'disabled_reason' => null,
            ])->save();

            $recorder->record(SecurityEvent::UserEnabled, $user, metadata: [
                'actor' => auth()->user()?->email,
            ]);

            Flux::toast(variant: 'success', text: __(':name can sign in again.', ['name' => $user->name]));

            return;
        }

        if ($user->is(auth()->user())) {
            Flux::toast(variant: 'warning', text: __('You cannot disable your own account.'));

            return;
        }

        if ($user->site_admin && $this->usableSiteAdminCount() <= 1) {
            Flux::toast(variant: 'warning', text: __('At least one site admin must stay enabled.'));

            return;
        }

        $reason = trim($this->disableReason);

        DB::transaction(function () use ($user, $reason, $revoker) {
            $user->forceFill([
                'disabled_at' => now(),
                'disabled_by' => auth()->user()?->email,
                'disabled_reason' => $reason !== '' ? $reason : null,
            ])->save();

            $revoker->revokeForAccountDisabled($user);
        });

        $this->disableReason = '';

        $recorder->record(SecurityEvent::UserDisabled, $user, metadata: [
            'actor' => auth()->user()?->email,
            'reason' => $reason !== '' ? $reason : null,
        ]);

        Flux::toast(variant: 'success', text: __(':name has been disabled.', ['name' => $user->name]));
    }

    /**
     * Re-check the site-admin guard on every mutation and resolve the target.
     *
     * The route middleware does not run on Livewire's XHR endpoint, and
     * Livewire::test() bypasses HTTP middleware entirely — this in-component
     * guard is the one that actually holds.
     */
    private function authorizedUser(int $userId): User
    {
        abort_unless(auth()->user()?->site_admin, 404);

        return User::findOrFail($userId);
    }

    /** Site admins who could actually sign in and use the portal. */
    private function usableSiteAdminCount(): int
    {
        return User::query()
            ->where('site_admin', true)
            ->whereNull('disabled_at')
            ->count();
    }

    private function twoFactorSiteAdminCount(): int
    {
        return User::query()
            ->where('site_admin', true)
            ->whereNull('disabled_at')
            ->whereNotNull('two_factor_secret')
            ->whereNotNull('two_factor_confirmed_at')
            ->count();
    }

    #[Computed]
    public function users()
    {
        return User::query()
            ->withCount('companies')
            ->when($this->statusFilter === 'active', fn ($q) => $q->whereNull('disabled_at'))
            ->when($this->statusFilter === 'disabled', fn ($q) => $q->whereNotNull('disabled_at'))
            ->when($this->search !== '', fn ($q) => $q->where(function ($q) {
                $q->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('email', 'like', '%'.$this->search.'%');
            }))
            ->orderBy('name')
            ->paginate(25);
    }
}; ?>

<x-pages::admin.layout
    :heading="__('Users')"
    :subheading="__('Every user on the platform.')"
    content-class="max-w-5xl"
>
    <div class="mb-4 flex flex-wrap items-end gap-3">
        <flux:input
            wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Search name or email…') }}"
            icon="magnifying-glass"
            class="sm:max-w-md"
            data-test="user-search"
        />

        <flux:select wire:model.live="statusFilter" class="max-w-[180px]" data-test="user-status-filter">
            <flux:select.option value="">{{ __('All') }}</flux:select.option>
            <flux:select.option value="active">{{ __('Active') }}</flux:select.option>
            <flux:select.option value="disabled">{{ __('Disabled') }}</flux:select.option>
        </flux:select>
    </div>

    <flux:input
        wire:model="disableReason"
        :label="__('Reason for disabling (optional)')"
        placeholder="{{ __('e.g. Abuse report, compromised account, customer request') }}"
        class="mb-4 sm:max-w-md"
        data-test="disable-reason"
    />

    {{-- Mobile: stacked cards --}}
    <div class="space-y-3 lg:hidden">
        @forelse ($this->users as $user)
            <div class="rounded-lg border border-border p-4 @if($user->isDisabled()) opacity-60 @endif" wire:key="user-card-{{ $user->id }}">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <div class="font-medium">{{ $user->name }}</div>
                        <div class="text-sm text-muted-foreground">{{ $user->email }}</div>
                    </div>
                    <div class="flex flex-col items-end gap-1">
                        @if ($user->isDisabled())
                            <flux:badge color="red" size="sm">{{ __('Disabled') }}</flux:badge>
                        @endif
                        @if ($user->site_admin)
                            <flux:badge color="purple" size="sm">{{ __('Site admin') }}</flux:badge>
                        @endif
                    </div>
                </div>
                <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                    <flux:badge :color="$user->hasEnabledTwoFactorAuthentication() ? 'green' : 'zinc'" size="sm">
                        {{ $user->hasEnabledTwoFactorAuthentication() ? __('2FA on') : __('2FA off') }}
                    </flux:badge>
                    <span>{{ trans_choice(':count company|:count companies', $user->companies_count, ['count' => $user->companies_count]) }}</span>
                    <span>{{ __('Joined :date', ['date' => $user->created_at?->isoFormat('ll')]) }}</span>
                </div>
                @if ($user->isDisabled())
                    <div class="mt-2 text-xs text-muted-foreground" data-test="user-disabled-note">
                        {{ __('Disabled :when by :by', [
                            'when' => $user->disabled_at?->isoFormat('ll'),
                            'by' => $user->disabled_by ?? '—',
                        ]) }}@if ($user->disabled_reason) — {{ $user->disabled_reason }}@endif
                    </div>
                @endif
                <div class="mt-3">
                    @include('pages.admin.partials.user-row-actions', ['user' => $user])
                </div>
            </div>
        @empty
            <flux:text class="block py-8 text-center text-muted-foreground">{{ __('No users found.') }}</flux:text>
        @endforelse
    </div>

    {{-- Desktop: full table --}}
    <div class="hidden overflow-x-auto rounded-lg border border-border lg:block">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left font-medium">{{ __('Name') }}</th>
                    <th class="px-4 py-2 text-left font-medium">{{ __('Email') }}</th>
                    <th class="px-4 py-2 text-left font-medium">{{ __('Status') }}</th>
                    <th class="px-4 py-2 text-left font-medium">{{ __('2FA') }}</th>
                    <th class="px-4 py-2 text-right font-medium">{{ __('Companies') }}</th>
                    <th class="px-4 py-2 text-left font-medium">{{ __('Joined') }}</th>
                    <th class="px-4 py-2 text-right font-medium"><span class="sr-only">{{ __('Actions') }}</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->users as $user)
                    <tr wire:key="user-row-{{ $user->id }}" data-test="user-row" class="@if($user->isDisabled()) opacity-60 @endif">
                        <td class="px-4 py-2 font-medium">{{ $user->name }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $user->email }}</td>
                        <td class="px-4 py-2">
                            <div class="flex flex-wrap items-center gap-1">
                                @if ($user->isDisabled())
                                    <flux:badge color="red" size="sm" data-test="user-disabled-badge">{{ __('Disabled') }}</flux:badge>
                                @else
                                    <flux:badge color="green" size="sm">{{ __('Active') }}</flux:badge>
                                @endif
                                @if ($user->site_admin)
                                    <flux:badge color="purple" size="sm">{{ __('Site admin') }}</flux:badge>
                                @endif
                            </div>
                        </td>
                        <td class="px-4 py-2">
                            <flux:badge :color="$user->hasEnabledTwoFactorAuthentication() ? 'green' : 'zinc'" size="sm">
                                {{ $user->hasEnabledTwoFactorAuthentication() ? __('On') : __('Off') }}
                            </flux:badge>
                        </td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format($user->companies_count) }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $user->created_at?->isoFormat('ll') }}</td>
                        <td class="px-4 py-2 text-right">
                            @include('pages.admin.partials.user-row-actions', ['user' => $user])
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-8 text-center text-muted-foreground">{{ __('No users found.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->users->links() }}</div>

    <flux:modal name="user-form" class="max-w-lg">
        <form wire:submit="saveUser" class="space-y-6">
            <flux:heading size="lg">{{ __('Edit user') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" required data-test="user-name" />
            <flux:input wire:model="email" type="email" :label="__('Email')" required data-test="user-email" />

            {{-- @chisel-email-verification --}}
            <flux:text class="text-sm text-muted-foreground">
                {{ __('Changing the email clears its verified status and the user will need to confirm the new address.') }}
            </flux:text>
            {{-- @end-chisel-email-verification --}}

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="user-save">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</x-pages::admin.layout>
