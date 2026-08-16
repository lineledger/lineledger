<?php

use App\Actions\MasterData\SaveTaxAgency;
use App\Actions\MasterData\SaveTaxCode;
use App\Enums\AccountSubtype;
use App\Enums\TaxAppliesTo;
use App\Models\Account;
use App\Models\Company;
use App\Models\TaxAgency;
use App\Models\TaxCode;
use App\Support\Tax\TaxAuthorityCatalog;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Tax codes')] class extends Component {
    public Company $company;

    public ?int $editingId = null;

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    public string $f_code = '';

    public string $f_name = '';

    public string $f_rate = '';

    public ?int $f_agency_id = null;

    public string $f_applies_to = 'both';

    public bool $f_is_recoverable = true;

    public bool $f_is_active = true;

    public ?int $editingAgencyId = null;

    public string $a_name = '';

    public string $a_registration_number = '';

    public ?int $a_payable_account_id = null;

    // Which catalog authority the user picked, or '' for a custom one. Drives the
    // name/account-name prefill in updatedACatalogKey().
    public string $a_catalog_key = '';

    // Suggested name for the auto-created payable account, set from the catalog.
    public string $a_account_name = '';

    public function openCreate(): void
    {
        $this->resetForm();
        Flux::modal('tax-code-form')->show();
    }

    public function openEdit(int $id): void
    {
        $t = TaxCode::findOrFail($id);

        $this->editingId = $t->id;
        $this->f_code = $t->code;
        $this->f_name = $t->name;
        // Show the rate as a percentage, keeping up to 3 decimals (e.g. QST 9.975)
        // but trimming trailing zeros so 13% reads "13", not "13.000".
        $this->f_rate = rtrim(rtrim(number_format($t->rate_basis_points / 100, 3), '0'), '.');
        $this->f_agency_id = $t->agency_id;
        $this->f_applies_to = $t->applies_to->value;
        $this->f_is_recoverable = $t->is_recoverable;
        $this->f_is_active = $t->is_active;

        Flux::modal('tax-code-form')->show();
    }

    public function save(): void
    {
        $companyId = $this->company->id;

        $validated = $this->validate([
            'f_code' => [
                'required', 'string', 'max:20',
                Rule::unique('tax_codes', 'code')->where('company_id', $companyId)->ignore($this->editingId),
            ],
            'f_name' => ['required', 'string', 'max:255'],
            'f_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'f_agency_id' => ['nullable', 'integer', Rule::exists('tax_agencies', 'id')->where('company_id', $companyId)],
            'f_applies_to' => ['required', Rule::enum(TaxAppliesTo::class)],
            'f_is_recoverable' => ['boolean'],
            'f_is_active' => ['boolean'],
        ]);

        $editing = $this->editingId ? TaxCode::findOrFail($this->editingId) : null;
        abort_if($editing !== null && $editing->company_id !== $this->company->id, 403);

        app(SaveTaxCode::class)->handle([
            'code' => $validated['f_code'],
            'name' => $validated['f_name'],
            'rate_basis_points' => round(((float) $validated['f_rate']) * 100, 3),
            'agency_id' => $validated['f_agency_id'] ?: null,
            'applies_to' => $validated['f_applies_to'],
            'is_recoverable' => $validated['f_is_recoverable'],
            'is_active' => $validated['f_is_active'],
        ], $editing);

        Flux::modal('tax-code-form')->close();
        $this->resetForm();
        Flux::toast(variant: 'success', text: __('Tax code saved.'));
    }

    public function openAgencyCreate(): void
    {
        $this->resetAgencyForm();
        Flux::modal('tax-agency-form')->show();
    }

    public function openAgencyEdit(int $id): void
    {
        $a = TaxAgency::findOrFail($id);

        $this->editingAgencyId = $a->id;
        $this->a_name = $a->name;
        $this->a_registration_number = (string) ($a->registration_number ?? '');
        $this->a_payable_account_id = $a->payable_account_id;
        $this->a_catalog_key = '';
        $this->a_account_name = '';

        Flux::modal('tax-agency-form')->show();
    }

    /**
     * Prefill the agency name (and the suggested payable-account name) when the
     * user picks a known authority from the catalog; leave them blank for a
     * custom authority so the name field is theirs to fill.
     */
    public function updatedACatalogKey(): void
    {
        $entry = collect($this->authorityCatalog)->firstWhere('key', $this->a_catalog_key);

        $this->a_name = $entry['name'] ?? '';
        $this->a_account_name = $entry['account_name'] ?? '';
    }

    public function saveAgency(): void
    {
        $companyId = $this->company->id;

        $validated = $this->validate([
            'a_name' => ['required', 'string', 'max:255'],
            'a_registration_number' => ['nullable', 'string', 'max:50'],
            // Optional: a blank payable account auto-creates one (see SaveTaxAgency).
            'a_payable_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('company_id', $companyId)],
            'a_account_name' => ['nullable', 'string', 'max:255'],
            'a_catalog_key' => ['nullable', 'string', 'max:50'],
        ]);

        $editing = $this->editingAgencyId ? TaxAgency::findOrFail($this->editingAgencyId) : null;
        abort_if($editing !== null && $editing->company_id !== $this->company->id, 403);

        app(SaveTaxAgency::class)->handle([
            'name' => $validated['a_name'],
            'registration_number' => $validated['a_registration_number'],
            'payable_account_id' => $validated['a_payable_account_id'] ?: null,
            'payable_account_name' => $validated['a_account_name'] ?: null,
            'is_active' => true,
        ], $editing);

        Flux::modal('tax-agency-form')->close();
        $this->resetAgencyForm();
        Flux::toast(variant: 'success', text: __('Agency saved.'));
    }

    #[Computed]
    public function taxCodes()
    {
        return TaxCode::query()->with('agency')->orderBy('code')->get();
    }

    #[Computed]
    public function agencies()
    {
        return TaxAgency::query()->with('payableAccount')->orderBy('name')->get();
    }

    /**
     * Known tax authorities for this jurisdiction not already set up, offered in
     * the New agency picker. Filtering by name keeps the seeded authorities (CRA,
     * the home province) from showing as duplicates you can't re-add.
     *
     * @return list<array{key: string, name: string, account_name: string}>
     */
    #[Computed]
    public function authorityCatalog(): array
    {
        $existing = $this->agencies->map(fn (TaxAgency $a) => mb_strtolower(trim($a->name)))->all();

        return array_values(array_filter(
            TaxAuthorityCatalog::forCompany($this->company),
            fn (array $entry): bool => ! in_array(mb_strtolower($entry['name']), $existing, true),
        ));
    }

    #[Computed]
    public function payableAccountOptions()
    {
        // Active tax-payable accounts, plus the one already assigned to the agency
        // being edited so a since-deactivated account is never dropped.
        return Account::query()
            ->where(function ($q) {
                $q->where(fn ($inner) => $inner->where('subtype', AccountSubtype::TaxPayable->value)->where('is_active', true));

                if ($this->a_payable_account_id) {
                    $q->orWhere('id', $this->a_payable_account_id);
                }
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'f_code', 'f_name', 'f_rate', 'f_agency_id']);
        $this->f_applies_to = 'both';
        $this->f_is_recoverable = true;
        $this->f_is_active = true;
    }

    private function resetAgencyForm(): void
    {
        $this->reset(['editingAgencyId', 'a_name', 'a_registration_number', 'a_payable_account_id', 'a_catalog_key', 'a_account_name']);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Tax codes')" :subheading="__('Sales tax codes applied to invoice and bill lines.')">
        <div class="space-y-8">
            <div>
                <div class="mb-4 flex items-center justify-between">
                    <flux:heading>{{ __('Tax codes') }}</flux:heading>
                    <flux:button variant="primary" icon="plus" size="sm" wire:click="openCreate" data-test="new-tax-code-button">{{ __('New code') }}</flux:button>
                </div>

                <div class="overflow-x-auto rounded-lg border border-border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted">
                            <tr>
                                <th class="px-4 py-2 text-left">{{ __('Code') }}</th>
                                <th class="px-4 py-2 text-left">{{ __('Name') }}</th>
                                <th class="px-4 py-2 text-right">{{ __('Rate') }}</th>
                                <th class="px-4 py-2 text-left">{{ __('Agency') }}</th>
                                <th class="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($this->taxCodes as $t)
                                <tr data-test="tax-code-row" class="@if(! $t->is_active) opacity-50 @endif">
                                    <td class="px-4 py-2 font-mono">{{ $t->code }}</td>
                                    <td class="px-4 py-2">{{ $t->name }}</td>
                                    <td class="px-4 py-2 text-right font-mono">{{ rtrim(rtrim(number_format($t->rate_basis_points / 100, 3), '0'), '.') }}%</td>
                                    <td class="px-4 py-2 text-muted-foreground">{{ optional($t->agency)->name }}</td>
                                    <td class="px-4 py-2 text-right">
                                        <flux:button variant="ghost" size="sm" icon="pencil" wire:click="openEdit({{ $t->id }})" />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div>
                <div class="mb-4 flex items-center justify-between">
                    <flux:heading>{{ __('Tax agencies') }}</flux:heading>
                    <flux:button variant="filled" icon="plus" size="sm" wire:click="openAgencyCreate" data-test="new-agency-button">{{ __('New agency') }}</flux:button>
                </div>

                <div class="overflow-x-auto rounded-lg border border-border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted">
                            <tr>
                                <th class="px-4 py-2 text-left">{{ __('Name') }}</th>
                                <th class="px-4 py-2 text-left">{{ __('Registration #') }}</th>
                                <th class="px-4 py-2 text-left">{{ __('Payable account') }}</th>
                                <th class="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($this->agencies as $a)
                                <tr data-test="agency-row">
                                    <td class="px-4 py-2">{{ $a->name }}</td>
                                    <td class="px-4 py-2 font-mono text-muted-foreground">{{ $a->registration_number ?: '—' }}</td>
                                    <td class="px-4 py-2 text-muted-foreground">{{ optional($a->payableAccount)->code }} — {{ optional($a->payableAccount)->name }}</td>
                                    <td class="px-4 py-2 text-right">
                                        <flux:button variant="ghost" size="sm" icon="pencil" wire:click="openAgencyEdit({{ $a->id }})" />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </x-pages::settings.layout>

    <flux:modal name="tax-code-form" class="max-w-lg">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit tax code') : __('New tax code') }}</flux:heading>
            <x-api-id-hint :id="$editingId" field="tax_code_id" />

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <flux:input wire:model="f_code" :label="__('Code')" required maxlength="20" data-test="tax-code-code" />
                <flux:input wire:model="f_rate" :label="__('Rate (%)')" required data-test="tax-code-rate" />
            </div>

            <flux:input wire:model="f_name" :label="__('Name')" required data-test="tax-code-name" />

            <div>
                <flux:select wire:model="f_agency_id" :label="__('Agency')">
                    <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                    @foreach ($this->agencies as $a)
                        <flux:select.option :value="$a->id">{{ $a->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:button variant="ghost" size="xs" icon="plus" class="mt-1.5" wire:click="openAgencyCreate" data-test="new-authority-inline">{{ __('New authority') }}</flux:button>
            </div>

            <flux:select wire:model="f_applies_to" :label="__('Applies to')">
                <flux:select.option value="both">{{ __('Sales & purchases') }}</flux:select.option>
                <flux:select.option value="sale_only">{{ __('Sales only') }}</flux:select.option>
                <flux:select.option value="purchase_only">{{ __('Purchases only') }}</flux:select.option>
            </flux:select>

            <flux:callout icon="information-circle" variant="secondary">
                <flux:callout.text>
                    @if ($company->jurisdiction === \App\Enums\Country::UnitedStates)
                        {{ __(':label is usually destination-based and not recoverable — leave "Recoverable (input tax credit)" off unless you know otherwise.', ['label' => $company->jurisdiction->taxLabel()]) }}
                    @else
                        {{ __('For :label, enter the combined rate and mark it recoverable if you claim input tax credits.', ['label' => $company->jurisdiction->taxLabel()]) }}
                    @endif
                </flux:callout.text>
            </flux:callout>

            <flux:switch wire:model="f_is_recoverable" :label="__('Recoverable (input tax credit)')" />
            <flux:switch wire:model="f_is_active" :label="__('Active')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="tax-code-save-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="tax-agency-form" class="max-w-lg">
        <form wire:submit="saveAgency" class="space-y-6">
            <flux:heading size="lg">{{ $editingAgencyId ? __('Edit agency') : __('New agency') }}</flux:heading>
            {{-- Agencies are their own API resource (/api/v1/tax-agencies), so the
                 id a tax code references as `agency_id` belongs here too. --}}
            <x-api-id-hint :id="$editingAgencyId" field="agency_id" />

            @unless ($editingAgencyId)
                <flux:select
                    wire:model.live="a_catalog_key"
                    :label="__('Tax authority')"
                    :description="__('Pick a known authority to fill in the details, or choose Custom to enter your own.')"
                    data-test="agency-catalog"
                >
                    <flux:select.option value="">{{ __('Custom authority…') }}</flux:select.option>
                    @foreach ($this->authorityCatalog as $entry)
                        <flux:select.option :value="$entry['key']">{{ $entry['name'] }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endunless

            <flux:input wire:model="a_name" :label="__('Name')" required data-test="agency-name" />

            <flux:input
                wire:model="a_registration_number"
                :label="__('Registration number')"
                :description="__('Tax registration number. Shown on invoices.')"
                maxlength="50"
                data-test="agency-registration-number"
            />

            <flux:select wire:model.live="a_payable_account_id" :label="__('Payable account')" data-test="agency-payable-account">
                <flux:select.option value="">{{ $editingAgencyId ? __('— Keep current account —') : __('Create a new account automatically') }}</flux:select.option>
                @foreach ($this->payableAccountOptions as $opt)
                    <flux:select.option :value="$opt->id">{{ $opt->code }} — {{ $opt->name }}</flux:select.option>
                @endforeach
            </flux:select>

            @if (! $editingAgencyId && ! $a_payable_account_id)
                <flux:text class="-mt-4 text-sm text-muted-foreground" data-test="agency-account-hint">
                    {{ __('A Tax Payable account will be created automatically to hold tax collected for this authority.') }}
                </flux:text>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="agency-save-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
