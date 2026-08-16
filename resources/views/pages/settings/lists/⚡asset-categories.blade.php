<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AssetCategory;
use App\Models\Company;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Asset categories')] class extends Component {
    public Company $company;

    public ?int $editingId = null;

    public string $f_name = '';

    public string $f_description = '';

    public ?int $f_default_asset_account_id = null;

    public ?int $f_default_accumulated_depreciation_account_id = null;

    public ?int $f_default_depreciation_expense_account_id = null;

    public ?int $f_default_useful_life_months = null;

    public ?string $f_cca_class = null;

    public bool $f_is_active = true;

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    public function openCreate(): void
    {
        $this->reset([
            'editingId', 'f_name', 'f_description',
            'f_default_asset_account_id', 'f_default_accumulated_depreciation_account_id',
            'f_default_depreciation_expense_account_id', 'f_default_useful_life_months', 'f_cca_class',
        ]);
        $this->f_is_active = true;
        Flux::modal('asset-categories-form')->show();
    }

    public function openEdit(int $id): void
    {
        $c = AssetCategory::findOrFail($id);
        $this->editingId = $c->id;
        $this->f_name = $c->name;
        $this->f_description = $c->description ?? '';
        $this->f_default_asset_account_id = $c->default_asset_account_id;
        $this->f_default_accumulated_depreciation_account_id = $c->default_accumulated_depreciation_account_id;
        $this->f_default_depreciation_expense_account_id = $c->default_depreciation_expense_account_id;
        $this->f_default_useful_life_months = $c->default_useful_life_months;
        $this->f_cca_class = $c->cca_class?->value;
        $this->f_is_active = (bool) $c->is_active;
        Flux::modal('asset-categories-form')->show();
    }

    public function save(): void
    {
        $companyId = $this->company->id;

        $validated = $this->validate([
            'f_name' => [
                'required', 'string', 'max:255',
                Rule::unique('asset_categories', 'name')
                    ->where('company_id', $companyId)
                    ->ignore($this->editingId)
                    ->whereNull('deleted_at'),
            ],
            'f_description' => ['nullable', 'string'],
            'f_default_asset_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('company_id', $companyId)->where('subtype', AccountSubtype::FixedAsset->value)],
            'f_default_accumulated_depreciation_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('company_id', $companyId)->where('subtype', AccountSubtype::FixedAsset->value)],
            'f_default_depreciation_expense_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('company_id', $companyId)->where('type', AccountType::Expense->value)],
            'f_default_useful_life_months' => ['nullable', 'integer', 'min:1', 'max:1200'],
            'f_cca_class' => ['nullable', Rule::enum(\App\Enums\CcaClass::class)],
            'f_is_active' => ['boolean'],
        ]);

        app(\App\Actions\Assets\SaveAssetCategory::class)->handle([
            'name' => $validated['f_name'],
            'description' => $validated['f_description'] ?: null,
            'default_asset_account_id' => $validated['f_default_asset_account_id'] ?: null,
            'default_accumulated_depreciation_account_id' => $validated['f_default_accumulated_depreciation_account_id'] ?: null,
            'default_depreciation_expense_account_id' => $validated['f_default_depreciation_expense_account_id'] ?: null,
            'default_useful_life_months' => $validated['f_default_useful_life_months'],
            'cca_class' => $validated['f_cca_class'] ?: null,
            'is_active' => $validated['f_is_active'],
        ], $editingCategory = $this->editingId ? AssetCategory::findOrFail($this->editingId) : null);

        abort_if($editingCategory !== null && $editingCategory->company_id !== $this->company->id, 403);

        unset($this->categories);

        Flux::modal('asset-categories-form')->close();
        Flux::toast(variant: 'success', text: __('Asset category saved.'));
    }

    #[Computed]
    public function categories()
    {
        return AssetCategory::query()
            ->with(['defaultAssetAccount'])
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function fixedAssetAccountOptions()
    {
        return Account::query()
            ->where('subtype', AccountSubtype::FixedAsset->value)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function expenseAccountOptions()
    {
        return Account::query()
            ->where('type', AccountType::Expense->value)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Asset categories')" :subheading="__('Group fixed assets and define default GL accounts that pre-fill on new asset records.')">
        <div class="mb-4 flex justify-end">
            <flux:button variant="primary" icon="plus" wire:click="openCreate" data-test="new-asset-category-button">{{ __('New category') }}</flux:button>
        </div>

        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-4 py-2 text-left">{{ __('Name') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Default asset account') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Useful life (months)') }}</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($this->categories as $c)
                        <tr data-test="asset-category-row" class="@if(! $c->is_active) opacity-50 @endif">
                            <td class="px-4 py-2">{{ $c->name }}</td>
                            <td class="px-4 py-2 text-muted-foreground">
                                @if ($c->defaultAssetAccount)
                                    {{ $c->defaultAssetAccount->code }} — {{ $c->defaultAssetAccount->name }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-2">{{ $c->default_useful_life_months ?? '—' }}</td>
                            <td class="px-4 py-2 text-right">
                                <flux:button variant="ghost" size="sm" icon="pencil" wire:click="openEdit({{ $c->id }})" data-test="edit-asset-category-button" />
                            </td>
                        </tr>
                    @endforeach
                    @if ($this->categories->isEmpty())
                        <tr><td colspan="4" class="px-4 py-6 text-center text-muted-foreground">{{ __('No asset categories yet.') }}</td></tr>
                    @endif
                </tbody>
            </table>
        </div>
    </x-pages::settings.layout>

    <flux:modal name="asset-categories-form" class="max-w-xl">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit asset category') : __('New asset category') }}</flux:heading>

            <flux:input wire:model="f_name" :label="__('Name')" required data-test="asset-category-name" />
            <flux:textarea wire:model="f_description" :label="__('Description')" rows="2" />

            <flux:select wire:model="f_default_asset_account_id" :label="__('Default asset account')" data-test="asset-category-asset-account">
                <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                @foreach ($this->fixedAssetAccountOptions as $opt)
                    <flux:select.option :value="$opt->id">{{ $opt->code }} — {{ $opt->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="f_default_accumulated_depreciation_account_id" :label="__('Default accumulated depreciation account')">
                <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                @foreach ($this->fixedAssetAccountOptions as $opt)
                    <flux:select.option :value="$opt->id">{{ $opt->code }} — {{ $opt->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="f_default_depreciation_expense_account_id" :label="__('Default depreciation expense account')">
                <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                @foreach ($this->expenseAccountOptions as $opt)
                    <flux:select.option :value="$opt->id">{{ $opt->code }} — {{ $opt->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input type="number" min="1" wire:model="f_default_useful_life_months" :label="__('Default useful life (months)')" data-test="asset-category-useful-life" />

            @if ($company->supports(\App\Enums\JurisdictionCapability::CanadianCapitalCostAllowance))
                <flux:select wire:model="f_cca_class" :label="__('CCA class')" :description="__('Capital cost allowance class for the T2125 / CCA schedule.')" data-test="asset-category-cca-class">
                    <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                    @foreach (\App\Enums\CcaClass::options() as $opt)
                        <flux:select.option :value="$opt['value']">{{ $opt['label'] }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:switch wire:model="f_is_active" :label="__('Active')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="asset-category-save-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
