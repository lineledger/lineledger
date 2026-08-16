<?php

use App\Actions\Sales\SaveSalesReceipt;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\ItemType;
use App\Enums\SalesReceiptStatus;
use App\Exceptions\Posting\PeriodLockedException;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Item;
use App\Models\PaymentMethod;
use App\Models\SalesReceipt;
use App\Models\TaxCode;
use App\Rules\MoneyString;
use App\Services\Posting\DocumentNumberGenerator;
use App\Services\Posting\SalesReceiptPoster;
use App\Services\Posting\TaxCalculator;
use App\Support\Money;
use App\Support\Quantity;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Sales receipt')] class extends Component {
    public Company $company;

    public ?SalesReceipt $receipt = null;

    public ?int $contact_id = null;

    public string $contact_query = '';

    public bool $contact_creating = false;

    public string $new_contact_name = '';

    public string $sales_receipt_no = '';

    public string $receipt_date = '';

    public ?int $deposit_to_account_id = null;

    public ?int $payment_method_id = null;

    public string $reference = '';

    public string $memo = '';

    /**
     * @var array<int, array{item_id: ?int, account_id: ?int, description: string, quantity: string, unit_price: string, discount_pct: string, tax_code_id: ?int, subtotal: int, tax: int, total: int}>
     */
    public array $lines = [];

    public function mount(Company $company, ?SalesReceipt $receipt = null): void
    {
        $this->company = $company;

        if ($receipt && $receipt->exists) {
            abort_if($receipt->status === SalesReceiptStatus::Void, 403, 'Voided sales receipts cannot be edited.');

            $this->receipt = $receipt->load('lines');
            $this->contact_id = $receipt->contact_id;
            $this->sales_receipt_no = $receipt->sales_receipt_no;
            $this->receipt_date = $receipt->receipt_date->toDateString();
            $this->deposit_to_account_id = $receipt->deposit_to_account_id;
            $this->payment_method_id = $receipt->payment_method_id;
            $this->reference = $receipt->reference ?? '';
            $this->memo = $receipt->memo ?? '';

            $this->lines = $receipt->lines->map(fn ($l) => [
                'item_id' => $l->item_id,
                'account_id' => $l->account_id,
                'description' => $l->description ?? '',
                'quantity' => Quantity::format($l->quantity),
                'unit_price' => Money::fromCents((int) $l->unit_price_cents)->toDecimalString(),
                'discount_pct' => $l->line_discount_pct !== null ? rtrim(rtrim((string) $l->line_discount_pct, '0'), '.') : '',
                'tax_code_id' => $l->tax_code_id,
                'secondary_tax_code_id' => $l->secondary_tax_code_id,
                'tax_code_ids' => array_values(array_filter([$l->tax_code_id, $l->secondary_tax_code_id])),
                'subtotal' => (int) $l->line_subtotal_cents,
                'tax' => (int) $l->line_tax_cents,
                'secondary_tax' => (int) $l->secondary_tax_cents,
                'total' => (int) $l->line_total_cents,
            ])->all();

            return;
        }

        $this->receipt_date = $this->company->currentDateTime()->toDateString();
        $this->sales_receipt_no = app(DocumentNumberGenerator::class)->next($company, SalesReceipt::class, 'sales_receipt_no', 'SR');

        $undeposited = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->where('is_active', true)->first();
        $this->deposit_to_account_id = $undeposited?->id ?? $this->depositToOptions->first()?->id;

        $this->lines = [$this->emptyLine()];
    }

    /**
     * @return array{item_id: ?int, account_id: ?int, description: string, quantity: string, unit_price: string, discount_pct: string, tax_code_id: ?int, subtotal: int, tax: int, total: int}
     */
    protected function emptyLine(): array
    {
        return [
            'item_id' => null,
            'account_id' => $this->company->invoiceSettingsOrNew()->default_sales_account_id,
            'description' => '',
            'quantity' => '1',
            'unit_price' => '0.00',
            'discount_pct' => '',
            'tax_code_id' => null,
            'secondary_tax_code_id' => null,
            'tax_code_ids' => [],
            'subtotal' => 0,
            'tax' => 0,
            'secondary_tax' => 0,
            'total' => 0,
        ];
    }

    public function addLine(): void
    {
        $this->lines[] = $this->emptyLine();
    }

    public function removeLine(int $i): void
    {
        if (count($this->lines) <= 1) {
            return;
        }
        unset($this->lines[$i]);
        $this->lines = array_values($this->lines);
    }

    public function updatedLines(mixed $value, ?string $key = null): void
    {
        if ($key === null) {
            return;
        }

        if (! str_ends_with($key, '.item_id')) {
            $i = (int) explode('.', $key)[0];

            if (str_ends_with($key, '.tax_code_ids')) {
                $ids = array_slice(array_values(array_unique(array_filter(
                    array_map('intval', (array) $this->lines[$i]['tax_code_ids'])
                ))), 0, 2);
                $this->lines[$i]['tax_code_id'] = $ids[0] ?? null;
                $this->lines[$i]['secondary_tax_code_id'] = $ids[1] ?? null;
            }

            $this->recalcLine($i);

            return;
        }

        $i = (int) explode('.', $key)[0];

        if ($value) {
            $item = Item::find($value);

            if ($item && $item->type === ItemType::Bundle) {
                $this->expandBundle($i, $item);

                return;
            }

            if ($item) {
                $this->lines[$i]['account_id'] = $item->income_account_id;
                $this->lines[$i]['description'] = $item->description ?? $item->name;
                $this->lines[$i]['unit_price'] = Money::fromCents((int) $item->default_price_cents)->toDecimalString();
                $this->lines[$i]['tax_code_id'] = $item->default_tax_code_id;
                $this->lines[$i]['secondary_tax_code_id'] = $item->default_secondary_tax_code_id;
            }
        }

        $this->recalcLine($i);
    }

    /**
     * Replace the picked bundle line with one prefilled line per component.
     */
    protected function expandBundle(int $i, Item $bundle): void
    {
        $bundle->loadMissing('components.component');

        $newLines = [];

        foreach ($bundle->components as $component) {
            $comp = $component->component;

            if (! $comp) {
                continue;
            }

            $line = $this->emptyLine();
            $line['item_id'] = $comp->id;
            $line['account_id'] = $comp->income_account_id;
            $line['description'] = $comp->description ?? $comp->name;
            $line['quantity'] = Quantity::format($component->quantity);
            $line['unit_price'] = Money::fromCents((int) $comp->default_price_cents)->toDecimalString();
            $line['tax_code_id'] = $comp->default_tax_code_id;
            $line['secondary_tax_code_id'] = $comp->default_secondary_tax_code_id;

            $newLines[] = $line;
        }

        if ($newLines === []) {
            $this->lines[$i]['item_id'] = null;

            return;
        }

        array_splice($this->lines, $i, 1, $newLines);
        $this->lines = array_values($this->lines);

        for ($idx = $i, $end = $i + count($newLines); $idx < $end; $idx++) {
            $this->recalcLine($idx);
        }
    }

    public function selectContact(int $id): void
    {
        $this->contact_id = $id;
        $this->contact_creating = false;
        $this->new_contact_name = '';
        $this->contact_query = '';
        $this->resetErrorBag(['contact_id', 'new_contact_name']);

        $preferred = Contact::query()->whereKey($id)->value('preferred_payment_method_id');
        if ($preferred) {
            $this->payment_method_id = (int) $preferred;
        }

        // Default each empty line's tax code to the customer's default.
        $defaultTax = Contact::query()->whereKey($id)->value('default_tax_code_id');
        if ($defaultTax) {
            foreach ($this->lines as $i => $line) {
                if (! $line['tax_code_id']) {
                    $this->lines[$i]['tax_code_id'] = (int) $defaultTax;
                    $this->recalcLine($i);
                }
            }
        }
    }

    public function startNewContact(): void
    {
        $this->new_contact_name = trim($this->contact_query);
        $this->contact_creating = true;
        $this->contact_id = null;
        $this->contact_query = '';
        $this->resetErrorBag(['contact_id', 'new_contact_name']);
    }

    public function clearContact(): void
    {
        $this->contact_id = null;
        $this->contact_creating = false;
        $this->new_contact_name = '';
        $this->contact_query = '';
        $this->resetErrorBag(['contact_id', 'new_contact_name']);
    }

    protected function recalcLine(int $i): void
    {
        $calc = app(TaxCalculator::class);
        $line = $this->lines[$i];

        $qty = $line['quantity'] === '' ? '0' : $line['quantity'];
        $price = $line['unit_price'] === '' ? '0' : $line['unit_price'];

        try {
            $unitCents = Money::fromString($price)->cents;
        } catch (Throwable) {
            $unitCents = 0;
        }

        $taxCode = $line['tax_code_id'] ? TaxCode::find($line['tax_code_id']) : null;
        $secondaryTaxCode = ($line['secondary_tax_code_id'] ?? null) ? TaxCode::find($line['secondary_tax_code_id']) : null;

        $this->lines[$i]['tax_code_ids'] = array_values(array_filter([$line['tax_code_id'], $line['secondary_tax_code_id'] ?? null]));

        $discountPct = ($line['discount_pct'] ?? '') === '' ? null : $line['discount_pct'];

        $totals = $calc->line($qty, $unitCents, $taxCode, 0, $discountPct, 0, null, $secondaryTaxCode);

        $this->lines[$i]['subtotal'] = $totals['subtotal_cents'];
        $this->lines[$i]['tax'] = $totals['tax_cents'];
        $this->lines[$i]['secondary_tax'] = $totals['secondary_tax_cents'];
        $this->lines[$i]['total'] = $totals['total_cents'];
    }

    public function saveDraft(): void
    {
        if ($this->receipt?->journal_entry_id) {
            $this->addError('lines', __('This sales receipt is posted. Use Save to update it in place.'));

            return;
        }

        $this->persist();
        Flux::toast(variant: 'success', text: __('Draft saved.'));
        $this->redirectRoute('sales-receipts.edit', ['company' => $this->company->slug, 'receipt' => $this->receipt->id], navigate: true);
    }

    public function post(SalesReceiptPoster $poster): void
    {
        $wasPosted = $this->receipt?->journal_entry_id !== null;

        if (! $this->persist()) {
            return;
        }

        try {
            $wasPosted ? $poster->repost($this->receipt) : $poster->post($this->receipt);
        } catch (PeriodLockedException|RuntimeException $e) {
            $this->addError('lines', $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: $wasPosted ? __('Sales receipt updated.') : __('Sales receipt posted.'));
        $this->redirectRoute('sales-receipts.show', ['company' => $this->company->slug, 'receipt' => $this->receipt->id], navigate: true);
    }

    protected function persist(): bool
    {
        $companyId = $this->company->id;

        if ($this->contact_creating) {
            $this->validate(
                ['new_contact_name' => ['required', 'string', 'max:255']],
                attributes: ['new_contact_name' => __('customer name')],
            );

            $contact = Contact::create([
                'display_name' => trim($this->new_contact_name),
                'is_customer' => true,
                'is_active' => true,
            ]);

            $this->contact_id = $contact->id;
            $this->contact_creating = false;
            $this->new_contact_name = '';
        }

        $validated = $this->validate([
            'contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('company_id', $companyId)->where('is_customer', true)],
            'sales_receipt_no' => ['required', 'string', 'max:40'],
            'receipt_date' => ['required', 'date'],
            'deposit_to_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', $companyId)],
            'payment_method_id' => ['nullable', 'integer', Rule::exists('payment_methods', 'id')->where('company_id', $companyId)],
            'reference' => ['nullable', 'string', 'max:100'],
            'memo' => ['nullable', 'string'],
            'lines' => ['array', 'min:1'],
            'lines.*.item_id' => ['nullable', 'integer', Rule::exists('items', 'id')->where('company_id', $companyId)],
            'lines.*.account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', $companyId)],
            'lines.*.description' => ['nullable', 'string'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.unit_price' => ['required', 'string', new MoneyString],
            'lines.*.discount_pct' => ['nullable', 'numeric', 'between:0,100'],
            'lines.*.tax_code_id' => ['nullable', 'integer', Rule::exists('tax_codes', 'id')->where('company_id', $companyId)],
            'lines.*.secondary_tax_code_id' => ['nullable', 'integer', Rule::exists('tax_codes', 'id')->where('company_id', $companyId)],
        ]);

        $this->receipt = app(SaveSalesReceipt::class)->handle([
            'contact_id' => $validated['contact_id'] ?: null,
            'sales_receipt_no' => $validated['sales_receipt_no'],
            'receipt_date' => $validated['receipt_date'],
            'deposit_to_account_id' => $validated['deposit_to_account_id'],
            'payment_method_id' => $validated['payment_method_id'] ?: null,
            'reference' => $validated['reference'] ?: null,
            'memo' => $validated['memo'] ?: null,
            'lines' => array_map(fn (array $line): array => [
                'item_id' => $line['item_id'] ?? null,
                'account_id' => $line['account_id'],
                'description' => $line['description'] ?? '',
                'quantity' => $line['quantity'],
                'unit_price_cents' => Money::fromString($line['unit_price'])->cents,
                'line_discount_pct' => ($line['discount_pct'] ?? '') !== '' ? $line['discount_pct'] : null,
                'tax_code_id' => $line['tax_code_id'] ?? null,
                'secondary_tax_code_id' => $line['secondary_tax_code_id'] ?? null,
            ], $validated['lines']),
        ], $this->receipt);

        return true;
    }

    #[Computed]
    public function customers()
    {
        $query = Contact::query()->where('is_customer', true)->where('is_active', true);

        if (trim($this->contact_query) !== '') {
            $query->where('display_name', 'like', '%'.trim($this->contact_query).'%');
        }

        return $query->orderBy('display_name')->limit(50)->get(['id', 'display_name']);
    }

    #[Computed]
    public function selectedContactName(): ?string
    {
        return $this->contact_id
            ? Contact::query()->where('id', $this->contact_id)->value('display_name')
            : null;
    }

    #[Computed]
    public function itemOptions()
    {
        return Item::query()->with('category')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku', 'item_category_id'])
            ->map(fn ($i) => ['id' => $i->id, 'name' => $i->name, 'sku' => $i->sku, 'category' => $i->category?->name]);
    }

    #[Computed]
    public function accountOptions()
    {
        $lineAccountIds = collect($this->lines)->pluck('account_id')->filter()->all();

        return Account::query()
            ->where(function ($q) use ($lineAccountIds) {
                $q->where(fn ($inner) => $inner->selectableForItemAccount()->where('is_active', true));

                if ($lineAccountIds !== []) {
                    $q->orWhereIn('id', $lineAccountIds);
                }
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function taxCodeOptions()
    {
        return TaxCode::query()->where('is_active', true)->orderBy('code')->get();
    }

    #[Computed]
    public function depositToOptions()
    {
        return Account::query()
            ->where(function ($q) {
                $q->where(fn ($inner) => $inner
                    ->whereIn('subtype', [AccountSubtype::Bank->value, AccountSubtype::UndepositedFunds->value])
                    ->where('is_active', true));

                if ($this->deposit_to_account_id) {
                    $q->orWhere('id', $this->deposit_to_account_id);
                }
            })
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'subtype']);
    }

    #[Computed]
    public function paymentMethodOptions()
    {
        return PaymentMethod::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    /**
     * @return array{subtotal: int, tax: int, total: int}
     */
    #[Computed]
    public function totals(): array
    {
        $sub = array_sum(array_column($this->lines, 'subtotal'));
        $tax = array_sum(array_column($this->lines, 'tax')) + array_sum(array_column($this->lines, 'secondary_tax'));

        return ['subtotal' => $sub, 'tax' => $tax, 'total' => $sub + $tax];
    }

    /**
     * Per-tax-code breakdown of the live line tax, so the footer can show each tax
     * (e.g. GST and PST) on its own row rather than one combined "Tax" total. Mirrors
     * {@see \App\Support\Tax\LineTaxBreakdown} but reads the unsaved component state.
     *
     * @return array<int, array{label: string, rate: float, tax_cents: int}>
     */
    #[Computed]
    public function taxBreakdown(): array
    {
        $codes = $this->taxCodeOptions->keyBy('id');
        $rows = [];

        foreach ($this->lines as $line) {
            foreach ([
                [$line['tax_code_id'] ?? null, (int) ($line['tax'] ?? 0)],
                [$line['secondary_tax_code_id'] ?? null, (int) ($line['secondary_tax'] ?? 0)],
            ] as [$id, $cents]) {
                if (! $id || $cents === 0) {
                    continue;
                }

                $code = $codes[$id] ?? null;
                $rows[$id] ??= [
                    'label' => $code ? (string) $code->name : '',
                    'rate' => $code ? $code->ratePercent() : 0.0,
                    'tax_cents' => 0,
                ];
                $rows[$id]['tax_cents'] += $cents;
            }
        }

        return array_values($rows);
    }
}; ?>

<section class="w-full">
    <flux:heading size="xl" level="1" class="mb-6">{{ $receipt?->id ? __('Edit sales receipt') : __('New sales receipt') }}</flux:heading>

    <form wire:submit="post" class="space-y-6">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <x-contact-combo
                :label="__('Customer (optional)')"
                :placeholder="__('Search, or leave blank for a cash sale…')"
                :add-label="__('customer')"
                :options="$this->customers"
                :selected-id="$contact_id"
                :selected-name="$this->selectedContactName"
                :query="$contact_query"
                :creating="$contact_creating"
                :new-name="$new_contact_name"
                data-test="sales-receipt-customer-combo"
            />

            <flux:input wire:model="sales_receipt_no" :label="__('Sales receipt #')" required data-test="sales-receipt-no-input" />
            <flux:input type="date" wire:model="receipt_date" :label="__('Date')" required data-test="sales-receipt-date-input" />

            <flux:select wire:model="deposit_to_account_id" :label="__('Deposit to')" required data-test="sales-receipt-deposit-select">
                <flux:select.option value="">{{ __('— Select —') }}</flux:select.option>
                @foreach ($this->depositToOptions as $opt)
                    <flux:select.option :value="$opt->id">{{ $opt->code }} — {{ $opt->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="payment_method_id" :label="__('Payment method')" data-test="sales-receipt-method-select">
                <flux:select.option value="">{{ __('— Select —') }}</flux:select.option>
                @foreach ($this->paymentMethodOptions as $m)
                    <flux:select.option :value="$m->id">{{ $m->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="reference" :label="__('Reference')" :placeholder="$company->jurisdiction->chequeLabel('ref')" />
        </div>

        <flux:input wire:model="memo" :label="__('Memo')" />

        <div class="overflow-x-auto rounded-lg border border-border">
            <table
                class="w-full text-sm"
                x-on:keydown.tab.capture="tabAddRow($event)"
                x-data="{
                    addRowAndFocus(next) {
                        this.$wire.addLine().then(() => {
                            this.$nextTick(() => this.$root.querySelector('[data-item-input=&quot;' + next + '&quot;], [data-line-first=&quot;' + next + '&quot;]')?.focus());
                        });
                    },
                    tabAddRow(event) {
                        if (event.shiftKey) return;
                        const row = event.target.closest('tr');
                        if (! row || ! row.parentElement) return;
                        const fields = [...row.querySelectorAll('input:not([type=hidden]), select, textarea, [data-test=&quot;line-tax&quot;]')].filter((el) => ! el.disabled && el.offsetParent !== null);
                        if (! fields.length || event.target !== fields[fields.length - 1]) return;
                        const rows = [...row.parentElement.querySelectorAll(':scope > tr')];
                        if (row !== rows[rows.length - 1]) return;
                        event.preventDefault();
                        this.addRowAndFocus(rows.length);
                    },
                }"
            >
                <thead class="hidden bg-muted lg:table-header-group">
                    <tr>
                        <th class="px-2 py-2 text-left w-44">{{ __('Item') }}</th>
                        <th class="px-2 py-2 text-left">{{ __('Description') }}</th>
                        <th class="px-2 py-2 text-left w-44">{{ __('Account') }}</th>
                        <th class="px-2 py-2 text-right w-20">{{ __('Qty') }}</th>
                        <th class="px-2 py-2 text-right w-28">{{ __('Unit price') }}</th>
                        <th class="px-2 py-2 text-right w-20">{{ __('Disc %') }}</th>
                        <th class="px-2 py-2 text-left w-32">{{ __('Tax') }}</th>
                        <th class="px-2 py-2 text-right w-28">{{ __('Amount') }}</th>
                        <th class="px-2 py-2 w-10"></th>
                    </tr>
                </thead>
                <tbody class="lg:divide-y lg:divide-border">
                    @foreach ($lines as $i => $line)
                        <tr wire:key="sr-line-{{ $i }}" data-test="sales-receipt-line-row" class="block border-b border-border p-3 lg:table-row lg:border-0 lg:p-0">
                            <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Item') }}</span>
                                <x-line-item-combo :index="$i" :items="$this->itemOptions" />
                            </td>
                            <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Description') }}</span>
                                <flux:input wire:model="lines.{{ $i }}.description" />
                            </td>
                            <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Account') }}</span>
                                <flux:select wire:model.live="lines.{{ $i }}.account_id" data-test="sr-line-account">
                                    <flux:select.option value="">{{ __('—') }}</flux:select.option>
                                    @foreach ($this->accountOptions as $opt)
                                        <flux:select.option :value="$opt->id">{{ $opt->code }} — {{ $opt->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                @error('lines.'.$i.'.account_id') <flux:text class="mt-1 text-xs text-red-600">{{ __('Account is required.') }}</flux:text> @enderror
                            </td>
                            <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Qty') }}</span>
                                <flux:input wire:model.live="lines.{{ $i }}.quantity" class="lg:text-right" data-test="sr-line-qty" />
                            </td>
                            <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Unit price') }}</span>
                                <x-amount-input model="lines.{{ $i }}.unit_price" class="lg:text-right" data-test="sr-line-unit-price" />
                            </td>
                            <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Disc %') }}</span>
                                <flux:input wire:model.live="lines.{{ $i }}.discount_pct" class="lg:text-right" placeholder="0" data-test="sr-line-discount-pct" />
                            </td>
                            <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Tax') }}</span>
                                @php($selectedTaxIds = $line['tax_code_ids'] ?? [])
                                <flux:dropdown>
                                    <flux:button variant="outline" size="sm" icon:trailing="chevron-down" class="w-full justify-between font-normal" data-test="line-tax">
                                        <span class="truncate">{{ $this->taxCodeOptions->whereIn('id', $selectedTaxIds)->pluck('code')->implode(', ') ?: __('Select tax') }}</span>
                                    </flux:button>
                                    <flux:menu>
                                        <flux:menu.checkbox.group wire:model.live="lines.{{ $i }}.tax_code_ids">
                                            @foreach ($this->taxCodeOptions as $opt)
                                                <flux:menu.checkbox value="{{ $opt->id }}" :disabled="count($selectedTaxIds) === 2 && ! in_array($opt->id, $selectedTaxIds)" keep-open>{{ $opt->code }}</flux:menu.checkbox>
                                            @endforeach
                                        </flux:menu.checkbox.group>
                                    </flux:menu>
                                </flux:dropdown>
                            </td>
                            <td class="flex items-center justify-between px-2 py-1 font-mono lg:table-cell lg:py-2 lg:text-right" data-test="sr-line-total">
                                <span class="text-xs font-medium text-muted-foreground lg:hidden">{{ __('Amount') }}</span>
                                <span>{{ number_format($line['total'] / 100, 2) }}</span>
                            </td>
                            <td class="block px-2 pt-2 text-right lg:table-cell lg:p-2">
                                <flux:button variant="ghost" size="sm" icon="x-mark" type="button" tabindex="-1" wire:click="removeLine({{ $i }})">
                                    <span class="lg:hidden">{{ __('Remove line') }}</span>
                                </flux:button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="hidden bg-muted lg:table-footer-group">
                    <tr>
                        <td colspan="6" class="px-2 py-2 text-right font-medium">{{ __('Subtotal') }}</td>
                        <td class="px-2 py-2 text-right font-mono" data-test="sales-receipt-subtotal">{{ number_format($this->totals['subtotal'] / 100, 2) }}</td>
                        <td></td>
                    </tr>
                    @forelse ($this->taxBreakdown as $taxRow)
                        <tr data-test="sales-receipt-tax-row">
                            <td colspan="6" class="px-2 py-2 text-right font-medium">{{ $taxRow['label'] }} {{ number_format($taxRow['rate'], 2) }}%</td>
                            <td class="px-2 py-2 text-right font-mono">{{ number_format($taxRow['tax_cents'] / 100, 2) }}</td>
                            <td></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-2 py-2 text-right font-medium">{{ __('Tax') }}</td>
                            <td class="px-2 py-2 text-right font-mono" data-test="sales-receipt-tax">{{ number_format($this->totals['tax'] / 100, 2) }}</td>
                            <td></td>
                        </tr>
                    @endforelse
                    <tr class="text-base">
                        <td colspan="6" class="px-2 py-2 text-right font-semibold">{{ __('Total') }}</td>
                        <td class="px-2 py-2 text-right font-mono font-semibold" data-test="sales-receipt-total">{{ number_format($this->totals['total'] / 100, 2) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>

            <div class="space-y-1 border-t border-border bg-muted px-3 py-3 text-sm lg:hidden">
                <div class="flex justify-between"><span class="font-medium">{{ __('Subtotal') }}</span><span class="font-mono">{{ number_format($this->totals['subtotal'] / 100, 2) }}</span></div>
                @forelse ($this->taxBreakdown as $taxRow)
                    <div class="flex justify-between"><span class="font-medium">{{ $taxRow['label'] }} {{ number_format($taxRow['rate'], 2) }}%</span><span class="font-mono">{{ number_format($taxRow['tax_cents'] / 100, 2) }}</span></div>
                @empty
                    <div class="flex justify-between"><span class="font-medium">{{ __('Tax') }}</span><span class="font-mono">{{ number_format($this->totals['tax'] / 100, 2) }}</span></div>
                @endforelse
                <div class="flex justify-between text-base"><span class="font-semibold">{{ __('Total') }}</span><span class="font-mono font-semibold">{{ number_format($this->totals['total'] / 100, 2) }}</span></div>
            </div>
        </div>

        @error('lines') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror

        <div class="flex items-center justify-between">
            <flux:button variant="filled" type="button" icon="plus" wire:click="addLine">{{ __('Add line') }}</flux:button>

            <div class="flex gap-2">
                @if ($receipt?->journal_entry_id)
                    <flux:button variant="primary" type="submit" data-test="post-sales-receipt-button">{{ __('Save changes') }}</flux:button>
                @else
                    <flux:button variant="filled" type="button" wire:click="saveDraft" data-test="save-sales-receipt-draft-button">{{ __('Save draft') }}</flux:button>
                    <flux:button variant="primary" type="submit" data-test="post-sales-receipt-button">{{ __('Save & post') }}</flux:button>
                @endif
            </div>
        </div>
    </form>
</section>
