<?php

use App\Enums\AccountSubtype;
use App\Enums\CostingMethod;
use App\Models\Account;
use App\Models\Company;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Inventory settings')] class extends Component {
    public Company $company;

    public string $f_costing_method = 'weighted_average';

    public ?int $f_default_inventory_asset_account_id = null;

    public ?int $f_default_cogs_account_id = null;

    public function mount(Company $company): void
    {
        $this->company = $company;
        $this->f_costing_method = $company->costing_method?->value ?? 'weighted_average';
        $this->f_default_inventory_asset_account_id = $company->default_inventory_asset_account_id;
        $this->f_default_cogs_account_id = $company->default_cogs_account_id;
    }

    public function save(): void
    {
        $companyId = $this->company->id;

        $rules = [
            'f_default_inventory_asset_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('company_id', $companyId)],
            'f_default_cogs_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('company_id', $companyId)],
        ];

        if ($this->company->canChangeCostingMethod()) {
            $rules['f_costing_method'] = ['required', Rule::enum(CostingMethod::class)];
        }

        $validated = $this->validate($rules);

        $payload = [
            'default_inventory_asset_account_id' => $validated['f_default_inventory_asset_account_id'] ?: null,
            'default_cogs_account_id' => $validated['f_default_cogs_account_id'] ?: null,
        ];

        if ($this->company->canChangeCostingMethod()) {
            $payload['costing_method'] = $validated['f_costing_method'];
        }

        $this->company->update($payload);

        Flux::toast(variant: 'success', text: __('Inventory settings saved.'));
    }

    #[Computed]
    public function inventoryAssetAccounts()
    {
        return Account::query()
            ->where(function ($q) {
                $q->where(fn ($inner) => $inner->where('subtype', AccountSubtype::Inventory->value)->where('is_active', true));

                if ($this->f_default_inventory_asset_account_id) {
                    $q->orWhere('id', $this->f_default_inventory_asset_account_id);
                }
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function cogsAccounts()
    {
        return Account::query()
            ->where(function ($q) {
                $q->where(fn ($inner) => $inner
                    ->whereIn('subtype', [AccountSubtype::CostOfGoodsSold->value, AccountSubtype::Expense->value])
                    ->where('is_active', true));

                if ($this->f_default_cogs_account_id) {
                    $q->orWhere('id', $this->f_default_cogs_account_id);
                }
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function isLocked(): bool
    {
        return ! $this->company->canChangeCostingMethod();
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Inventory')" :subheading="__('Costing method and default inventory accounts.')">
        <form wire:submit="save" class="max-w-2xl space-y-6">
            <div>
                <flux:select wire:model="f_costing_method" :label="__('Costing method')" :disabled="$this->isLocked">
                    @foreach (CostingMethod::cases() as $m)
                        <flux:select.option :value="$m->value">{{ $m->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                @if ($this->isLocked)
                    <flux:text class="mt-1 text-xs text-amber-600 dark:text-amber-400">
                        {{ __('Cannot change costing method after inventory movements exist.') }}
                    </flux:text>
                @else
                    <flux:text class="mt-1 text-xs text-muted-foreground">
                        {{ __('Weighted Average keeps a running unit cost; FIFO tracks discrete layers. Locked once any stock movement exists.') }}
                    </flux:text>
                @endif
            </div>

            <flux:select wire:model="f_default_inventory_asset_account_id" :label="__('Default inventory asset account')">
                <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                @foreach ($this->inventoryAssetAccounts as $a)
                    <flux:select.option :value="$a->id">{{ $a->code }} — {{ $a->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="f_default_cogs_account_id" :label="__('Default COGS account')">
                <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                @foreach ($this->cogsAccounts as $a)
                    <flux:select.option :value="$a->id">{{ $a->code }} — {{ $a->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
        </form>
    </x-pages::settings.layout>
</section>
