<?php

use App\Actions\Sales\SaveCreditMemo;
use App\Enums\CreditMemoStatus;
use App\Exceptions\Posting\PeriodLockedException;
use App\Models\Account;
use App\Models\Classification;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\Invoice;
use App\Models\InvoiceSetting;
use App\Models\Item;
use App\Models\Location;
use App\Models\TaxCode;
use App\Rules\MoneyString;
use App\Services\Posting\CreditMemoPoster;
use App\Services\Posting\DocumentNumberGenerator;
use App\Services\Posting\TaxCalculator;
use App\Support\Money;
use App\Support\Quantity;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Credit memo')] class extends Component
{
    public Company $company;

    public ?CreditMemo $creditMemo = null;

    public ?int $contact_id = null;

    public string $contact_query = '';

    public bool $contact_creating = false;

    public string $new_contact_name = '';

    public string $credit_memo_no = '';

    public string $credit_memo_date = '';

    public ?int $sales_rep_id = null;

    public string $memo = '';

    public string $customer_message = '';

    /**
     * Which optional header fields and line columns are shown on this form.
     * Persisted per-company on the shared InvoiceSetting so credit memos and
     * invoices keep a consistent layout.
     *
     * @var array<string, bool>
     */
    public array $fieldVisibility = [];

    /**
     * @var array<int, array{
     *     item_id: ?int,
     *     account_id: ?int,
     *     description: string,
     *     service_date: string,
     *     quantity: string,
     *     unit_price: string,
     *     discount_pct: string,
     *     tax_code_id: ?int,
     *     class_id: ?int,
     *     location_id: ?int,
     *     subtotal: int,
     *     tax: int,
     *     total: int
     * }>
     */
    public array $lines = [];

    public function mount(Company $company, ?CreditMemo $credit_memo = null): void
    {
        $this->company = $company;

        $this->loadFieldVisibility();

        if ($credit_memo && $credit_memo->exists) {
            abort_if($credit_memo->status === CreditMemoStatus::Void, 403, 'Voided credit memos cannot be edited.');

            $this->creditMemo = $credit_memo->load('lines');
            $this->contact_id = $credit_memo->contact_id;
            $this->credit_memo_no = $credit_memo->credit_memo_no;
            $this->credit_memo_date = $credit_memo->credit_memo_date->toDateString();
            $this->sales_rep_id = $credit_memo->sales_rep_id;
            $this->memo = $credit_memo->memo ?? '';
            $this->customer_message = $credit_memo->customer_message ?? '';

            $this->lines = $credit_memo->lines->map(fn ($l) => [
                'item_id' => $l->item_id,
                'account_id' => $l->account_id,
                'description' => $l->description ?? '',
                'service_date' => $l->service_date?->toDateString() ?? '',
                'quantity' => Quantity::format($l->quantity),
                'unit_price' => Money::fromCents((int) $l->unit_price_cents)->toDecimalString(),
                'discount_pct' => $l->line_discount_pct !== null ? rtrim(rtrim((string) $l->line_discount_pct, '0'), '.') : '',
                'tax_code_id' => $l->tax_code_id,
                'secondary_tax_code_id' => $l->secondary_tax_code_id,
                'tax_code_ids' => array_values(array_filter([$l->tax_code_id, $l->secondary_tax_code_id])),
                'class_id' => $l->class_id,
                'location_id' => $l->location_id,
                'subtotal' => (int) $l->line_subtotal_cents,
                'tax' => (int) $l->line_tax_cents,
                'secondary_tax' => (int) $l->secondary_tax_cents,
                'total' => (int) $l->line_total_cents,
            ])->all();
        } else {
            $this->credit_memo_date = $this->company->currentDateTime()->toDateString();
            $this->credit_memo_no = app(DocumentNumberGenerator::class)->next($company, CreditMemo::class, 'credit_memo_no', 'CM');
            $this->lines = [$this->emptyLine()];

            $this->prefillFromInvoice($company);
        }
    }

    protected function prefillFromInvoice(Company $company): void
    {
        if (! request('invoice')) {
            return;
        }

        $invoice = Invoice::where('company_id', $company->id)
            ->with('lines')
            ->find((int) request('invoice'));

        if (! $invoice) {
            return;
        }

        $this->contact_id = $invoice->contact_id;
        $this->memo = __('Credit for invoice :number', ['number' => $invoice->invoice_no]);

        $lines = $invoice->lines->map(fn ($l) => [
            'item_id' => $l->item_id,
            'account_id' => $l->account_id,
            'description' => $l->description ?? '',
            'service_date' => $l->service_date?->toDateString() ?? '',
            'quantity' => Quantity::format($l->quantity),
            'unit_price' => Money::fromCents((int) $l->unit_price_cents)->toDecimalString(),
            'discount_pct' => $l->line_discount_pct !== null ? rtrim(rtrim((string) $l->line_discount_pct, '0'), '.') : '',
            'tax_code_id' => $l->tax_code_id,
            'secondary_tax_code_id' => $l->secondary_tax_code_id,
            'tax_code_ids' => array_values(array_filter([$l->tax_code_id, $l->secondary_tax_code_id])),
            'class_id' => $l->class_id,
            'location_id' => $l->location_id,
            'subtotal' => (int) $l->line_subtotal_cents,
            'tax' => (int) $l->line_tax_cents,
            'secondary_tax' => (int) $l->secondary_tax_cents,
            'total' => (int) $l->line_total_cents,
        ])->all();

        if ($lines !== []) {
            $this->lines = $lines;
        }

        $this->applyContactDefaults();
    }

    /**
     * @return array{item_id: ?int, account_id: ?int, description: string, service_date: string, quantity: string, unit_price: string, discount_pct: string, tax_code_id: ?int, class_id: ?int, location_id: ?int, subtotal: int, tax: int, total: int}
     */
    protected function emptyLine(): array
    {
        return [
            'item_id' => null,
            'account_id' => null,
            'description' => '',
            'service_date' => '',
            'quantity' => '1',
            'unit_price' => '0.00',
            'discount_pct' => '',
            'tax_code_id' => null,
            'secondary_tax_code_id' => null,
            'tax_code_ids' => [],
            'class_id' => null,
            'location_id' => null,
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
        // Livewire passes a null key when the whole `lines` array is updated
        // (a top-level, dot-less path) rather than a single nested field.
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

            // Picking an account fills a blank tax code from the account's
            // default — never overwriting one already on the line, so item
            // and contact defaults keep their existing precedence.
            if (str_ends_with($key, '.account_id') && $value && empty($this->lines[$i]['tax_code_id'])) {
                $this->lines[$i]['tax_code_id'] = Account::find($value)?->default_tax_code_id;
            }

            $this->recalcLine($i);

            return;
        }

        $i = (int) explode('.', $key)[0];
        $itemId = $value;

        if ($itemId) {
            $item = Item::find($itemId);

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

    public function selectContact(int $id): void
    {
        $this->contact_id = $id;
        $this->contact_creating = false;
        $this->new_contact_name = '';
        $this->contact_query = '';
        $this->resetErrorBag(['contact_id', 'new_contact_name']);

        $this->applyContactDefaults();
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

    protected function applyContactDefaults(): void
    {
        if (! $this->contact_id) {
            return;
        }

        $contact = Contact::find($this->contact_id);

        if ($contact?->default_tax_code_id) {
            foreach ($this->lines as $i => $line) {
                if (! $line['tax_code_id']) {
                    $this->lines[$i]['tax_code_id'] = $contact->default_tax_code_id;
                    $this->recalcLine($i);
                }
            }
        }
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
        if ($this->creditMemo?->journal_entry_id) {
            $this->addError('lines', __('This credit memo is posted. Use Save to update it in place.'));

            return;
        }

        $this->persist();
        Flux::toast(variant: 'success', text: __('Draft saved.'));
        $this->redirectRoute('credit-memos.edit', ['company' => $this->company->slug, 'credit_memo' => $this->creditMemo->id], navigate: true);
    }

    public function postCreditMemo(CreditMemoPoster $poster): void
    {
        $wasPosted = $this->creditMemo?->journal_entry_id !== null;

        $this->persist();

        try {
            $wasPosted ? $poster->repost($this->creditMemo) : $poster->post($this->creditMemo);
        } catch (PeriodLockedException|RuntimeException $e) {
            $this->addError('lines', $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: $wasPosted ? __('Credit memo updated.') : __('Credit memo posted.'));
        $this->redirectRoute('credit-memos.show', ['company' => $this->company->slug, 'credit_memo' => $this->creditMemo->id], navigate: true);
    }

    protected function persist(): void
    {
        $companyId = $this->company->id;

        if ($this->contact_creating) {
            $name = trim($this->new_contact_name);

            $this->validate(
                ['new_contact_name' => ['required', 'string', 'max:255']],
                attributes: ['new_contact_name' => __('customer name')],
            );

            $contact = Contact::create([
                'display_name' => $name,
                'is_customer' => true,
                'is_active' => true,
            ]);

            $this->contact_id = $contact->id;
            $this->contact_creating = false;
            $this->new_contact_name = '';
            $this->applyContactDefaults();
        }

        $validated = $this->validate([
            'contact_id' => ['required', 'integer', Rule::exists('contacts', 'id')->where('company_id', $companyId)->where('is_customer', true)],
            'credit_memo_no' => ['required', 'string', 'max:40'],
            'credit_memo_date' => ['required', 'date'],
            'sales_rep_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('company_id', $companyId)->where('is_employee', true)],
            'memo' => ['nullable', 'string'],
            'customer_message' => ['nullable', 'string'],
            'lines' => ['array', 'min:1'],
            'lines.*.item_id' => ['nullable', 'integer', Rule::exists('items', 'id')->where('company_id', $companyId)],
            'lines.*.account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', $companyId)],
            'lines.*.description' => ['nullable', 'string'],
            'lines.*.service_date' => ['nullable', 'date'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.unit_price' => ['required', 'string', new MoneyString],
            'lines.*.discount_pct' => ['nullable', 'numeric', 'between:0,100'],
            'lines.*.tax_code_id' => ['nullable', 'integer', Rule::exists('tax_codes', 'id')->where('company_id', $companyId)],
            'lines.*.secondary_tax_code_id' => ['nullable', 'integer', Rule::exists('tax_codes', 'id')->where('company_id', $companyId)],
            'lines.*.class_id' => ['nullable', 'integer', Rule::exists('classifications', 'id')->where('company_id', $companyId)],
            'lines.*.location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('company_id', $companyId)],
        ]);

        $this->creditMemo = app(SaveCreditMemo::class)->handle([
            'contact_id' => $validated['contact_id'],
            'sales_rep_id' => $validated['sales_rep_id'] ?: null,
            'credit_memo_no' => $validated['credit_memo_no'],
            'credit_memo_date' => $validated['credit_memo_date'],
            'memo' => $validated['memo'] ?: null,
            'customer_message' => $validated['customer_message'] ?: null,
            'lines' => collect($validated['lines'])->map(fn ($line) => [
                'item_id' => $line['item_id'] ?? null,
                'account_id' => $line['account_id'],
                'description' => $line['description'] ?? '',
                'service_date' => ($line['service_date'] ?? '') ?: null,
                'quantity' => $line['quantity'],
                'unit_price_cents' => Money::fromString($line['unit_price'])->cents,
                'line_discount_pct' => ($line['discount_pct'] ?? '') !== '' ? $line['discount_pct'] : null,
                'tax_code_id' => $line['tax_code_id'] ?? null,
                'secondary_tax_code_id' => $line['secondary_tax_code_id'] ?? null,
                'class_id' => $line['class_id'] ?? null,
                'location_id' => $line['location_id'] ?? null,
            ])->all(),
        ], $this->creditMemo);
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
    public function salesRepOptions()
    {
        return Contact::query()
            ->where('is_employee', true)
            ->where('is_active', true)
            ->orderBy('display_name')
            ->get(['id', 'display_name']);
    }

    #[Computed]
    public function itemOptions()
    {
        return Item::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku']);
    }

    #[Computed]
    public function accountOptions()
    {
        // Keep any account already coded on a line visible, even if it has
        // since been deactivated, so editing never silently drops a selection.
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
    public function classificationOptions()
    {
        return Classification::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function locationOptions()
    {
        return Location::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function tracksClasses(): bool
    {
        return (bool) $this->company->features_classes;
    }

    #[Computed]
    public function tracksLocations(): bool
    {
        return (bool) $this->company->features_locations;
    }

    /**
     * Sales reps are drawn from employee contacts, which only exist when the
     * Employees feature is enabled. With it off there is no way to manage reps,
     * so the field is hidden rather than left dangling empty.
     */
    #[Computed]
    public function tracksSalesReps(): bool
    {
        return (bool) $this->company->features_employees;
    }

    #[Computed]
    public function dimensionColumns(): int
    {
        return (int) $this->tracksClasses + (int) $this->tracksLocations;
    }

    /**
     * The toggleable fields, keyed by the property name used in $fieldVisibility,
     * mapped to the InvoiceSetting column that persists them.
     *
     * @var array<string, string>
     */
    protected const VISIBILITY_MAP = [
        'sales_rep' => 'show_sales_rep',
        'memo' => 'show_memo',
        'customer_message' => 'show_customer_message',
        'item_column' => 'show_item_column',
        'qty_column' => 'show_qty_column',
        'tax_column' => 'show_tax_column',
        'service_date_column' => 'show_service_date_column',
        'account_column' => 'show_account_column',
    ];

    protected function loadFieldVisibility(): void
    {
        $settings = $this->company->invoiceSettingsOrNew();

        foreach (self::VISIBILITY_MAP as $key => $column) {
            $this->fieldVisibility[$key] = (bool) $settings->{$column};
        }
    }

    /**
     * Persist a toggle change immediately so the layout sticks for the company.
     */
    public function updatedFieldVisibility(): void
    {
        $payload = [];

        foreach (self::VISIBILITY_MAP as $key => $column) {
            $payload[$column] = (bool) ($this->fieldVisibility[$key] ?? true);
        }

        InvoiceSetting::updateOrCreate(['company_id' => $this->company->id], $payload);
    }

    /**
     * Columns spanned by the totals labels in the footer: every line column
     * except Amount and the remove button. Always-on columns are Description,
     * Unit price, Disc % (3); Item, Qty, Tax, Service date and Account are
     * optional, plus any tracked dimensions.
     */
    #[Computed]
    public function lineLeadingColspan(): int
    {
        return 3
            + (int) ($this->fieldVisibility['item_column'] ?? true)
            + (int) ($this->fieldVisibility['qty_column'] ?? true)
            + (int) ($this->fieldVisibility['tax_column'] ?? true)
            + (int) ($this->fieldVisibility['service_date_column'] ?? true)
            + (int) ($this->fieldVisibility['account_column'] ?? true)
            + $this->dimensionColumns;
    }

    #[Computed]
    public function totals(): array
    {
        $sub = array_sum(array_column($this->lines, 'subtotal'));
        $tax = array_sum(array_column($this->lines, 'tax'));

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
    <div class="mb-6 flex items-start justify-between gap-4">
        <flux:heading size="xl" level="1">{{ $creditMemo?->id ? __('Edit credit memo') : __('New credit memo') }}</flux:heading>

        <flux:dropdown align="end" data-test="credit-memo-field-settings">
            <flux:button variant="ghost" size="sm" icon="adjustments-horizontal" icon:trailing="chevron-down">{{ __('Fields') }}</flux:button>

            <flux:menu>
                <flux:menu.group :heading="__('Header fields')">
                    @if ($this->tracksSalesReps)
                        <flux:menu.checkbox wire:model.live="fieldVisibility.sales_rep" keep-open>{{ __('Sales rep') }}</flux:menu.checkbox>
                    @endif
                    <flux:menu.checkbox wire:model.live="fieldVisibility.memo" keep-open>{{ __('Memo') }}</flux:menu.checkbox>
                    <flux:menu.checkbox wire:model.live="fieldVisibility.customer_message" keep-open>{{ __('Message displayed on credit memo') }}</flux:menu.checkbox>
                </flux:menu.group>

                <flux:menu.separator />

                <flux:menu.group :heading="__('Line columns')">
                    <flux:menu.checkbox wire:model.live="fieldVisibility.item_column" keep-open>{{ __('Item') }}</flux:menu.checkbox>
                    <flux:menu.checkbox wire:model.live="fieldVisibility.qty_column" keep-open>{{ __('Qty') }}</flux:menu.checkbox>
                    <flux:menu.checkbox wire:model.live="fieldVisibility.tax_column" keep-open>{{ __('Tax') }}</flux:menu.checkbox>
                    <flux:menu.checkbox wire:model.live="fieldVisibility.service_date_column" keep-open>{{ __('Service date') }}</flux:menu.checkbox>
                    <flux:menu.checkbox wire:model.live="fieldVisibility.account_column" keep-open>{{ __('Account') }}</flux:menu.checkbox>
                </flux:menu.group>
            </flux:menu>
        </flux:dropdown>
    </div>

    <form wire:submit="postCreditMemo" class="space-y-6">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <x-contact-combo
                :label="__('Customer')"
                :placeholder="__('Search or type to add a new customer…')"
                :add-label="__('customer')"
                :options="$this->customers"
                :selected-id="$contact_id"
                :selected-name="$this->selectedContactName"
                :query="$contact_query"
                :creating="$contact_creating"
                :new-name="$new_contact_name"
                data-test="credit-memo-customer-combo"
            />

            <flux:input wire:model="credit_memo_no" :label="__('Credit memo #')" required data-test="credit-memo-no-input" />
            <flux:input type="date" wire:model.live="credit_memo_date" :label="__('Date')" required data-test="credit-memo-date-input" />

            @if ($this->tracksSalesReps && $fieldVisibility['sales_rep'])
                <flux:select wire:model="sales_rep_id" :label="__('Sales rep')" data-test="credit-memo-sales-rep">
                    <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                    @foreach ($this->salesRepOptions as $rep)
                        <flux:select.option :value="$rep->id">{{ $rep->display_name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
        </div>

        @if ($fieldVisibility['memo'])
            <flux:input wire:model="memo" :label="__('Memo')" />
        @endif
        @if ($fieldVisibility['customer_message'])
            <flux:textarea wire:model="customer_message" :label="__('Message displayed on credit memo')" rows="2" data-test="credit-memo-customer-message" />
        @endif

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
                        @if ($fieldVisibility['item_column'])
                            <th class="px-2 py-2 text-left w-44">{{ __('Item') }}</th>
                        @endif
                        <th class="px-2 py-2 text-left">{{ __('Description') }}</th>
                        @if ($fieldVisibility['service_date_column'])
                            <th class="px-2 py-2 text-left w-36">{{ __('Service date') }}</th>
                        @endif
                        @if ($fieldVisibility['account_column'])
                            <th class="px-2 py-2 text-left w-44">{{ __('Account') }}</th>
                        @endif
                        @if ($fieldVisibility['qty_column'])
                            <th class="px-2 py-2 text-right w-20">{{ __('Qty') }}</th>
                        @endif
                        <th class="px-2 py-2 text-right w-28">{{ __('Unit price') }}</th>
                        <th class="px-2 py-2 text-right w-20">{{ __('Disc %') }}</th>
                        @if ($fieldVisibility['tax_column'])
                            <th class="px-2 py-2 text-left w-32">{{ __('Tax') }}</th>
                        @endif
                        @if ($this->tracksClasses)
                            <th class="px-2 py-2 text-left w-32">{{ __('Class') }}</th>
                        @endif
                        @if ($this->tracksLocations)
                            <th class="px-2 py-2 text-left w-32">{{ __('Location') }}</th>
                        @endif
                        <th class="px-2 py-2 text-right w-28">{{ __('Amount') }}</th>
                        <th class="px-2 py-2 w-10"></th>
                    </tr>
                </thead>
                <tbody class="lg:divide-y lg:divide-border">
                    @foreach ($lines as $i => $line)
                        <tr wire:key="line-{{ $i }}" data-test="credit-memo-line-row" class="block border-b border-border p-3 lg:table-row lg:border-0 lg:p-0">
                            @if ($fieldVisibility['item_column'])
                                <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                    <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Item') }}</span>
                                    <x-line-item-combo :index="$i" :items="$this->itemOptions" />
                                </td>
                            @endif
                            <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Description') }}</span>
                                <flux:input wire:model="lines.{{ $i }}.description" />
                            </td>
                            @if ($fieldVisibility['service_date_column'])
                                <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                    <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Service date') }}</span>
                                    <flux:input type="date" wire:model="lines.{{ $i }}.service_date" data-test="line-service-date" />
                                </td>
                            @endif
                            @if ($fieldVisibility['account_column'])
                                <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                    <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Account') }}</span>
                                    <flux:select wire:model.live="lines.{{ $i }}.account_id" data-test="line-account">
                                        <flux:select.option value="">{{ __('—') }}</flux:select.option>
                                        @foreach ($this->accountOptions as $opt)
                                            <flux:select.option :value="$opt->id">{{ $opt->code }} — {{ $opt->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                </td>
                            @endif
                            @if ($fieldVisibility['qty_column'])
                                <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                    <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Qty') }}</span>
                                    <flux:input wire:model.live="lines.{{ $i }}.quantity" class="lg:text-right" data-test="line-qty" />
                                </td>
                            @endif
                            <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Unit price') }}</span>
                                <x-amount-input model="lines.{{ $i }}.unit_price" class="lg:text-right" data-test="line-unit-price" />
                            </td>
                            <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Disc %') }}</span>
                                <flux:input wire:model.live="lines.{{ $i }}.discount_pct" class="lg:text-right" placeholder="0" data-test="line-discount-pct" />
                            </td>
                            @if ($fieldVisibility['tax_column'])
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
                            @endif
                            @if ($this->tracksClasses)
                                <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                    <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Class') }}</span>
                                    <flux:select wire:model="lines.{{ $i }}.class_id" data-test="line-class">
                                        <flux:select.option value="">{{ __('—') }}</flux:select.option>
                                        @foreach ($this->classificationOptions as $opt)
                                            <flux:select.option :value="$opt->id">{{ $opt->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                </td>
                            @endif
                            @if ($this->tracksLocations)
                                <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                    <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Location') }}</span>
                                    <flux:select wire:model="lines.{{ $i }}.location_id" data-test="line-location">
                                        <flux:select.option value="">{{ __('—') }}</flux:select.option>
                                        @foreach ($this->locationOptions as $opt)
                                            <flux:select.option :value="$opt->id">{{ $opt->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                </td>
                            @endif
                            <td class="flex items-center justify-between px-2 py-1 font-mono lg:table-cell lg:py-2 lg:text-right" data-test="line-total">
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
                        <td colspan="{{ $this->lineLeadingColspan }}" class="px-2 py-2 text-right font-medium">{{ __('Subtotal') }}</td>
                        <td class="px-2 py-2 text-right font-mono" data-test="credit-memo-subtotal">{{ number_format($this->totals['subtotal'] / 100, 2) }}</td>
                        <td></td>
                    </tr>
                    @forelse ($this->taxBreakdown as $taxRow)
                        <tr data-test="credit-memo-tax-row">
                            <td colspan="{{ $this->lineLeadingColspan }}" class="px-2 py-2 text-right font-medium">{{ $taxRow['label'] }} {{ number_format($taxRow['rate'], 2) }}%</td>
                            <td class="px-2 py-2 text-right font-mono">{{ number_format($taxRow['tax_cents'] / 100, 2) }}</td>
                            <td></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $this->lineLeadingColspan }}" class="px-2 py-2 text-right font-medium">{{ __('Tax') }}</td>
                            <td class="px-2 py-2 text-right font-mono" data-test="credit-memo-tax">{{ number_format($this->totals['tax'] / 100, 2) }}</td>
                            <td></td>
                        </tr>
                    @endforelse
                    <tr class="text-base">
                        <td colspan="{{ $this->lineLeadingColspan }}" class="px-2 py-2 text-right font-semibold">{{ __('Total') }}</td>
                        <td class="px-2 py-2 text-right font-mono font-semibold" data-test="credit-memo-total">{{ number_format($this->totals['total'] / 100, 2) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>

            {{-- Mobile totals (tfoot is desktop-only) --}}
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
                @if ($creditMemo?->journal_entry_id)
                    <flux:button variant="primary" type="submit" data-test="post-credit-memo-button">{{ __('Save changes') }}</flux:button>
                @else
                    <flux:button variant="filled" type="button" wire:click="saveDraft" data-test="save-draft-button">{{ __('Save draft') }}</flux:button>
                    <flux:button variant="primary" type="submit" data-test="post-credit-memo-button">{{ __('Post credit memo') }}</flux:button>
                @endif
            </div>
        </div>
    </form>
</section>
