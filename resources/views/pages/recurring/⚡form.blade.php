<?php

use App\Actions\Recurring\SaveRecurringDocument;
use App\Enums\AccountType;
use App\Enums\BillType;
use App\Enums\RecurrenceEndType;
use App\Enums\RecurrenceFrequency;
use App\Enums\RecurringAutomationMode;
use App\Enums\RecurringDocumentType;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Item;
use App\Models\PaymentTerm;
use App\Models\RecurringDocument;
use App\Models\TaxCode;
use App\Rules\MoneyString;
use App\Services\Posting\TaxCalculator;
use App\Support\Money;
use App\Support\Quantity;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Recurring')] class extends Component {
    public Company $company;

    public ?RecurringDocument $recurring = null;

    public string $document_type = 'invoice';

    public string $automation_mode = 'draft';

    public string $name = '';

    public ?int $contact_id = null;

    public string $contact_query = '';

    public bool $contact_creating = false;

    public string $new_contact_name = '';

    public string $vendor_reference = '';

    public ?int $terms_id = null;

    public string $memo = '';

    public string $frequency = 'monthly';

    public string $start_date = '';

    public ?int $day_of_month = null;

    public string $end_type = 'never';

    public string $end_date = '';

    public ?int $max_occurrences = null;

    /**
     * @var array<int, array{
     *     item_id: ?int, account_id: ?int, description: string, quantity: string,
     *     unit_price: string, tax_code_id: ?int, subtotal: int, tax: int, total: int
     * }>
     */
    public array $lines = [];

    public function mount(Company $company, ?RecurringDocument $recurring = null): void
    {
        $this->company = $company;

        if ($recurring && $recurring->exists) {
            $this->recurring = $recurring->load('lines');
            $this->document_type = $recurring->document_type->value;
            $this->automation_mode = $recurring->automation_mode?->value ?? 'draft';
            $this->name = $recurring->name ?? '';
            $this->contact_id = $recurring->contact_id;
            $this->vendor_reference = $recurring->vendor_reference ?? '';
            $this->terms_id = $recurring->terms_id;
            $this->memo = $recurring->memo ?? '';
            $this->frequency = $recurring->frequency->value;
            $this->start_date = $recurring->start_date->toDateString();
            $this->day_of_month = $recurring->day_of_month;
            $this->end_type = $recurring->end_type->value;
            $this->end_date = $recurring->end_date?->toDateString() ?? '';
            $this->max_occurrences = $recurring->max_occurrences;

            $this->lines = $recurring->lines->map(fn ($l) => [
                'item_id' => $l->item_id,
                'account_id' => $l->account_id,
                'description' => $l->description ?? '',
                'quantity' => Quantity::format($l->quantity),
                'unit_price' => Money::fromCents((int) $l->unit_price_cents)->toDecimalString(),
                'tax_code_id' => $l->tax_code_id,
                'secondary_tax_code_id' => $l->secondary_tax_code_id,
                'tax_code_ids' => array_values(array_filter([$l->tax_code_id, $l->secondary_tax_code_id])),
                'subtotal' => 0,
                'tax' => 0,
                'secondary_tax' => 0,
                'total' => 0,
            ])->all();

            foreach (array_keys($this->lines) as $i) {
                $this->recalcLine($i);
            }
        } else {
            $type = request()->query('type');
            $this->document_type = $type === 'bill' ? 'bill' : 'invoice';

            $today = $this->company->currentDateTime();
            $this->start_date = $today->toDateString();
            $this->day_of_month = (int) $today->format('j');
            $this->lines = [$this->emptyLine()];
        }
    }

    /**
     * @return array{item_id: ?int, account_id: ?int, description: string, quantity: string, unit_price: string, tax_code_id: ?int, subtotal: int, tax: int, total: int}
     */
    protected function emptyLine(): array
    {
        return [
            'item_id' => null,
            'account_id' => null,
            'description' => '',
            'quantity' => '1',
            'unit_price' => '0.00',
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
        // Livewire passes a null key when the whole `lines` array is updated
        // (a top-level, dot-less path) rather than a single nested field.
        if ($key === null) {
            return;
        }

        if (! str_ends_with($key, '.item_id')) {
            $i = (int) explode('.', $key)[0];

            // The tax picker is a multi-select bound to tax_code_ids; fan the
            // (max two) chosen codes back out to the primary/secondary columns
            // that drive calculation and posting.
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

        if ($value) {
            $item = Item::find($value);

            if ($item) {
                $this->lines[$i]['account_id'] = $this->isBill()
                    ? ($item->expense_account_id ?? $item->income_account_id)
                    : $item->income_account_id;
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

        if ($contact?->default_terms_id) {
            $this->terms_id = $contact->default_terms_id;
        }

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
        } catch (\Throwable) {
            $unitCents = 0;
        }

        $taxCode = $line['tax_code_id'] ? TaxCode::find($line['tax_code_id']) : null;
        $secondaryTaxCode = ($line['secondary_tax_code_id'] ?? null) ? TaxCode::find($line['secondary_tax_code_id']) : null;

        // Keep the multi-select picker in step with the columns when a tax code
        // is set indirectly (item/account/contact defaults, etc.).
        $this->lines[$i]['tax_code_ids'] = array_values(array_filter([$line['tax_code_id'], $line['secondary_tax_code_id'] ?? null]));

        $totals = $calc->line($qty, $unitCents, $taxCode, 0, null, 0, null, $secondaryTaxCode);

        $this->lines[$i]['subtotal'] = $totals['subtotal_cents'];
        $this->lines[$i]['tax'] = $totals['tax_cents'];
        $this->lines[$i]['secondary_tax'] = $totals['secondary_tax_cents'];
        $this->lines[$i]['total'] = $totals['total_cents'];
    }

    public function isBill(): bool
    {
        return $this->document_type === 'bill';
    }

    public function save(): void
    {
        $companyId = $this->company->id;
        $isBill = $this->isBill();

        if ($this->contact_creating) {
            $this->validate(
                ['new_contact_name' => ['required', 'string', 'max:255']],
                attributes: ['new_contact_name' => $isBill ? __('vendor name') : __('customer name')],
            );

            $contact = Contact::create([
                'display_name' => trim($this->new_contact_name),
                'is_customer' => ! $isBill,
                'is_vendor' => $isBill,
                'is_active' => true,
            ]);

            $this->contact_id = $contact->id;
            $this->contact_creating = false;
            $this->new_contact_name = '';
            $this->applyContactDefaults();
        }

        $contactRole = $isBill ? 'is_vendor' : 'is_customer';

        $validated = $this->validate([
            'document_type' => ['required', Rule::in(['invoice', 'bill'])],
            'automation_mode' => ['required', Rule::in(array_column(RecurringAutomationMode::cases(), 'value'))],
            'name' => ['nullable', 'string', 'max:255'],
            'contact_id' => ['required', 'integer', Rule::exists('contacts', 'id')->where('company_id', $companyId)->where($contactRole, true)],
            'vendor_reference' => ['nullable', 'string', 'max:100'],
            'terms_id' => ['nullable', 'integer', Rule::exists('payment_terms', 'id')->where('company_id', $companyId)],
            'memo' => ['nullable', 'string'],
            'frequency' => ['required', Rule::in(array_column(RecurrenceFrequency::cases(), 'value'))],
            'start_date' => ['required', 'date'],
            'day_of_month' => ['nullable', 'integer', 'min:1', 'max:31'],
            'end_type' => ['required', Rule::in(array_column(RecurrenceEndType::cases(), 'value'))],
            'end_date' => ['nullable', 'required_if:end_type,on_date', 'date', 'after_or_equal:start_date'],
            'max_occurrences' => ['nullable', 'required_if:end_type,after_occurrences', 'integer', 'min:1'],
            'lines' => ['array', 'min:1'],
            'lines.*.item_id' => ['nullable', 'integer', Rule::exists('items', 'id')->where('company_id', $companyId)],
            'lines.*.account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', $companyId)],
            'lines.*.description' => ['nullable', 'string'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.unit_price' => ['required', 'string', new MoneyString],
            'lines.*.tax_code_id' => ['nullable', 'integer', Rule::exists('tax_codes', 'id')->where('company_id', $companyId)],
            'lines.*.secondary_tax_code_id' => ['nullable', 'integer', Rule::exists('tax_codes', 'id')->where('company_id', $companyId)],
        ]);

        $this->recurring = app(SaveRecurringDocument::class)->handle([
            'document_type' => $validated['document_type'],
            'automation_mode' => $validated['automation_mode'],
            'contact_id' => $validated['contact_id'],
            'bill_type' => $isBill ? BillType::Vendor->value : null,
            'vendor_reference' => $isBill ? ($validated['vendor_reference'] ?: null) : null,
            'terms_id' => $validated['terms_id'] ?: null,
            'memo' => $validated['memo'] ?: null,
            'name' => $validated['name'] ?: null,
            'frequency' => $validated['frequency'],
            'start_date' => $validated['start_date'],
            'day_of_month' => $validated['day_of_month'] ?: null,
            'end_type' => $validated['end_type'],
            'end_date' => $validated['end_date'] ?: null,
            'max_occurrences' => $validated['max_occurrences'] ?: null,
            'lines' => array_map(fn (array $line): array => [
                'item_id' => $line['item_id'] ?? null,
                'account_id' => $line['account_id'],
                'description' => $line['description'] ?? '',
                'quantity' => $line['quantity'],
                'unit_price_cents' => Money::fromString($line['unit_price'])->cents,
                'tax_code_id' => $line['tax_code_id'] ?? null,
                'secondary_tax_code_id' => $line['secondary_tax_code_id'] ?? null,
            ], $validated['lines']),
        ], $this->recurring);

        Flux::toast(variant: 'success', text: __('Recurring schedule saved.'));
        $this->redirectRoute('recurring.show', ['company' => $this->company->slug, 'recurring' => $this->recurring->id], navigate: true);
    }

    #[Computed]
    public function contactOptions()
    {
        $role = $this->isBill() ? 'is_vendor' : 'is_customer';

        $query = Contact::query()->where($role, true)->where('is_active', true);

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
    public function termsOptions()
    {
        return PaymentTerm::query()->where('is_active', true)->orderBy('days')->get();
    }

    #[Computed]
    public function itemOptions()
    {
        return Item::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku']);
    }

    #[Computed]
    public function accountOptions()
    {
        $lineAccountIds = collect($this->lines)->pluck('account_id')->filter()->all();

        $query = Account::query();

        if ($this->isBill()) {
            $query->where(function ($q) use ($lineAccountIds) {
                $q->where(fn ($inner) => $inner
                    ->whereIn('type', [AccountType::Expense->value, AccountType::Asset->value, AccountType::Liability->value])
                    ->where('is_active', true));

                if ($lineAccountIds !== []) {
                    $q->orWhereIn('id', $lineAccountIds);
                }
            });
        } else {
            $query->where(function ($q) use ($lineAccountIds) {
                $q->where(fn ($inner) => $inner->selectableForItemAccount()->where('is_active', true));

                if ($lineAccountIds !== []) {
                    $q->orWhereIn('id', $lineAccountIds);
                }
            });
        }

        return $query->orderBy('code')->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function taxCodeOptions()
    {
        return TaxCode::query()->where('is_active', true)->orderBy('code')->get();
    }

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
    <flux:heading size="xl" level="1" class="mb-6">
        {{ $recurring?->id ? __('Edit recurring schedule') : ($this->isBill() ? __('New recurring bill') : __('New recurring invoice')) }}
    </flux:heading>

    <form wire:submit="save" class="space-y-6">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <x-contact-combo
                :label="$this->isBill() ? __('Vendor') : __('Customer')"
                :placeholder="$this->isBill() ? __('Search or type to add a new vendor…') : __('Search or type to add a new customer…')"
                :add-label="$this->isBill() ? __('vendor') : __('customer')"
                :options="$this->contactOptions"
                :selected-id="$contact_id"
                :selected-name="$this->selectedContactName"
                :query="$contact_query"
                :creating="$contact_creating"
                :new-name="$new_contact_name"
                data-test="recurring-contact-combo"
            />

            <flux:input wire:model="name" :label="__('Schedule name')" placeholder="{{ __('e.g. Monthly retainer') }}" data-test="recurring-name-input" />

            @if ($this->isBill())
                <flux:input wire:model="vendor_reference" :label="__('Vendor reference')" placeholder="{{ __('Their invoice #') }}" />
            @endif

            <flux:select wire:model="terms_id" :label="__('Terms')">
                <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                @foreach ($this->termsOptions as $t)
                    <flux:select.option :value="$t->id">{{ $t->name }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        <flux:input wire:model="memo" :label="__('Memo')" />

        @unless ($this->isBill())
            <flux:select wire:model="automation_mode" :label="__('When each invoice is generated')" data-test="recurring-automation-mode">
                @foreach (RecurringAutomationMode::cases() as $mode)
                    <flux:select.option :value="$mode->value">{{ $mode->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:text size="sm" class="text-muted-foreground">{{ __('Automatic options post each invoice to your books; emailing also sends it to the customer (skipped if they have no email).') }}</flux:text>
        @endunless

        <flux:separator :text="__('Schedule')" />

        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <flux:select wire:model.live="frequency" :label="__('Frequency')" data-test="recurring-frequency">
                @foreach (\App\Enums\RecurrenceFrequency::cases() as $f)
                    <flux:select.option :value="$f->value">{{ $f->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input type="date" wire:model="start_date" :label="__('Start date')" required data-test="recurring-start-date" />

            @if ($frequency !== 'weekly')
                <flux:input type="number" min="1" max="31" wire:model="day_of_month" :label="__('Day of month')" placeholder="{{ __('1–31') }}" data-test="recurring-day-of-month" />
            @endif
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <flux:select wire:model.live="end_type" :label="__('Ends')" data-test="recurring-end-type">
                @foreach (\App\Enums\RecurrenceEndType::cases() as $e)
                    <flux:select.option :value="$e->value">{{ $e->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($end_type === 'on_date')
                <flux:input type="date" wire:model="end_date" :label="__('End date')" data-test="recurring-end-date" />
            @elseif ($end_type === 'after_occurrences')
                <flux:input type="number" min="1" wire:model="max_occurrences" :label="__('Number of occurrences')" data-test="recurring-max-occurrences" />
            @endif
        </div>

        <flux:separator :text="__('Line items')" />

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
                        <th class="px-2 py-2 text-left w-32">{{ __('Tax') }}</th>
                        <th class="px-2 py-2 text-right w-28">{{ __('Amount') }}</th>
                        <th class="px-2 py-2 w-10"></th>
                    </tr>
                </thead>
                <tbody class="lg:divide-y lg:divide-border">
                    @foreach ($lines as $i => $line)
                        <tr wire:key="line-{{ $i }}" data-test="recurring-line-row" class="block border-b border-border p-3 lg:table-row lg:border-0 lg:p-0">
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
                                <flux:select wire:model.live="lines.{{ $i }}.account_id" data-test="line-account">
                                    <flux:select.option value="">{{ __('—') }}</flux:select.option>
                                    @foreach ($this->accountOptions as $opt)
                                        <flux:select.option :value="$opt->id">{{ $opt->code }} — {{ $opt->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </td>
                            <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Qty') }}</span>
                                <flux:input wire:model.live="lines.{{ $i }}.quantity" class="lg:text-right" data-test="line-qty" />
                            </td>
                            <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Unit price') }}</span>
                                <x-amount-input model="lines.{{ $i }}.unit_price" class="lg:text-right" data-test="line-unit-price" />
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
                        <td colspan="6" class="px-2 py-2 text-right font-medium">{{ __('Subtotal') }}</td>
                        <td class="px-2 py-2 text-right font-mono" data-test="recurring-subtotal">{{ number_format($this->totals['subtotal'] / 100, 2) }}</td>
                        <td></td>
                    </tr>
                    @forelse ($this->taxBreakdown as $taxRow)
                        <tr data-test="recurring-tax-row">
                            <td colspan="6" class="px-2 py-2 text-right font-medium">{{ $taxRow['label'] }} {{ number_format($taxRow['rate'], 2) }}%</td>
                            <td class="px-2 py-2 text-right font-mono">{{ number_format($taxRow['tax_cents'] / 100, 2) }}</td>
                            <td></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-2 py-2 text-right font-medium">{{ __('Tax') }}</td>
                            <td class="px-2 py-2 text-right font-mono" data-test="recurring-tax">{{ number_format($this->totals['tax'] / 100, 2) }}</td>
                            <td></td>
                        </tr>
                    @endforelse
                    <tr class="text-base">
                        <td colspan="6" class="px-2 py-2 text-right font-semibold">{{ __('Total') }}</td>
                        <td class="px-2 py-2 text-right font-mono font-semibold" data-test="recurring-total">{{ number_format($this->totals['total'] / 100, 2) }}</td>
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
                <flux:button variant="ghost" :href="route('recurring.index', ['company' => $company->slug])" wire:navigate>{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" type="submit" data-test="save-recurring-button">{{ __('Save schedule') }}</flux:button>
            </div>
        </div>
    </form>
</section>
