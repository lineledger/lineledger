<?php

use App\Actions\Sales\SaveEstimate;
use App\Enums\EstimateStatus;
use App\Models\Account;
use App\Models\Classification;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Estimate;
use App\Models\Item;
use App\Models\Location;
use App\Models\PaymentTerm;
use App\Models\TaxCode;
use App\Rules\MoneyString;
use App\Services\Posting\DocumentNumberGenerator;
use App\Services\Posting\TaxCalculator;
use App\Support\Money;
use App\Support\Quantity;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Estimate')] class extends Component
{
    public Company $company;

    public ?Estimate $estimate = null;

    public ?int $contact_id = null;

    public string $contact_query = '';

    public bool $contact_creating = false;

    public string $new_contact_name = '';

    public string $estimate_no = '';

    public string $estimate_date = '';

    public string $expires_on = '';

    public ?int $terms_id = null;

    public ?int $sales_rep_id = null;

    public string $customer_po = '';

    public string $memo = '';

    public string $customer_message = '';

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

    public function mount(Company $company, ?Estimate $estimate = null): void
    {
        $this->company = $company;

        if ($estimate && $estimate->exists) {
            abort_if($estimate->status === EstimateStatus::Converted, 403, 'Converted estimates cannot be edited.');

            $this->estimate = $estimate->load('lines');
            $this->contact_id = $estimate->contact_id;
            $this->estimate_no = $estimate->estimate_no;
            $this->estimate_date = $estimate->estimate_date->toDateString();
            $this->expires_on = $estimate->expires_on?->toDateString() ?? '';
            $this->terms_id = $estimate->terms_id;
            $this->sales_rep_id = $estimate->sales_rep_id;
            $this->customer_po = $estimate->customer_po ?? '';
            $this->memo = $estimate->memo ?? '';
            $this->customer_message = $estimate->customer_message ?? '';

            $this->lines = $estimate->lines->map(fn ($l) => [
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
            $this->estimate_date = $this->company->currentDateTime()->toDateString();
            $this->expires_on = $this->company->currentDateTime()->addDays(30)->toDateString();
            $this->estimate_no = app(DocumentNumberGenerator::class)->next($company, Estimate::class, 'estimate_no', 'EST');
            $this->lines = [$this->emptyLine()];
        }
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

    /**
     * Triggered when an item is picked — prefill the line.
     */
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
        } catch (Throwable) {
            $unitCents = 0;
        }

        $taxCode = $line['tax_code_id'] ? TaxCode::find($line['tax_code_id']) : null;
        $secondaryTaxCode = ($line['secondary_tax_code_id'] ?? null) ? TaxCode::find($line['secondary_tax_code_id']) : null;

        // Keep the multi-select picker in step with the columns when a tax code
        // is set indirectly (item/account/contact defaults, etc.).
        $this->lines[$i]['tax_code_ids'] = array_values(array_filter([$line['tax_code_id'], $line['secondary_tax_code_id'] ?? null]));

        $discountPct = ($line['discount_pct'] ?? '') === '' ? null : $line['discount_pct'];

        $totals = $calc->line($qty, $unitCents, $taxCode, 0, $discountPct, 0, null, $secondaryTaxCode);

        $this->lines[$i]['subtotal'] = $totals['subtotal_cents'];
        $this->lines[$i]['tax'] = $totals['tax_cents'];
        $this->lines[$i]['secondary_tax'] = $totals['secondary_tax_cents'];
        $this->lines[$i]['total'] = $totals['total_cents'];
    }

    public function save(): void
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
            'estimate_no' => ['required', 'string', 'max:40'],
            'estimate_date' => ['required', 'date'],
            'expires_on' => ['nullable', 'date'],
            'terms_id' => ['nullable', 'integer', Rule::exists('payment_terms', 'id')->where('company_id', $companyId)],
            'sales_rep_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('company_id', $companyId)->where('is_employee', true)],
            'customer_po' => ['nullable', 'string', 'max:100'],
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

        $this->estimate = app(SaveEstimate::class)->handle([
            'contact_id' => $validated['contact_id'],
            'sales_rep_id' => $validated['sales_rep_id'] ?: null,
            'estimate_no' => $validated['estimate_no'],
            'estimate_date' => $validated['estimate_date'],
            'expires_on' => $validated['expires_on'] ?: null,
            'customer_po' => $validated['customer_po'] ?: null,
            'terms_id' => $validated['terms_id'] ?: null,
            'memo' => $validated['memo'] ?: null,
            'customer_message' => $validated['customer_message'] ?: null,
            'lines' => array_map(fn (array $line): array => [
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
            ], $validated['lines']),
        ], $this->estimate);

        Flux::toast(variant: 'success', text: __('Estimate saved.'));
        $this->redirectRoute('estimates.show', ['company' => $this->company->slug, 'estimate' => $this->estimate->id], navigate: true);
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
    public function termsOptions()
    {
        return PaymentTerm::query()->where('is_active', true)->orderBy('days')->get();
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
    <flux:heading size="xl" level="1" class="mb-6">{{ $estimate?->id ? __('Edit estimate') : __('New estimate') }}</flux:heading>

    <form wire:submit="save" class="space-y-6">
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
                data-test="estimate-customer-combo"
            />

            <flux:input wire:model="estimate_no" :label="__('Estimate #')" required data-test="estimate-no-input" />
            <flux:input type="date" wire:model="estimate_date" :label="__('Date')" required data-test="estimate-date-input" />
            <flux:input type="date" wire:model="expires_on" :label="__('Expires on')" data-test="estimate-expires-input" />

            <flux:select wire:model="terms_id" :label="__('Terms')">
                <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                @foreach ($this->termsOptions as $t)
                    <flux:select.option :value="$t->id">{{ $t->name }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($this->tracksSalesReps)
                <flux:select wire:model="sales_rep_id" :label="__('Sales rep')" data-test="estimate-sales-rep">
                    <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                    @foreach ($this->salesRepOptions as $rep)
                        <flux:select.option :value="$rep->id">{{ $rep->display_name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:input wire:model="customer_po" :label="__('Customer PO #')" data-test="estimate-customer-po" />
        </div>

        <flux:input wire:model="memo" :label="__('Memo')" />
        <flux:textarea wire:model="customer_message" :label="__('Customer message')" :description="__('Shown to the customer on the printed estimate.')" rows="2" />

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
                        <th class="px-2 py-2 text-left w-36">{{ __('Service date') }}</th>
                        <th class="px-2 py-2 text-left w-44">{{ __('Account') }}</th>
                        <th class="px-2 py-2 text-right w-20">{{ __('Qty') }}</th>
                        <th class="px-2 py-2 text-right w-28">{{ __('Unit price') }}</th>
                        <th class="px-2 py-2 text-right w-20">{{ __('Disc %') }}</th>
                        <th class="px-2 py-2 text-left w-32">{{ __('Tax') }}</th>
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
                        <tr wire:key="line-{{ $i }}" data-test="estimate-line-row" class="block border-b border-border p-3 lg:table-row lg:border-0 lg:p-0">
                            <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Item') }}</span>
                                <x-line-item-combo :index="$i" :items="$this->itemOptions" />
                            </td>
                            <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Description') }}</span>
                                <flux:input wire:model="lines.{{ $i }}.description" />
                            </td>
                            <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Service date') }}</span>
                                <flux:input type="date" wire:model="lines.{{ $i }}.service_date" data-test="line-service-date" />
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
                                <flux:input wire:model.live="lines.{{ $i }}.unit_price" class="lg:text-right" data-test="line-unit-price" />
                            </td>
                            <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Disc %') }}</span>
                                <flux:input wire:model.live="lines.{{ $i }}.discount_pct" class="lg:text-right" placeholder="0" data-test="line-discount-pct" />
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
                        <td colspan="{{ 8 + $this->dimensionColumns }}" class="px-2 py-2 text-right font-medium">{{ __('Subtotal') }}</td>
                        <td class="px-2 py-2 text-right font-mono" data-test="estimate-subtotal">{{ number_format($this->totals['subtotal'] / 100, 2) }}</td>
                        <td></td>
                    </tr>
                    @forelse ($this->taxBreakdown as $taxRow)
                        <tr data-test="estimate-tax-row">
                            <td colspan="{{ 8 + $this->dimensionColumns }}" class="px-2 py-2 text-right font-medium">{{ $taxRow['label'] }} {{ number_format($taxRow['rate'], 2) }}%</td>
                            <td class="px-2 py-2 text-right font-mono">{{ number_format($taxRow['tax_cents'] / 100, 2) }}</td>
                            <td></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ 8 + $this->dimensionColumns }}" class="px-2 py-2 text-right font-medium">{{ __('Tax') }}</td>
                            <td class="px-2 py-2 text-right font-mono" data-test="estimate-tax">{{ number_format($this->totals['tax'] / 100, 2) }}</td>
                            <td></td>
                        </tr>
                    @endforelse
                    <tr class="text-base">
                        <td colspan="{{ 8 + $this->dimensionColumns }}" class="px-2 py-2 text-right font-semibold">{{ __('Total') }}</td>
                        <td class="px-2 py-2 text-right font-mono font-semibold" data-test="estimate-total">{{ number_format($this->totals['total'] / 100, 2) }}</td>
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

            <flux:button variant="primary" type="submit" data-test="save-estimate-button">{{ __('Save estimate') }}</flux:button>
        </div>
    </form>
</section>
