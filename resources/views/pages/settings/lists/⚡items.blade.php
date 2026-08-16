<?php

use App\Actions\MasterData\SaveItem;
use App\Enums\AccountSubtype;
use App\Enums\ItemType;
use App\Livewire\Concerns\ImportsCsvList;
use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\TaxCode;
use App\Rules\MoneyString;
use App\Services\Migration\Importers\CompanyCsvImporter;
use App\Services\Migration\Importers\ItemsImporter;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\WithFileUploads;

new #[Title('Items')] class extends Component {
    use WithPagination;
    use ImportsCsvList;
    use WithFileUploads;

    public Company $company;

    /** Matches name or SKU. Mirrors the Chart of Accounts search (?q=…). */
    #[Url(as: 'q')]
    public string $search = '';

    /** Inactive items are hidden by default, as on the Chart of Accounts. */
    public bool $showInactive = false;

    public ?int $editingId = null;

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    // Filtering shrinks the result set, so a stale page number would land the
    // user on an empty page instead of the first match.
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedShowInactive(): void
    {
        $this->resetPage();
    }

    protected function csvImporter(): CompanyCsvImporter
    {
        return app(ItemsImporter::class);
    }

    protected function csvImportModalName(): string
    {
        return 'item-import';
    }

    protected function csvImportLabelPlural(): string
    {
        return __('items');
    }

    public string $f_name = '';

    public string $f_sku = '';

    public string $f_description = '';

    public string $f_type = 'service';

    public ?int $f_item_category_id = null;

    public ?int $f_income_account_id = null;

    public ?int $f_expense_account_id = null;

    /**
     * Default sales taxes (up to two, e.g. GST + PST). The first is stored as the
     * item's primary default tax, the second as its secondary; both prefill a line
     * when the item is picked on a document.
     *
     * @var array<int, int|string>
     */
    public array $f_default_tax_code_ids = [];

    public string $f_price = '';

    public bool $f_is_active = true;

    public ?int $f_inventory_asset_account_id = null;

    public ?int $f_cogs_account_id = null;

    public string $f_reorder_point = '';

    public string $f_opening_qty = '';

    public string $f_opening_cost = '';

    /**
     * Bundle component rows: { component_item_id: ?int, quantity: string }
     *
     * @var array<int, array{component_item_id: ?int, quantity: string}>
     */
    public array $f_components = [];

    public function openCreate(): void
    {
        $this->resetForm();
        $this->f_inventory_asset_account_id = $this->company->default_inventory_asset_account_id;
        $this->f_cogs_account_id = $this->company->default_cogs_account_id;
        Flux::modal('item-form')->show();
    }

    public function openEdit(int $id): void
    {
        $i = Item::with('components')->findOrFail($id);

        $this->editingId = $i->id;
        $this->f_name = $i->name;
        $this->f_sku = $i->sku ?? '';
        $this->f_description = $i->description ?? '';
        $this->f_type = $i->type->value;
        $this->f_item_category_id = $i->item_category_id;
        $this->f_income_account_id = $i->income_account_id;
        $this->f_expense_account_id = $i->expense_account_id;
        $this->f_default_tax_code_ids = array_values(array_filter([$i->default_tax_code_id, $i->default_secondary_tax_code_id]));
        $this->f_price = Money::fromCents((int) $i->default_price_cents)->toDecimalString();
        $this->f_is_active = $i->is_active;
        $this->f_inventory_asset_account_id = $i->inventory_asset_account_id ?? $this->company->default_inventory_asset_account_id;
        $this->f_cogs_account_id = $i->cogs_account_id ?? $this->company->default_cogs_account_id;
        $this->f_reorder_point = $i->reorder_point !== null ? (string) (float) $i->reorder_point : '';
        $this->f_opening_qty = '';
        $this->f_opening_cost = '';
        $this->f_components = $i->components->map(fn ($c) => [
            'component_item_id' => $c->component_item_id,
            'quantity' => rtrim(rtrim((string) $c->quantity, '0'), '.') ?: '1',
        ])->all();

        Flux::modal('item-form')->show();
    }

    public function addComponent(): void
    {
        $this->f_components[] = ['component_item_id' => null, 'quantity' => '1'];
    }

    public function removeComponent(int $i): void
    {
        unset($this->f_components[$i]);
        $this->f_components = array_values($this->f_components);
    }

    public function save(): void
    {
        $companyId = $this->company->id;

        $rules = [
            'f_name' => ['required', 'string', 'max:255'],
            'f_sku' => ['nullable', 'string', 'max:100'],
            'f_description' => ['nullable', 'string'],
            'f_type' => ['required', Rule::enum(ItemType::class)],
            'f_item_category_id' => ['nullable', 'integer', Rule::exists('item_categories', 'id')->where('company_id', $companyId)],
            'f_income_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', $companyId)],
            'f_expense_account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('company_id', $companyId)],
            'f_default_tax_code_ids' => ['array', 'max:2'],
            'f_default_tax_code_ids.*' => ['integer', Rule::exists('tax_codes', 'id')->where('company_id', $companyId)],
            'f_price' => ['nullable', 'string', new MoneyString],
            'f_is_active' => ['boolean'],
            'f_reorder_point' => ['nullable', 'numeric', 'min:0'],
        ];

        if ($this->f_type === ItemType::Inventory->value) {
            $rules['f_inventory_asset_account_id'] = ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', $companyId)];
            $rules['f_cogs_account_id'] = ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', $companyId)];
            $rules['f_opening_qty'] = ['nullable', 'numeric', 'min:0'];
            $rules['f_opening_cost'] = ['nullable', 'string', new MoneyString];
        }

        if ($this->f_type === ItemType::Bundle->value) {
            $rules['f_components'] = ['array', 'min:1'];
            $rules['f_components.*.component_item_id'] = ['required', 'integer', Rule::exists('items', 'id')->where('company_id', $companyId)];
            $rules['f_components.*.quantity'] = ['required', 'numeric', 'min:0'];
        }

        $validated = $this->validate($rules);

        $tracksInventory = $this->f_type === ItemType::Inventory->value;

        // The tax picker is a multi-select; the first pick is the primary default
        // tax, the second the secondary (e.g. GST then PST). Both prefill a line.
        $taxCodeIds = array_values(array_filter(array_map('intval', $validated['f_default_tax_code_ids'] ?? [])));

        $existingItem = $this->editingId ? Item::findOrFail($this->editingId) : null;
        abort_if($existingItem !== null && $existingItem->company_id !== $this->company->id, 403);

        app(SaveItem::class)->handle([
            'name' => $validated['f_name'],
            'sku' => $validated['f_sku'] ?: null,
            'description' => $validated['f_description'] ?: null,
            'type' => $validated['f_type'],
            'item_category_id' => $validated['f_item_category_id'] ?: null,
            'income_account_id' => $validated['f_income_account_id'],
            'expense_account_id' => $validated['f_expense_account_id'] ?: null,
            'default_tax_code_id' => $taxCodeIds[0] ?? null,
            'default_secondary_tax_code_id' => $taxCodeIds[1] ?? null,
            'default_price_cents' => $validated['f_price'] !== '' ? Money::fromString($validated['f_price'])->cents : 0,
            'is_active' => $validated['f_is_active'],
            'inventory_asset_account_id' => $tracksInventory ? $validated['f_inventory_asset_account_id'] : null,
            'cogs_account_id' => $tracksInventory ? $validated['f_cogs_account_id'] : null,
            'reorder_point' => $validated['f_reorder_point'] !== '' ? $validated['f_reorder_point'] : null,
            'opening_qty' => $tracksInventory && $this->f_opening_qty !== '' ? (float) $this->f_opening_qty : 0.0,
            'opening_cost_cents' => $this->f_opening_cost !== '' ? Money::fromString($this->f_opening_cost)->cents : 0,
            'components' => $this->f_type === ItemType::Bundle->value ? $this->f_components : [],
        ], $existingItem);

        Flux::modal('item-form')->close();
        $this->resetForm();
        Flux::toast(variant: 'success', text: __('Item saved.'));
    }

    #[Computed]
    public function items()
    {
        return Item::query()
            ->with('category')
            ->when($this->search !== '', fn ($q) => $q->where(function ($q) {
                $q->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('sku', 'like', '%'.$this->search.'%');
            }))
            ->when(! $this->showInactive, fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->paginate(25);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    #[Computed]
    public function typeOptions(): array
    {
        return array_map(fn (ItemType $t) => ['value' => $t->value, 'label' => $t->label()], ItemType::cases());
    }

    #[Computed]
    public function itemCategoryOptions()
    {
        return ItemCategory::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    /**
     * Items eligible to be bundle components: active, non-bundle, and not this item.
     */
    #[Computed]
    public function componentItemOptions()
    {
        return Item::query()
            ->where('is_active', true)
            ->where('type', '!=', ItemType::Bundle->value)
            ->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))
            ->orderBy('name')
            ->get(['id', 'name', 'sku']);
    }

    #[Computed]
    public function accountOptions()
    {
        return Account::query()
            ->where(function ($q) {
                $q->where(fn ($inner) => $inner->selectableForItemAccount()->where('is_active', true));

                if ($this->f_income_account_id) {
                    $q->orWhere('id', $this->f_income_account_id);
                }

                if ($this->f_expense_account_id) {
                    $q->orWhere('id', $this->f_expense_account_id);
                }
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function inventoryAssetAccounts()
    {
        return Account::query()
            ->where(function ($q) {
                $q->where(fn ($inner) => $inner->where('subtype', AccountSubtype::Inventory->value)->where('is_active', true));

                if ($this->f_inventory_asset_account_id) {
                    $q->orWhere('id', $this->f_inventory_asset_account_id);
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

                if ($this->f_cogs_account_id) {
                    $q->orWhere('id', $this->f_cogs_account_id);
                }
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function taxCodes()
    {
        return TaxCode::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'f_name', 'f_sku', 'f_description', 'f_type', 'f_item_category_id',
            'f_income_account_id', 'f_expense_account_id', 'f_default_tax_code_ids', 'f_price',
            'f_inventory_asset_account_id', 'f_cogs_account_id',
            'f_reorder_point', 'f_opening_qty', 'f_opening_cost', 'f_components',
        ]);
        $this->f_is_active = true;
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Items')" :subheading="__('Reusable products and services that prefill invoice lines.')" content-class="max-w-4xl">
        <div class="mb-4 flex justify-end gap-2">
            <flux:button icon="arrow-up-tray" wire:click="openImport" data-test="import-items-button">{{ __('Import') }}</flux:button>
            <flux:button variant="primary" icon="plus" wire:click="openCreate" data-test="new-item-button">{{ __('New item') }}</flux:button>
        </div>

        <div class="mb-4 flex flex-wrap items-center gap-4">
            <flux:input
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __('Search name or SKU…') }}"
                icon="magnifying-glass"
                class="max-w-md"
                data-test="items-search"
            />
            <flux:switch wire:model.live="showInactive" :label="__('Show inactive')" data-test="items-show-inactive" />
        </div>

        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full min-w-[640px] text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-4 py-2 text-left font-medium">{{ __('Name') }}</th>
                        <th class="px-4 py-2 text-left font-medium">{{ __('Type') }}</th>
                        <th class="px-4 py-2 text-left font-medium">{{ __('Category') }}</th>
                        <th class="px-4 py-2 text-right font-medium">{{ __('Default price') }}</th>
                        <th class="px-4 py-2 text-right font-medium">{{ __('On hand') }}</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($this->items as $item)
                        <tr data-test="item-row" class="@if(! $item->is_active) opacity-50 @endif">
                            <td class="px-4 py-2">
                                {{ $item->name }}
                                @if ($item->sku) <flux:text class="text-xs text-muted-foreground">{{ $item->sku }}</flux:text> @endif
                            </td>
                            <td class="px-4 py-2 text-muted-foreground">{{ $item->type->label() }}</td>
                            <td class="px-4 py-2 text-muted-foreground">{{ optional($item->category)->name ?? '—' }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ number_format($item->default_price_cents / 100, 2) }}</td>
                            <td class="px-4 py-2 text-right font-mono">
                                @if ($item->track_inventory)
                                    @php($qty = (float) $item->qty_on_hand_cached)
                                    <span class="@if($item->isBelowReorderPoint()) text-amber-600 dark:text-amber-400 font-semibold @endif">{{ rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.') }}</span>
                                @else
                                    <span class="text-muted-foreground">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right">
                                <flux:button variant="ghost" size="sm" icon="pencil" wire:click="openEdit({{ $item->id }})" />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-muted-foreground">
                                @if ($search !== '')
                                    {{ __('No items match your search.') }}
                                @elseif (! $showInactive)
                                    {{ __('No active items. Turn on “Show inactive” to see hidden ones.') }}
                                @else
                                    {{ __('No items yet.') }}
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $this->items->links() }}</div>
    </x-pages::settings.layout>

    <flux:modal name="item-form" class="max-w-xl">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit item') : __('New item') }}</flux:heading>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <flux:input wire:model="f_name" :label="__('Name')" required data-test="item-name" />
                <flux:input wire:model="f_sku" :label="__('SKU')" />
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <flux:select wire:model.live="f_type" :label="__('Type')" data-test="item-type">
                    @foreach ($this->typeOptions as $opt)
                        <flux:select.option :value="$opt['value']">{{ $opt['label'] }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="f_item_category_id" :label="__('Category')" data-test="item-category">
                    <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                    @foreach ($this->itemCategoryOptions as $c)
                        <flux:select.option :value="$c->id">{{ $c->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:textarea wire:model="f_description" :label="__('Description')" rows="2" />

            <flux:select wire:model="f_income_account_id" :label="__('Income / sales account')" required>
                <flux:select.option value="">{{ __('— Select —') }}</flux:select.option>
                @foreach ($this->accountOptions as $a)
                    <flux:select.option :value="$a->id">{{ $a->code }} — {{ $a->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <flux:field>
                    <flux:label>{{ __('Default tax') }}</flux:label>
                    <flux:dropdown>
                        <flux:button
                            variant="outline"
                            icon:trailing="chevron-down"
                            class="w-full justify-between font-normal"
                            data-test="item-default-tax"
                        >
                            <span class="truncate">{{ $this->taxCodes->whereIn('id', $f_default_tax_code_ids)->pluck('code')->implode(', ') ?: __('— None —') }}</span>
                        </flux:button>
                        <flux:menu>
                            <flux:menu.checkbox.group wire:model.live="f_default_tax_code_ids">
                                @foreach ($this->taxCodes as $c)
                                    <flux:menu.checkbox value="{{ $c->id }}" :disabled="count($f_default_tax_code_ids) === 2 && ! in_array($c->id, $f_default_tax_code_ids)" keep-open>{{ $c->code }} — {{ $c->name }}</flux:menu.checkbox>
                                @endforeach
                            </flux:menu.checkbox.group>
                        </flux:menu>
                    </flux:dropdown>
                    <flux:description>{{ __('Pick up to two taxes (e.g. GST and PST) — each is applied separately on the line.') }}</flux:description>
                </flux:field>
                <flux:input wire:model="f_price" :label="__('Default price')" placeholder="0.00" />
            </div>

            {{-- Purchase information --}}
            <div class="space-y-3 rounded-lg border border-border p-4" data-test="item-purchase-info">
                <flux:heading size="sm">{{ __('Purchase information') }}</flux:heading>
                <flux:select wire:model="f_expense_account_id" :label="__('Purchase / expense account')" :description="__('Used for this item on bills, purchase orders & vendor credits. Falls back to the income account.')" data-test="item-expense-account">
                    <flux:select.option value="">{{ __('— Same as income account —') }}</flux:select.option>
                    @foreach ($this->accountOptions as $a)
                        <flux:select.option :value="$a->id">{{ $a->code }} — {{ $a->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            {{-- Inventory (Inventory type only) --}}
            @if ($f_type === 'inventory')
                <div class="space-y-4 rounded-lg bg-muted p-4" data-test="item-inventory-section">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <flux:select wire:model="f_inventory_asset_account_id" :label="__('Inventory asset account')" required>
                            <flux:select.option value="">{{ __('— Select —') }}</flux:select.option>
                            @foreach ($this->inventoryAssetAccounts as $a)
                                <flux:select.option :value="$a->id">{{ $a->code }} — {{ $a->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:select wire:model="f_cogs_account_id" :label="__('COGS account')" required>
                            <flux:select.option value="">{{ __('— Select —') }}</flux:select.option>
                            @foreach ($this->cogsAccounts as $a)
                                <flux:select.option :value="$a->id">{{ $a->code }} — {{ $a->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    <flux:input wire:model="f_reorder_point" :label="__('Reorder point')" placeholder="0" />

                    @if (! $editingId || ! Item::find($editingId)?->track_inventory)
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <flux:input wire:model="f_opening_qty" :label="__('Opening quantity')" placeholder="0" />
                            <flux:input wire:model="f_opening_cost" :label="__('Opening unit cost')" placeholder="0.00" />
                        </div>
                        <flux:text class="text-xs text-muted-foreground">
                            {{ __('Posts a one-time opening balance: DR Inventory Asset / CR Opening Balance Equity.') }}
                        </flux:text>
                    @endif
                </div>
            @endif

            {{-- Bundle components (Bundle type only) --}}
            @if ($f_type === 'bundle')
                <div class="space-y-3 rounded-lg bg-muted p-4" data-test="item-bundle-section">
                    <div class="flex items-center justify-between">
                        <flux:heading size="sm">{{ __('Bundle components') }}</flux:heading>
                        <flux:button variant="filled" size="xs" icon="plus" type="button" wire:click="addComponent" data-test="add-component">{{ __('Add component') }}</flux:button>
                    </div>
                    <flux:text class="text-xs text-muted-foreground">{{ __('Items included in this bundle. Selecting the bundle on a sale expands into these lines.') }}</flux:text>

                    @foreach ($f_components as $i => $component)
                        <div class="flex items-end gap-2" wire:key="component-{{ $i }}" data-test="component-row">
                            <flux:select wire:model="f_components.{{ $i }}.component_item_id" :label="$i === 0 ? __('Item') : null" class="flex-1">
                                <flux:select.option value="">{{ __('— Select —') }}</flux:select.option>
                                @foreach ($this->componentItemOptions as $opt)
                                    <flux:select.option :value="$opt->id">{{ $opt->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:input wire:model="f_components.{{ $i }}.quantity" :label="$i === 0 ? __('Qty') : null" class="w-24" />
                            <flux:button variant="ghost" size="sm" icon="x-mark" type="button" wire:click="removeComponent({{ $i }})" />
                        </div>
                    @endforeach
                    @error('f_components') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror
                </div>
            @endif

            <flux:switch wire:model="f_is_active" :label="__('Active')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="item-save-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <x-csv-import-modal
        name="item-import"
        :template-url="route('lists.template', ['company' => $company->slug, 'list' => 'items'])"
        :subtitle="__('Bulk-add items from a CSV. Rows whose SKU matches an existing item are skipped.')"
        :preview-rows="$importPreviewRows"
        :row-errors="$importErrors"
        :summary="$importSummary"
        :creatable-count="$this->importCreatableCount"
        :has-file="(bool) $importFile"
    >
        <x-slot:help>
            <p>{{ __('Columns: sku, name, description, type, item_category, is_inventory, income_account_code, expense_account_code, inventory_asset_account_code, cogs_account_code, default_price, default_tax_code, reorder_point.') }}</p>
            <p class="mt-2">{{ __('Account columns reference your Chart of Accounts by code; default_tax_code references a tax code by its code. "type" is one of service, non_inventory, other_charge, inventory, bundle (left blank, it follows is_inventory). A new item_category is created automatically if it doesn\'t exist yet.') }}</p>
        </x-slot:help>
    </x-csv-import-modal>
</section>
