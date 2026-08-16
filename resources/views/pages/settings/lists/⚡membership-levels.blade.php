<?php

use App\Actions\MasterData\SaveMembershipLevel;
use App\Enums\AccountType;
use App\Enums\RecurrenceFrequency;
use App\Models\Account;
use App\Models\Company;
use App\Models\MembershipLevel;
use App\Models\PaymentTerm;
use App\Models\TaxCode;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Membership levels')] class extends Component {
    public Company $company;

    public ?int $editingId = null;

    public string $f_name = '';

    public string $f_dues = '';

    public string $f_billing_frequency = 'annual';

    public ?int $f_revenue_account_id = null;

    public ?int $f_default_terms_id = null;

    public ?int $f_default_tax_code_id = null;

    public bool $f_is_active = true;

    public function mount(Company $company): void
    {
        $this->company = $company;

        abort_unless($company->tracksMembership(), 403);
    }

    public function openCreate(): void
    {
        $this->reset(['editingId', 'f_name', 'f_dues', 'f_billing_frequency', 'f_revenue_account_id', 'f_default_terms_id', 'f_default_tax_code_id', 'f_is_active']);
        $this->f_billing_frequency = RecurrenceFrequency::Annual->value;
        $this->f_is_active = true;
        Flux::modal('membership-level-form')->show();
    }

    public function openEdit(int $id): void
    {
        $level = MembershipLevel::findOrFail($id);
        $this->editingId = $level->id;
        $this->f_name = $level->name;
        $this->f_dues = $level->default_dues_cents ? (string) Money::fromCents($level->default_dues_cents) : '';
        $this->f_billing_frequency = $level->billing_frequency->value;
        $this->f_revenue_account_id = $level->revenue_account_id;
        $this->f_default_terms_id = $level->default_terms_id;
        $this->f_default_tax_code_id = $level->default_tax_code_id;
        $this->f_is_active = $level->is_active;
        Flux::modal('membership-level-form')->show();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'f_name' => ['required', 'string', 'max:255'],
            'f_dues' => ['nullable', 'string'],
            'f_billing_frequency' => ['required', Rule::enum(RecurrenceFrequency::class)],
            'f_revenue_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('company_id', $this->company->id)],
            'f_default_terms_id' => ['nullable', 'integer', Rule::exists('payment_terms', 'id')->where('company_id', $this->company->id)],
            'f_default_tax_code_id' => ['nullable', 'integer', Rule::exists('tax_codes', 'id')->where('company_id', $this->company->id)],
            'f_is_active' => ['boolean'],
        ]);

        $editing = $this->editingId ? MembershipLevel::findOrFail($this->editingId) : null;
        abort_if($editing !== null && $editing->company_id !== $this->company->id, 403);

        app(SaveMembershipLevel::class)->handle([
            'name' => $validated['f_name'],
            'default_dues_cents' => $this->f_dues !== '' ? (Money::tryFromString($this->f_dues)?->cents ?? 0) : 0,
            'billing_frequency' => $validated['f_billing_frequency'],
            'revenue_account_id' => $validated['f_revenue_account_id'] ?: null,
            'default_terms_id' => $validated['f_default_terms_id'] ?: null,
            'default_tax_code_id' => $validated['f_default_tax_code_id'] ?: null,
            'is_active' => $validated['f_is_active'],
        ], $editing);

        Flux::modal('membership-level-form')->close();
        Flux::toast(variant: 'success', text: __('Membership level saved.'));
    }

    #[Computed]
    public function levels()
    {
        return MembershipLevel::query()->with('revenueAccount')->orderBy('name')->get();
    }

    #[Computed]
    public function incomeAccounts()
    {
        return Account::query()->where('type', AccountType::Income->value)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function paymentTerms()
    {
        return PaymentTerm::query()->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function taxCodes()
    {
        return TaxCode::query()->orderBy('name')->get(['id', 'name']);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Membership levels')" :subheading="__('Define membership tiers and their default dues. Dues are billed to members as invoices.')">
        <div class="mb-4 flex justify-end">
            <flux:button variant="primary" icon="plus" wire:click="openCreate" data-test="new-membership-level-button">{{ __('New level') }}</flux:button>
        </div>

        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-4 py-2 text-left">{{ __('Name') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Default dues') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Billing') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Revenue account') }}</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($this->levels as $level)
                        <tr data-test="membership-level-row" class="@if (! $level->is_active) opacity-50 @endif">
                            <td class="px-4 py-2">{{ $level->name }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ Money::fromCents($level->default_dues_cents, $company->currency_code) }}</td>
                            <td class="px-4 py-2">{{ $level->billing_frequency->label() }}</td>
                            <td class="px-4 py-2 text-muted-foreground">{{ $level->revenueAccount ? $level->revenueAccount->code.' — '.$level->revenueAccount->name : __('—') }}</td>
                            <td class="px-4 py-2 text-right">
                                <flux:button variant="ghost" size="sm" icon="pencil" wire:click="openEdit({{ $level->id }})" />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-6 text-center text-muted-foreground">{{ __('No membership levels yet.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-pages::settings.layout>

    <flux:modal name="membership-level-form" class="max-w-lg">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit level') : __('New level') }}</flux:heading>
            <flux:input wire:model="f_name" :label="__('Name')" required data-test="membership-level-name" />
            <div class="grid grid-cols-2 gap-4">
                <flux:input wire:model="f_dues" :label="__('Default dues')" placeholder="0.00" data-test="membership-level-dues" />
                <flux:select wire:model="f_billing_frequency" :label="__('Billing frequency')" data-test="membership-level-frequency">
                    @foreach (RecurrenceFrequency::options() as $option)
                        <flux:select.option value="{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <flux:select wire:model="f_revenue_account_id" :label="__('Revenue account')" data-test="membership-level-revenue-account">
                <flux:select.option value="">{{ __('Select an account') }}</flux:select.option>
                @foreach ($this->incomeAccounts as $account)
                    <flux:select.option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="grid grid-cols-2 gap-4">
                <flux:select wire:model="f_default_terms_id" :label="__('Default terms')">
                    <flux:select.option value="">{{ __('None') }}</flux:select.option>
                    @foreach ($this->paymentTerms as $term)
                        <flux:select.option value="{{ $term->id }}">{{ $term->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="f_default_tax_code_id" :label="__('Default tax code')">
                    <flux:select.option value="">{{ __('None') }}</flux:select.option>
                    @foreach ($this->taxCodes as $code)
                        <flux:select.option value="{{ $code->id }}">{{ $code->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <flux:switch wire:model="f_is_active" :label="__('Active')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="membership-level-save-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
