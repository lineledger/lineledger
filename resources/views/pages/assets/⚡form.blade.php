<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\AssetStatus;
use App\Models\Account;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\Company;
use App\Rules\MoneyString;
use App\Services\Assets\AssetSourcePrefiller;
use App\Services\Posting\DocumentNumberGenerator;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Asset')] class extends Component {
    public Company $company;

    public ?Asset $asset = null;

    public string $asset_no = '';

    public string $name = '';

    public string $description = '';

    public ?int $asset_category_id = null;

    public ?int $asset_account_id = null;

    public ?int $accumulated_depreciation_account_id = null;

    public ?int $depreciation_expense_account_id = null;

    public string $serial_number = '';

    public string $location = '';

    public string $acquired_date = '';

    public string $in_service_date = '';

    public string $cost = '0.00';

    public string $salvage_value = '0.00';

    public ?int $useful_life_months = null;

    public bool $auto_depreciate = false;

    public string $status = 'in-service';

    public string $disposed_at = '';

    public string $disposal_notes = '';

    public string $notes = '';

    public bool $is_active = true;

    public ?string $source_type = null;

    public ?int $source_id = null;

    public function mount(Company $company, ?Asset $asset = null): void
    {
        $this->company = $company;

        if ($asset && $asset->exists) {
            $this->asset = $asset;
            $this->asset_no = $asset->asset_no;
            $this->name = $asset->name;
            $this->description = $asset->description ?? '';
            $this->asset_category_id = $asset->asset_category_id;
            $this->asset_account_id = $asset->asset_account_id;
            $this->accumulated_depreciation_account_id = $asset->accumulated_depreciation_account_id;
            $this->depreciation_expense_account_id = $asset->depreciation_expense_account_id;
            $this->serial_number = $asset->serial_number ?? '';
            $this->location = $asset->location ?? '';
            $this->acquired_date = $asset->acquired_date->toDateString();
            $this->in_service_date = $asset->in_service_date?->toDateString() ?? '';
            $this->cost = Money::fromCents((int) $asset->cost_cents)->toDecimalString();
            $this->salvage_value = Money::fromCents((int) $asset->salvage_value_cents)->toDecimalString();
            $this->useful_life_months = $asset->useful_life_months;
            $this->auto_depreciate = (bool) $asset->auto_depreciate;
            $this->status = $asset->status->value;
            $this->disposed_at = $asset->disposed_at?->toDateString() ?? '';
            $this->disposal_notes = $asset->disposal_notes ?? '';
            $this->notes = $asset->notes ?? '';
            $this->is_active = (bool) $asset->is_active;
            $this->source_type = $asset->source_type;
            $this->source_id = $asset->source_id;

            return;
        }

        $this->asset_no = app(DocumentNumberGenerator::class)->next($company, Asset::class, 'asset_no', 'AST');
        $this->acquired_date = $this->company->currentDateTime()->toDateString();

        $sourceType = request()->query('source_type');
        $sourceId = (int) request()->query('source_id');

        if ($sourceType && $sourceId) {
            $prefilled = app(AssetSourcePrefiller::class)->resolve($sourceType, $sourceId);

            if ($prefilled) {
                $this->name = $prefilled['name'] ?? '';
                $this->description = $prefilled['description'] ?? '';
                $this->asset_account_id = $prefilled['asset_account_id'] ?? null;
                $this->acquired_date = $prefilled['acquired_date'] ?? $this->acquired_date;
                $this->cost = Money::fromCents((int) ($prefilled['cost_cents'] ?? 0))->toDecimalString();
                $this->source_type = $prefilled['source_type'];
                $this->source_id = $prefilled['source_id'];
            }
        }
    }

    public function updatedAssetCategoryId(?int $value): void
    {
        if (! $value) {
            return;
        }

        $category = AssetCategory::find($value);

        if (! $category) {
            return;
        }

        if ($category->default_asset_account_id && ! $this->asset_account_id) {
            $this->asset_account_id = $category->default_asset_account_id;
        }

        if ($category->default_accumulated_depreciation_account_id && ! $this->accumulated_depreciation_account_id) {
            $this->accumulated_depreciation_account_id = $category->default_accumulated_depreciation_account_id;
        }

        if ($category->default_depreciation_expense_account_id && ! $this->depreciation_expense_account_id) {
            $this->depreciation_expense_account_id = $category->default_depreciation_expense_account_id;
        }

        if ($category->default_useful_life_months && ! $this->useful_life_months) {
            $this->useful_life_months = $category->default_useful_life_months;
        }
    }

    public function save(): void
    {
        $companyId = $this->company->id;

        $validated = $this->validate([
            'asset_no' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'asset_category_id' => ['nullable', 'integer', Rule::exists('asset_categories', 'id')->where('company_id', $companyId)],
            'asset_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', $companyId)->where('subtype', AccountSubtype::FixedAsset->value)],
            'accumulated_depreciation_account_id' => ['nullable', Rule::requiredIf(fn () => $this->auto_depreciate), 'integer', Rule::exists('accounts', 'id')->where('company_id', $companyId)],
            'depreciation_expense_account_id' => ['nullable', Rule::requiredIf(fn () => $this->auto_depreciate), 'integer', Rule::exists('accounts', 'id')->where('company_id', $companyId)],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'acquired_date' => ['required', 'date'],
            'in_service_date' => ['nullable', Rule::requiredIf(fn () => $this->auto_depreciate), 'date'],
            'cost' => ['required', 'string', new MoneyString],
            'salvage_value' => ['required', 'string', new MoneyString],
            'useful_life_months' => ['nullable', Rule::requiredIf(fn () => $this->auto_depreciate), 'integer', 'min:1', 'max:1200'],
            'auto_depreciate' => ['boolean'],
            'status' => ['required', 'string', Rule::in(array_column(AssetStatus::cases(), 'value'))],
            'disposed_at' => ['nullable', 'date', Rule::requiredIf(fn () => in_array($this->status, ['disposed', 'sold', 'lost'], true))],
            'disposal_notes' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ], [
            'in_service_date.required' => __('Automatic depreciation needs an in-service date.'),
            'useful_life_months.required' => __('Automatic depreciation needs a useful life in months.'),
            'accumulated_depreciation_account_id.required' => __('Automatic depreciation needs an accumulated depreciation account.'),
            'depreciation_expense_account_id.required' => __('Automatic depreciation needs a depreciation expense account.'),
        ]);

        $this->asset = app(\App\Actions\Assets\SaveAsset::class)->handle([
            'asset_no' => $validated['asset_no'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?: null,
            'asset_category_id' => $validated['asset_category_id'] ?: null,
            'asset_account_id' => $validated['asset_account_id'],
            'accumulated_depreciation_account_id' => $validated['accumulated_depreciation_account_id'] ?: null,
            'depreciation_expense_account_id' => $validated['depreciation_expense_account_id'] ?: null,
            'serial_number' => $validated['serial_number'] ?: null,
            'location' => $validated['location'] ?: null,
            'acquired_date' => $validated['acquired_date'],
            'in_service_date' => $validated['in_service_date'] ?: null,
            'cost_cents' => Money::fromString($validated['cost'])->cents,
            'salvage_value_cents' => Money::fromString($validated['salvage_value'])->cents,
            'useful_life_months' => $validated['useful_life_months'],
            'auto_depreciate' => $validated['auto_depreciate'],
            'status' => $validated['status'],
            'disposed_at' => $validated['disposed_at'] ?: null,
            'disposal_notes' => $validated['disposal_notes'] ?: null,
            'notes' => $validated['notes'] ?: null,
            'is_active' => $validated['is_active'],
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
        ], $this->asset);

        Flux::toast(variant: 'success', text: __('Asset saved.'));
        $this->redirectRoute('assets.show', ['company' => $this->company->slug, 'asset' => $this->asset->id], navigate: true);
    }

    #[Computed]
    public function categoryOptions()
    {
        return AssetCategory::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
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
    <flux:heading size="xl" level="1" class="mb-6">{{ $asset?->id ? __('Edit asset') : __('New asset') }}</flux:heading>

    <form wire:submit="save" class="space-y-6">
        <div class="rounded-lg border border-border p-4" data-test="asset-section-identification">
            <flux:heading size="sm" class="mb-3">{{ __('Identification') }}</flux:heading>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <flux:input wire:model="asset_no" :label="__('Asset #')" required data-test="asset-no-input" />
                <flux:input wire:model="name" :label="__('Name')" required data-test="asset-name-input" />
                <flux:select wire:model.live="asset_category_id" :label="__('Category')" data-test="asset-category-select">
                    <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                    @foreach ($this->categoryOptions as $cat)
                        <flux:select.option :value="$cat->id">{{ $cat->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="description" :label="__('Description')" />
            </div>
        </div>

        <div class="rounded-lg border border-border p-4" data-test="asset-section-acquisition">
            <flux:heading size="sm" class="mb-3">{{ __('Acquisition') }}</flux:heading>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <flux:input type="date" wire:model="acquired_date" :label="__('Acquired date')" required data-test="asset-acquired-date" />
                <flux:input type="date" wire:model.live="in_service_date" :label="__('In-service date')" />
                <flux:input wire:model="cost" :label="__('Cost')" data-test="asset-cost-input" />
            </div>
            @if ($source_type && $source_id)
                <flux:text class="mt-3 text-muted-foreground">
                    {{ __('Created from') }}:
                    @if ($source_type === \App\Models\Bill::class)
                        <a href="{{ route('bills.show', ['company' => $company->slug, 'bill' => $source_id]) }}" wire:navigate class="underline">{{ __('Bill') }} #{{ $source_id }}</a>
                    @elseif ($source_type === \App\Models\Cheque::class)
                        <a href="{{ route('cheques.show', ['company' => $company->slug, 'cheque' => $source_id]) }}" wire:navigate class="underline">{{ $company->jurisdiction->cheque('singular') }} #{{ $source_id }}</a>
                    @elseif ($source_type === \App\Models\JournalEntry::class)
                        <a href="{{ route('journal.show', ['company' => $company->slug, 'entry' => $source_id]) }}" wire:navigate class="underline">{{ __('Journal entry') }} #{{ $source_id }}</a>
                    @else
                        <span class="font-mono text-xs">{{ $source_type }}#{{ $source_id }}</span>
                    @endif
                </flux:text>
            @endif
        </div>

        <div class="rounded-lg border border-border p-4" data-test="asset-section-gl">
            <flux:heading size="sm" class="mb-3">{{ __('GL accounts') }}</flux:heading>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <flux:select wire:model="asset_account_id" :label="__('Asset account')" required data-test="asset-account-select">
                    <flux:select.option value="">{{ __('—') }}</flux:select.option>
                    @foreach ($this->fixedAssetAccountOptions as $opt)
                        <flux:select.option :value="$opt->id">{{ $opt->code }} — {{ $opt->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="accumulated_depreciation_account_id" :label="__('Accumulated depreciation account')" data-test="asset-accum-dep-select">
                    <flux:select.option value="">{{ __('—') }}</flux:select.option>
                    @foreach ($this->fixedAssetAccountOptions as $opt)
                        <flux:select.option :value="$opt->id">{{ $opt->code }} — {{ $opt->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="depreciation_expense_account_id" :label="__('Depreciation expense account')" data-test="asset-dep-exp-select">
                    <flux:select.option value="">{{ __('—') }}</flux:select.option>
                    @foreach ($this->expenseAccountOptions as $opt)
                        <flux:select.option :value="$opt->id">{{ $opt->code }} — {{ $opt->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        <div class="rounded-lg border border-border p-4" data-test="asset-section-details">
            <flux:heading size="sm" class="mb-3">{{ __('Details') }}</flux:heading>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <flux:input wire:model="serial_number" :label="__('Serial number')" data-test="asset-serial-input" />
                <flux:input wire:model="location" :label="__('Location')" data-test="asset-location-input" />
            </div>
        </div>

        <div class="rounded-lg border border-border p-4" data-test="asset-section-depreciation">
            <flux:heading size="sm" class="mb-3">{{ __('Depreciation') }}</flux:heading>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <flux:input type="number" min="1" wire:model.live="useful_life_months" :label="__('Useful life (months)')" data-test="asset-useful-life-input" />
                <flux:input wire:model="salvage_value" :label="__('Salvage value')" data-test="asset-salvage-input" />
            </div>
            @php
                $autoDepreciationReady = $in_service_date !== ''
                    && $useful_life_months !== null
                    && $accumulated_depreciation_account_id
                    && $depreciation_expense_account_id;
            @endphp
            <div class="mt-4 space-y-2">
                <flux:switch wire:model="auto_depreciate" :label="__('Auto-generate monthly depreciation')" :disabled="! $autoDepreciationReady" data-test="asset-auto-depreciate-switch" />
                <flux:text class="text-sm text-muted-foreground">
                    {{ __('Monthly straight-line draft journal entries are generated after each month ends — full-month convention, starting the month the asset is placed in service.') }}
                    @unless ($autoDepreciationReady)
                        {{ __('To enable, set an in-service date, a useful life, and both depreciation accounts.') }}
                    @endunless
                </flux:text>
            </div>
        </div>

        <div class="rounded-lg border border-border p-4" data-test="asset-section-status">
            <flux:heading size="sm" class="mb-3">{{ __('Status') }}</flux:heading>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <flux:select wire:model.live="status" :label="__('Status')" required data-test="asset-status-select">
                    @foreach (\App\Enums\AssetStatus::cases() as $s)
                        <flux:select.option :value="$s->value">{{ $s->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                @if (in_array($status, ['disposed', 'sold', 'lost'], true))
                    <flux:input type="date" wire:model="disposed_at" :label="__('Disposal date')" required data-test="asset-disposed-at" />
                    <flux:input wire:model="disposal_notes" :label="__('Disposal notes')" data-test="asset-disposal-notes" />
                @endif
                <flux:switch wire:model="is_active" :label="__('Active')" />
            </div>
        </div>

        <flux:textarea wire:model="notes" :label="__('Notes')" rows="3" />

        <div class="flex justify-end gap-2">
            <flux:button variant="filled" :href="route('assets.index', ['company' => $company->slug])" wire:navigate type="button">{{ __('Cancel') }}</flux:button>
            <flux:button variant="primary" type="submit" data-test="asset-save-button">{{ __('Save asset') }}</flux:button>
        </div>
    </form>
</section>
