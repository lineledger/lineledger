{{-- The confirm dialog a HandlesPeriodLockOverride form opens when an action
     hits the company lock date. Warn mode asks for a confirmation; Block mode
     (shown only to Owners and Admins) asks for their password.
     <x-period-lock-override :company="$company" :message="$periodLockMessage" /> --}}
@props(['company', 'message' => ''])

@php($block = $company->lockMode() === \App\Enums\PeriodLockMode::Block)

<flux:modal name="period-lock-override" class="max-w-md" data-test="period-lock-override-modal">
    <form wire:submit="confirmPeriodLockOverride" class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('This date is in a closed period') }}</flux:heading>
            <flux:text class="mt-2" data-test="period-lock-override-message">{{ $message }}</flux:text>
            <flux:text class="mt-2">
                {{ $block
                    ? __('The books are closed through :date. As an Owner or Admin you can override the lock for this one transaction by entering your password. The override is recorded in the audit log.', ['date' => $company->lock_date?->toFormattedDateString()])
                    : __('The books are closed through :date. Continuing changes figures that may already have been reported. Your confirmation is recorded in the audit log.', ['date' => $company->lock_date?->toFormattedDateString()]) }}
            </flux:text>
        </div>

        @if ($block)
            <flux:input
                type="password"
                wire:model="periodLockPassword"
                :label="__('Your account password')"
                autocomplete="current-password"
                data-test="period-lock-override-password"
            />
        @endif

        <div class="flex justify-end gap-2">
            <flux:modal.close><flux:button variant="filled" type="button">{{ __('Cancel') }}</flux:button></flux:modal.close>
            <flux:button variant="primary" type="submit" data-test="period-lock-override-confirm">
                {{ $block ? __('Override and continue') : __('Continue anyway') }}
            </flux:button>
        </div>
    </form>
</flux:modal>
