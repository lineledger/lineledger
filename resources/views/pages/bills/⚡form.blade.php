<?php

use App\Actions\Purchasing\SaveBill;
use App\Enums\AccountType;
use App\Enums\BillStatus;
use App\Enums\BillType;
use App\Exceptions\Posting\PeriodLockedException;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Bill;
use App\Models\Classification;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Item;
use App\Models\Location;
use App\Models\PaymentTerm;
use App\Models\TaxCode;
use App\Rules\MoneyString;
use App\Services\AttachmentService;
use App\Services\Posting\BillPoster;
use App\Services\Posting\DocumentNumberGenerator;
use App\Services\Posting\TaxCalculator;
use App\Support\Money;
use App\Support\Quantity;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Bill')] class extends Component
{
    use WithFileUploads;

    public Company $company;

    public ?Bill $bill = null;

    public ?int $contact_id = null;

    public string $contact_query = '';

    public bool $contact_creating = false;

    public string $new_contact_name = '';

    public string $bill_no = '';

    public string $vendor_reference = '';

    public string $bill_date = '';

    public string $due_date = '';

    public ?int $terms_id = null;

    public string $memo = '';

    /** Set true once the user confirms a duplicate-bill-number warning. */
    public bool $ackDuplicateBillNo = false;

    /** Which save the duplicate modal should resume: 'draft' or 'post'. */
    public ?string $pendingSaveAction = null;

    #[Url(as: 'from')]
    public ?int $duplicateFromId = null;

    /**
     * @var array<int, array{
     *     item_id: ?int, account_id: ?int, description: string,
     *     quantity: string, unit_price: string, discount_pct: string, tax_code_id: ?int,
     *     secondary_tax_code_id: ?int, tax_override: string, class_id: ?int, location_id: ?int,
     *     subtotal: int, auto_tax: int, tax: int, secondary_tax: int, total: int
     * }>
     */
    public array $lines = [];

    /** @var array<int, mixed> */
    public array $newAttachments = [];

    public function mount(Company $company, ?Bill $bill = null): void
    {
        $this->company = $company;

        if ($bill && $bill->exists) {
            abort_if($bill->status === BillStatus::Void, 403, 'Voided bills cannot be edited.');
            abort_if($bill->bill_type !== BillType::Vendor, 404);

            $this->bill = $bill->load('lines');
            $this->contact_id = $bill->contact_id;
            $this->bill_no = $bill->bill_no;
            $this->vendor_reference = $bill->vendor_reference ?? '';
            $this->bill_date = $bill->bill_date->toDateString();
            $this->due_date = $bill->due_date->toDateString();
            $this->terms_id = $bill->terms_id;
            $this->memo = $bill->memo ?? '';

            $this->lines = $bill->lines->map(fn ($l) => [
                'item_id' => $l->item_id,
                'account_id' => $l->account_id,
                'description' => $l->description ?? '',
                'quantity' => Quantity::format($l->quantity),
                'unit_price' => Money::fromCents((int) $l->unit_price_cents)->toDecimalString(),
                'discount_pct' => $l->line_discount_pct !== null ? rtrim(rtrim((string) $l->line_discount_pct, '0'), '.') : '',
                'tax_code_id' => $l->tax_code_id,
                'secondary_tax_code_id' => $l->secondary_tax_code_id,
                'tax_code_ids' => array_values(array_filter([$l->tax_code_id, $l->secondary_tax_code_id])),
                'tax_override' => $l->tax_override_cents !== null ? Money::fromCents((int) $l->tax_override_cents)->toDecimalString() : '',
                'class_id' => $l->class_id,
                'location_id' => $l->location_id,
                'subtotal' => (int) $l->line_subtotal_cents,
                'auto_tax' => 0,
                'tax' => (int) $l->line_tax_cents,
                'secondary_tax' => (int) $l->secondary_tax_cents,
                'total' => (int) $l->line_total_cents,
            ])->all();

            foreach (array_keys($this->lines) as $i) {
                $this->recalcLine($i);
            }
        } else {
            $this->bill_date = $this->company->currentDateTime()->toDateString();
            $this->due_date = $this->company->currentDateTime()->addDays(30)->toDateString();
            $this->bill_no = app(DocumentNumberGenerator::class)->next($company, Bill::class, 'bill_no', 'BILL');
            $this->lines = [$this->emptyLine()];

            if ($this->duplicateFromId) {
                $this->prefillFrom($this->duplicateFromId);
            }
        }
    }

    protected function prefillFrom(int $sourceId): void
    {
        $source = Bill::query()
            ->where('company_id', $this->company->id)
            ->where('bill_type', BillType::Vendor)
            ->with('lines')
            ->find($sourceId);

        if (! $source) {
            return;
        }

        $this->contact_id = $source->contact_id;
        $this->terms_id = $source->terms_id;
        $this->memo = $source->memo ?? '';

        if ($this->terms_id) {
            $term = PaymentTerm::find($this->terms_id);
            if ($term) {
                $this->due_date = $term->dueDateFrom(CarbonImmutable::parse($this->bill_date))->toDateString();
            }
        }

        $lines = $source->lines->map(fn ($l) => [
            'item_id' => $l->item_id,
            'account_id' => $l->account_id,
            'description' => $l->description ?? '',
            'quantity' => Quantity::format($l->quantity),
            'unit_price' => Money::fromCents((int) $l->unit_price_cents)->toDecimalString(),
            'discount_pct' => $l->line_discount_pct !== null ? rtrim(rtrim((string) $l->line_discount_pct, '0'), '.') : '',
            'tax_code_id' => $l->tax_code_id,
            'secondary_tax_code_id' => $l->secondary_tax_code_id,
            'tax_code_ids' => array_values(array_filter([$l->tax_code_id, $l->secondary_tax_code_id])),
            'tax_override' => $l->tax_override_cents !== null ? Money::fromCents((int) $l->tax_override_cents)->toDecimalString() : '',
            'class_id' => $l->class_id,
            'location_id' => $l->location_id,
            'subtotal' => (int) $l->line_subtotal_cents,
            'auto_tax' => 0,
            'tax' => (int) $l->line_tax_cents,
            'secondary_tax' => (int) $l->secondary_tax_cents,
            'total' => (int) $l->line_total_cents,
        ])->all();

        if (! empty($lines)) {
            $this->lines = $lines;

            foreach (array_keys($this->lines) as $i) {
                $this->recalcLine($i);
            }
        }
    }

    /**
     * @return array{item_id: ?int, account_id: ?int, description: string, quantity: string, unit_price: string, discount_pct: string, tax_code_id: ?int, secondary_tax_code_id: ?int, tax_override: string, class_id: ?int, location_id: ?int, subtotal: int, auto_tax: int, tax: int, secondary_tax: int, total: int}
     */
    protected function emptyLine(): array
    {
        return [
            'item_id' => null,
            'account_id' => null,
            'description' => '',
            'quantity' => '1',
            'unit_price' => '0.00',
            'discount_pct' => '',
            'tax_code_id' => null,
            'secondary_tax_code_id' => null,
            'tax_code_ids' => [],
            'tax_override' => '',
            'class_id' => null,
            'location_id' => null,
            'subtotal' => 0,
            'auto_tax' => 0,
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
                $this->lines[$i]['account_id'] = $item->expense_account_id ?? $item->income_account_id;
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
            $term = PaymentTerm::find($this->terms_id);

            if ($term && $this->bill_date) {
                $this->due_date = $term->dueDateFrom(CarbonImmutable::parse($this->bill_date))->toDateString();
            }
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

    public function updatedTermsId(?int $value): void
    {
        if ($value && $this->bill_date) {
            $term = PaymentTerm::find($value);
            if ($term) {
                $this->due_date = $term->dueDateFrom(CarbonImmutable::parse($this->bill_date))->toDateString();
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

        $autoTax = $totals['tax_cents'];
        $override = trim((string) ($line['tax_override'] ?? ''));
        if ($override === '') {
            $taxCents = $autoTax;
        } else {
            $taxCents = Money::tryFromString($override)?->cents ?? $autoTax;
        }

        $secondaryTax = $totals['secondary_tax_cents'];

        $this->lines[$i]['subtotal'] = $totals['subtotal_cents'];
        $this->lines[$i]['auto_tax'] = $autoTax;
        $this->lines[$i]['tax'] = $taxCents;
        $this->lines[$i]['secondary_tax'] = $secondaryTax;
        $this->lines[$i]['total'] = $totals['subtotal_cents'] + $taxCents + $secondaryTax;
    }

    public function saveDraft(): void
    {
        if ($this->bill?->journal_entry_id) {
            $this->addError('lines', __('This bill is posted. Use Save to update it in place.'));

            return;
        }

        if ($this->guardDuplicateBillNo('draft')) {
            return;
        }

        $this->persist();
        Flux::toast(variant: 'success', text: __('Draft saved.'));
        $this->redirectRoute('bills.edit', ['company' => $this->company->slug, 'bill' => $this->bill->id], navigate: true);
    }

    public function postBill(BillPoster $poster): void
    {
        if ($this->guardDuplicateBillNo('post')) {
            return;
        }

        $wasPosted = $this->bill?->journal_entry_id !== null;

        $this->persist();

        try {
            $wasPosted ? $poster->repost($this->bill) : $poster->post($this->bill);
        } catch (PeriodLockedException|RuntimeException $e) {
            $this->addError('lines', $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: $wasPosted ? __('Bill updated.') : __('Bill posted.'));
        $this->redirectRoute('bills.show', ['company' => $this->company->slug, 'bill' => $this->bill->id], navigate: true);
    }

    /**
     * Soft "duplicate bill number" guard (QuickBooks parity). When the company
     * preference is on and the supplier already has a bill with the same
     * reference number, open a confirm modal instead of saving. Returns true
     * when the caller should stop and wait for the user to confirm.
     */
    protected function guardDuplicateBillNo(string $action): bool
    {
        if ($this->ackDuplicateBillNo || ! $this->company->warn_duplicate_bill_no) {
            return false;
        }

        if (! $this->duplicateBillNoExists()) {
            return false;
        }

        $this->pendingSaveAction = $action;
        Flux::modal('duplicate-bill-warning')->show();

        return true;
    }

    /**
     * Whether another live bill for this supplier already carries the same
     * supplier reference number (LineLedger's equivalent of QuickBooks' "Bill
     * no."). A blank reference, a new vendor, or no contact never collides.
     */
    protected function duplicateBillNoExists(): bool
    {
        $reference = trim($this->vendor_reference);

        if ($reference === '' || ! $this->contact_id || $this->contact_creating) {
            return false;
        }

        return Bill::query()
            ->where('company_id', $this->company->id)
            ->where('contact_id', $this->contact_id)
            ->where('vendor_reference', $reference)
            ->where('status', '!=', BillStatus::Void->value)
            ->when($this->bill?->exists, fn ($q) => $q->whereKeyNot($this->bill->id))
            ->exists();
    }

    public function confirmDuplicateBillNo(BillPoster $poster): void
    {
        $this->ackDuplicateBillNo = true;
        Flux::modal('duplicate-bill-warning')->close();

        $this->pendingSaveAction === 'post'
            ? $this->postBill($poster)
            : $this->saveDraft();
    }

    protected function persist(): void
    {
        $companyId = $this->company->id;

        if ($this->contact_creating) {
            $name = trim($this->new_contact_name);

            $this->validate(
                ['new_contact_name' => ['required', 'string', 'max:255']],
                attributes: ['new_contact_name' => __('vendor name')],
            );

            $contact = Contact::create([
                'display_name' => $name,
                'is_vendor' => true,
                'is_active' => true,
            ]);

            $this->contact_id = $contact->id;
            $this->contact_creating = false;
            $this->new_contact_name = '';
            $this->applyContactDefaults();
        }

        $validated = $this->validate([
            'contact_id' => ['required', 'integer', Rule::exists('contacts', 'id')->where('company_id', $companyId)->where('is_vendor', true)],
            'bill_no' => ['required', 'string', 'max:40'],
            'vendor_reference' => ['nullable', 'string', 'max:100'],
            'bill_date' => ['required', 'date'],
            'due_date' => ['required', 'date'],
            'terms_id' => ['nullable', 'integer', Rule::exists('payment_terms', 'id')->where('company_id', $companyId)],
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
            'lines.*.tax_override' => ['nullable', 'string', new MoneyString],
            'lines.*.class_id' => ['nullable', 'integer', Rule::exists('classifications', 'id')->where('company_id', $companyId)],
            'lines.*.location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('company_id', $companyId)],
            ...AttachmentService::uploadRules(),
        ]);

        $this->bill = app(SaveBill::class)->handle([
            'contact_id' => $validated['contact_id'],
            'bill_type' => BillType::Vendor->value,
            'bill_no' => $validated['bill_no'],
            'vendor_reference' => $validated['vendor_reference'] ?: null,
            'bill_date' => $validated['bill_date'],
            'due_date' => $validated['due_date'],
            'terms_id' => $validated['terms_id'] ?: null,
            'memo' => $validated['memo'] ?: null,
            'lines' => array_map(fn ($line) => [
                'item_id' => $line['item_id'] ?? null,
                'account_id' => $line['account_id'],
                'description' => $line['description'] ?? '',
                'quantity' => $line['quantity'],
                'unit_price_cents' => Money::fromString($line['unit_price'])->cents,
                'line_discount_pct' => ($line['discount_pct'] ?? '') !== '' ? $line['discount_pct'] : null,
                'tax_code_id' => $line['tax_code_id'] ?? null,
                'secondary_tax_code_id' => $line['secondary_tax_code_id'] ?? null,
                'tax_override_cents' => ($line['tax_override'] ?? '') !== ''
                    ? Money::fromString($line['tax_override'])->cents
                    : null,
                'class_id' => $line['class_id'] ?? null,
                'location_id' => $line['location_id'] ?? null,
            ], $validated['lines']),
        ], $this->bill);

        if ($this->newAttachments !== []) {
            app(AttachmentService::class)->upload($this->bill, $this->newAttachments, Auth::id());
            $this->newAttachments = [];
            unset($this->attachments);
        }
    }

    /**
     * @return Collection<int, Attachment>
     */
    #[Computed]
    public function attachments()
    {
        return $this->bill?->exists ? $this->bill->attachments()->get() : collect();
    }

    public function uploadAttachments(AttachmentService $service): void
    {
        if (! $this->bill?->exists) {
            return;
        }

        $this->validate(AttachmentService::uploadRules());

        $service->upload($this->bill, $this->newAttachments, Auth::id());

        $this->newAttachments = [];
        unset($this->attachments);

        Flux::toast(variant: 'success', text: __('Attachments uploaded.'));
    }

    public function removeAttachment(int $id, AttachmentService $service): void
    {
        if (! $this->bill?->exists) {
            return;
        }

        $service->remove(Attachment::findOrFail($id), $this->bill);

        unset($this->attachments);

        Flux::toast(variant: 'success', text: __('Attachment removed.'));
    }

    #[Computed]
    public function vendors()
    {
        $query = Contact::query()->where('is_vendor', true)->where('is_active', true);

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
        return Item::query()
            ->where('is_active', true)
            ->with('category:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'item_category_id'])
            ->map(fn (Item $i): array => [
                'id' => $i->id,
                'name' => $i->name,
                'sku' => $i->sku,
                'category' => $i->category?->name,
            ])
            ->values();
    }

    #[Computed]
    public function expenseAccountOptions()
    {
        return Account::query()
            ->whereIn('type', [AccountType::Expense->value, AccountType::Asset->value, AccountType::Liability->value])
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function taxCodeOptions()
    {
        $lineTaxCodeIds = collect($this->lines)->pluck('tax_code_id')->filter()->all();

        return TaxCode::query()
            ->where(function ($q) use ($lineTaxCodeIds) {
                $q->where(fn ($inner) => $inner->where('is_active', true)->forPurchases());

                if ($lineTaxCodeIds !== []) {
                    $q->orWhereIn('id', $lineTaxCodeIds);
                }
            })
            ->orderBy('code')
            ->get();
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
    <flux:heading size="xl" level="1" class="mb-6">{{ $bill?->id ? __('Edit bill') : __('New bill') }}</flux:heading>

    <form wire:submit="postBill" class="space-y-6">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <x-contact-combo
                :label="__('Vendor')"
                :placeholder="__('Search or type to add a new vendor…')"
                :add-label="__('vendor')"
                :options="$this->vendors"
                :selected-id="$contact_id"
                :selected-name="$this->selectedContactName"
                :query="$contact_query"
                :creating="$contact_creating"
                :new-name="$new_contact_name"
                data-test="bill-vendor-combo"
            />

            <flux:input wire:model="bill_no" :label="__('Bill #')" required data-test="bill-no-input" />
            <flux:input wire:model="vendor_reference" :label="__('Vendor reference')" placeholder="{{ __('Their invoice #') }}" data-test="bill-vendor-ref-input" />

            <flux:select wire:model.live="terms_id" :label="__('Terms')">
                <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                @foreach ($this->termsOptions as $t)
                    <flux:select.option :value="$t->id">{{ $t->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input type="date" wire:model.live="bill_date" :label="__('Bill date')" required data-test="bill-date-input" />
            <flux:input type="date" wire:model="due_date" :label="__('Due date')" required data-test="bill-due-date-input" />
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
                        <th class="px-2 py-2 text-right w-28">{{ __('Unit cost') }}</th>
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
                        <tr wire:key="line-{{ $i }}" data-test="bill-line-row" class="block border-b border-border p-3 lg:table-row lg:border-0 lg:p-0">
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
                                    @foreach ($this->expenseAccountOptions as $opt)
                                        <flux:select.option :value="$opt->id">{{ $opt->code }} — {{ $opt->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </td>
                            <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Qty') }}</span>
                                <flux:input wire:model.live="lines.{{ $i }}.quantity" class="lg:text-right" data-test="line-qty" />
                            </td>
                            <td class="block px-2 py-1 lg:table-cell lg:py-2">
                                <span class="mb-1 block text-xs font-medium text-muted-foreground lg:hidden">{{ __('Unit cost') }}</span>
                                <x-amount-input model="lines.{{ $i }}.unit_price" class="lg:text-right" data-test="line-unit-price" />
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
                                <x-amount-input model="lines.{{ $i }}.tax_override" modifiers=".live.debounce.500ms" size="sm"
                                    class="mt-1 lg:text-right"
                                    placeholder="{{ number_format($line['auto_tax'] / 100, 2) }}"
                                    data-test="line-tax-override" />
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
                        <td colspan="{{ 7 + $this->dimensionColumns }}" class="px-2 py-2 text-right font-medium">{{ __('Subtotal') }}</td>
                        <td class="px-2 py-2 text-right font-mono" data-test="bill-subtotal">{{ number_format($this->totals['subtotal'] / 100, 2) }}</td>
                        <td></td>
                    </tr>
                    @forelse ($this->taxBreakdown as $taxRow)
                        <tr data-test="bill-tax-row">
                            <td colspan="{{ 7 + $this->dimensionColumns }}" class="px-2 py-2 text-right font-medium">{{ $taxRow['label'] }} {{ number_format($taxRow['rate'], 2) }}%</td>
                            <td class="px-2 py-2 text-right font-mono">{{ number_format($taxRow['tax_cents'] / 100, 2) }}</td>
                            <td></td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ 7 + $this->dimensionColumns }}" class="px-2 py-2 text-right font-medium">{{ __('Tax') }}</td>
                            <td class="px-2 py-2 text-right font-mono" data-test="bill-tax">{{ number_format($this->totals['tax'] / 100, 2) }}</td>
                            <td></td>
                        </tr>
                    @endforelse
                    <tr class="text-base">
                        <td colspan="{{ 7 + $this->dimensionColumns }}" class="px-2 py-2 text-right font-semibold">{{ __('Total') }}</td>
                        <td class="px-2 py-2 text-right font-mono font-semibold" data-test="bill-total">{{ number_format($this->totals['total'] / 100, 2) }}</td>
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

        {{-- Supporting documents --}}
        <div class="space-y-3 rounded-lg border border-border p-4" data-test="bill-attachments">
            <flux:heading size="sm">{{ __('Attachments') }}</flux:heading>

            @foreach ($this->attachments as $att)
                <div class="flex items-center justify-between rounded-md border border-border px-3 py-2" wire:key="bill-att-{{ $att->id }}" data-test="bill-attachment-row">
                    <x-attachment-link :attachment="$att" :company="$company" />
                    <flux:button variant="ghost" size="sm" icon="x-mark"
                        wire:click="removeAttachment({{ $att->id }})"
                        wire:confirm="{{ __('Remove this attachment?') }}"
                        data-test="bill-attachment-remove" />
                </div>
            @endforeach

            <x-attachment-dropzone model="newAttachments"
                accept=".pdf,image/*,.doc,.docx,.xls,.xlsx"
                :description="__('PDF, images, or Office docs up to 10 MB each.')"
                data-test="bill-attachment-input" />

            @error('newAttachments.*') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror

            @if (count($newAttachments) > 0)
                @if ($bill?->exists)
                    <flux:button variant="filled" wire:click="uploadAttachments" data-test="bill-attachment-upload">
                        {{ __('Upload :count file(s)', ['count' => count($newAttachments)]) }}
                    </flux:button>
                @else
                    <flux:text class="text-sm text-muted-foreground">{{ __(':count file(s) will be attached when you save.', ['count' => count($newAttachments)]) }}</flux:text>
                @endif
            @endif
        </div>

        <div class="flex items-center justify-between">
            <flux:button variant="filled" type="button" icon="plus" wire:click="addLine">{{ __('Add line') }}</flux:button>

            <div class="flex gap-2">
                @if ($bill?->journal_entry_id)
                    <flux:button variant="primary" type="submit" data-test="post-bill-button">{{ __('Save changes') }}</flux:button>
                @else
                    <flux:button variant="filled" type="button" wire:click="saveDraft" data-test="save-draft-button">{{ __('Save draft') }}</flux:button>
                    <flux:button variant="primary" type="submit" data-test="post-bill-button">{{ __('Post bill') }}</flux:button>
                @endif
            </div>
        </div>
    </form>

    <flux:modal name="duplicate-bill-warning" class="max-w-md">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Possible duplicate bill') }}</flux:heading>
            <flux:text>
                {{ __('This supplier already has a bill with reference “:ref”. Record this one anyway?', ['ref' => $vendor_reference]) }}
            </flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="confirmDuplicateBillNo" data-test="confirm-duplicate-bill">
                    {{ __('Save anyway') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</section>
