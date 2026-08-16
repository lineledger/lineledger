<?php

use App\Actions\Portal\SetOwnPortalPassword;
use App\Actions\Portal\UpdateOwnEmployeeInfo;
use App\Models\Company;
use App\Models\Contact;
use Flux\Flux;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.employee-portal')] #[Title('Edit my info')] class extends Component
{
    public Company $company;

    public Contact $employee;

    // Account & security (portal password)
    public bool $hasPassword = false;
    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    // Address (Contact)
    public string $billing_line1 = '';
    public string $billing_line2 = '';
    public string $billing_city = '';
    public string $billing_region = '';
    public string $billing_postal_code = '';
    public string $billing_country = '';

    // TD1 (payroll profile) — presented in dollars.
    public string $td1_federal_claim = '';
    public string $td1_federal_code = '';
    public string $td1_provincial_claim = '';
    public string $td1_provincial_code = '';

    public function mount(Company $company): void
    {
        $this->company = $company;
        $this->employee = auth('customer')->user();
        $this->hasPassword = $this->employee->portal_password !== null;

        $this->billing_line1 = (string) ($this->employee->billing_line1 ?? '');
        $this->billing_line2 = (string) ($this->employee->billing_line2 ?? '');
        $this->billing_city = (string) ($this->employee->billing_city ?? '');
        $this->billing_region = (string) ($this->employee->billing_region ?? '');
        $this->billing_postal_code = (string) ($this->employee->billing_postal_code ?? '');
        $this->billing_country = (string) ($this->employee->billing_country ?? 'CA');

        $profile = $this->employee->payrollProfile;
        if ($profile !== null) {
            $this->td1_federal_claim = number_format((int) $profile->td1_federal_claim_cents / 100, 2, '.', '');
            $this->td1_federal_code = (string) ($profile->td1_federal_code ?? '');
            $this->td1_provincial_claim = number_format((int) $profile->td1_provincial_claim_cents / 100, 2, '.', '');
            $this->td1_provincial_code = (string) ($profile->td1_provincial_code ?? '');
        }
    }

    public function save(UpdateOwnEmployeeInfo $action): void
    {
        $validated = $this->validate([
            'billing_line1' => ['nullable', 'string', 'max:255'],
            'billing_line2' => ['nullable', 'string', 'max:255'],
            'billing_city' => ['nullable', 'string', 'max:120'],
            'billing_region' => ['nullable', 'string', 'max:120'],
            'billing_postal_code' => ['nullable', 'string', 'max:20'],
            'billing_country' => ['nullable', 'string', 'max:2'],
            'td1_federal_claim' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'td1_federal_code' => ['nullable', 'string', 'max:10'],
            'td1_provincial_claim' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'td1_provincial_code' => ['nullable', 'string', 'max:10'],
        ]);

        $action->handle($this->employee, [
            'billing_line1' => $validated['billing_line1'] ?? null,
            'billing_line2' => $validated['billing_line2'] ?? null,
            'billing_city' => $validated['billing_city'] ?? null,
            'billing_region' => $validated['billing_region'] ?? null,
            'billing_postal_code' => $validated['billing_postal_code'] ?? null,
            'billing_country' => $validated['billing_country'] ?? null,
            'td1_federal_claim_cents' => (int) round((float) $validated['td1_federal_claim'] * 100),
            'td1_federal_code' => $validated['td1_federal_code'] ?? null,
            'td1_provincial_claim_cents' => (int) round((float) $validated['td1_provincial_claim'] * 100),
            'td1_provincial_code' => $validated['td1_provincial_code'] ?? null,
        ]);

        Flux::toast(__('Your info has been updated.'), variant: 'success');

        $this->redirectRoute('employee-portal.dashboard', ['company' => $this->company->slug], navigate: true);
    }

    public function savePassword(SetOwnPortalPassword $action): void
    {
        // The action validates (current password match, strength, confirmation)
        // and throws ValidationException keyed to these property names, so
        // errors render inline on the fields below.
        $action->handle($this->employee, [
            'current_password' => $this->current_password,
            'password' => $this->password,
            'password_confirmation' => $this->password_confirmation,
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');
        $this->hasPassword = true;
        $this->employee->refresh();

        Flux::toast(__('Your password has been saved.'), variant: 'success');
    }
}; ?>

<div class="mx-auto flex max-w-2xl flex-col gap-6">
    <div class="flex items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Edit my info') }}</flux:heading>
        <flux:button size="sm" variant="ghost" :href="route('employee-portal.dashboard', ['company' => $company->slug])" wire:navigate>
            {{ __('Back') }}
        </flux:button>
    </div>

    <form wire:submit="save" class="flex flex-col gap-6">
        <flux:card class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Mailing address') }}</flux:heading>

            <flux:input wire:model="billing_line1" :label="__('Address line 1')" data-test="info-line1" />
            <flux:input wire:model="billing_line2" :label="__('Address line 2')" />

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="billing_city" :label="__('City')" />
                <flux:input wire:model="billing_region" :label="__('Province / region')" />
                <flux:input wire:model="billing_postal_code" :label="__('Postal code')" />
                <flux:input wire:model="billing_country" :label="__('Country')" maxlength="2" />
            </div>
        </flux:card>

        <flux:card class="flex flex-col gap-4">
            <div>
                <flux:heading size="lg">{{ __('Tax credits (TD1)') }}</flux:heading>
                <flux:subheading>{{ __('Your total claim amounts. Changes apply to future pay runs.') }}</flux:subheading>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="td1_federal_claim" type="number" step="0.01" :label="__('Federal claim amount')" data-test="info-td1-federal" />
                <flux:input wire:model="td1_federal_code" :label="__('Federal claim code')" />
                <flux:input wire:model="td1_provincial_claim" type="number" step="0.01" :label="__('Provincial claim amount')" />
                <flux:input wire:model="td1_provincial_code" :label="__('Provincial claim code')" />
            </div>
        </flux:card>

        <div class="flex justify-end">
            <flux:button variant="primary" type="submit" data-test="info-save">{{ __('Save changes') }}</flux:button>
        </div>
    </form>

    <form wire:submit="savePassword" class="flex flex-col gap-6">
        <flux:card class="flex flex-col gap-4">
            <div>
                <flux:heading size="lg">{{ __('Account & security') }}</flux:heading>
                @if ($hasPassword)
                    <flux:subheading>{{ __('Change the password you use to sign in to this portal.') }}</flux:subheading>
                @else
                    <flux:subheading>{{ __('Set a password so you can sign in directly with your email — no need to wait for an emailed link.') }}</flux:subheading>
                @endif
            </div>

            @if ($hasPassword)
                <flux:input
                    wire:model="current_password"
                    type="password"
                    :label="__('Current password')"
                    autocomplete="current-password"
                    viewable
                    data-test="info-current-password"
                />
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input
                    wire:model="password"
                    type="password"
                    :label="__('New password')"
                    autocomplete="new-password"
                    viewable
                    data-test="info-new-password"
                />
                <flux:input
                    wire:model="password_confirmation"
                    type="password"
                    :label="__('Confirm new password')"
                    autocomplete="new-password"
                    viewable
                    data-test="info-new-password-confirmation"
                />
            </div>

            <div class="flex justify-end">
                <flux:button variant="primary" type="submit" data-test="info-save-password">
                    {{ $hasPassword ? __('Change password') : __('Set password') }}
                </flux:button>
            </div>
        </flux:card>
    </form>
</div>
