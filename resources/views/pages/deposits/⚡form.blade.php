<?php

use App\Actions\Banking\SaveDeposit;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\DepositStatus;
use App\Exceptions\Posting\PeriodLockedException;
use App\Livewire\Concerns\GuardsEditLockedForm;
use App\Models\Account;
use App\Models\Classification;
use App\Models\Company;
use App\Models\CustomerReceipt;
use App\Models\Deposit;
use App\Models\DepositLine;
use App\Models\Location;
use App\Models\SalesReceipt;
use App\Rules\MoneyString;
use App\Services\Posting\DepositPoster;
use App\Services\Posting\DocumentNumberGenerator;
use App\Services\Reporting\CsvExporter;
use App\Services\Reporting\PdfExporter;
use App\Services\Reporting\XlsxExporter;
use App\Support\Banking\LastBankAccount;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Make deposit')] class extends Component
{
    use GuardsEditLockedForm;

    public Company $company;

    public ?Deposit $deposit = null;

    public ?int $bank_account_id = null;

    public string $deposit_no = '';

    public string $deposit_date = '';

    public string $memo = '';

    #[Url(as: 'from')]
    public ?int $duplicateFromId = null;

    /**
     * Receipts available for deposit: keyed by receipt_id → include
     *
     * @var array<int, array{source: string, receipt_id: int, date: string, receipt_no: string, contact: string, payment_method: ?string, reference: ?string, amount: int, included: bool}>
     */
    public array $availableReceipts = [];

    /** Column the receipt picker is sorted by; one of self::SORT_FIELDS. */
    public string $sortField = 'date';

    public string $sortDir = 'asc';

    /**
     * $availableReceipts keys in display order. Sorting reorders THIS, never the
     * bound array: each checkbox's wire:model carries its row's array index, and
     * that expression is compiled once when the row's DOM node is created — so
     * re-indexing the array under a moved row would point its checkbox at a
     * different receipt.
     *
     * @var array<int, int>
     */
    public array $receiptOrder = [];

    private const SORT_FIELDS = ['included', 'date', 'receipt_no', 'contact', 'payment_method', 'reference', 'amount'];

    /**
     * "Other" deposit lines (e.g. owner contribution, refund)
     *
     * @var array<int, array{account_id: ?int, contact_id: ?int, description: string, amount: string}>
     */
    public array $otherLines = [];

    protected function editLockRecord(): ?Model
    {
        return $this->deposit;
    }

    public function mount(Company $company, ?Deposit $deposit = null): void
    {
        $this->company = $company;

        if ($deposit && $deposit->exists) {
            abort_if($deposit->status === DepositStatus::Void, 403);

            $this->deposit = $deposit->load('lines.customerReceipt', 'lines.salesReceipt');
            $this->bank_account_id = $deposit->bank_account_id;
            $this->deposit_no = $deposit->deposit_no;
            $this->deposit_date = $deposit->deposit_date->toDateString();
            $this->memo = $deposit->memo ?? '';
            $this->otherLines = $this->mapOtherLines($deposit);

            $this->loadAvailableReceipts();

            return;
        }

        $this->deposit_date = $this->company->currentDateTime()->toDateString();
        $this->bank_account_id = $this->defaultBankAccountId();
        $this->deposit_no = app(DocumentNumberGenerator::class)->next($company, Deposit::class, 'deposit_no', 'DEP');

        $this->loadAvailableReceipts();

        if ($this->duplicateFromId) {
            $this->prefillFrom($this->duplicateFromId);
        }
    }

    /**
     * Copy a source deposit's bank, memo and "other" lines into a fresh, unsaved
     * draft. Receipt-source lines are skipped — those receipts are already
     * deposited — and the new draft keeps today's date and generated number.
     * Ignores a source from another company.
     */
    protected function prefillFrom(int $sourceId): void
    {
        $source = Deposit::query()
            ->where('company_id', $this->company->id)
            ->with('lines')
            ->find($sourceId);

        if (! $source) {
            return;
        }

        $this->bank_account_id = $source->bank_account_id;
        $this->memo = $source->memo ?? '';
        $this->otherLines = $this->mapOtherLines($source);
    }

    /**
     * Map a deposit's non-receipt ("other") lines into the editable line shape.
     *
     * @return array<int, array{account_id: ?int, contact_id: ?int, description: string, amount: string, class_id: ?int, location_id: ?int}>
     */
    protected function mapOtherLines(Deposit $deposit): array
    {
        return $deposit->lines
            ->reject(fn (DepositLine $line) => $line->isReceiptSource())
            ->map(fn (DepositLine $line) => [
                'account_id' => $line->account_id,
                'contact_id' => $line->contact_id,
                'description' => $line->description ?? '',
                'amount' => number_format($line->amount_cents / 100, 2, '.', ''),
                'class_id' => $line->class_id,
                'location_id' => $line->location_id,
            ])->values()->all();
    }

    /**
     * Receipts parked in Undeposited Funds AND not already on a posted/draft
     * deposit — both Receive-Payment receipts and pay-now Sales Receipts.
     */
    protected function loadAvailableReceipts(): void
    {
        $undep = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->where('is_active', true)->first();

        if (! $undep) {
            return;
        }

        // Receipts already locked into OTHER draft/posted deposits are excluded;
        // when editing, this deposit's own receipts stay selectable + pre-checked.
        $depositedIds = fn (string $column) => DepositLine::query()
            ->whereHas('deposit', function ($q) {
                $q->whereIn('status', ['draft', 'posted']);

                if ($this->deposit) {
                    $q->whereKeyNot($this->deposit->id);
                }
            })
            ->whereNotNull($column)
            ->pluck($column)
            ->all();

        $rows = collect();

        // --- Customer receipts (Receive Payment) ---
        $currentCustomerIds = $this->deposit
            ? $this->deposit->lines->whereNotNull('customer_receipt_id')->pluck('customer_receipt_id')->map(fn ($id) => (int) $id)->all()
            : [];

        CustomerReceipt::query()
            ->with(['contact', 'paymentMethod'])
            ->where('deposit_to_account_id', $undep->id)
            ->where('status', 'posted')
            ->whereNotIn('id', $depositedIds('customer_receipt_id'))
            ->orderBy('receipt_date')
            ->get()
            ->each(fn ($r) => $rows->push([
                'source' => 'customer',
                'receipt_id' => $r->id,
                'date' => $r->receipt_date->toDateString(),
                'receipt_no' => $r->receipt_no,
                'contact' => $r->contact?->display_name ?? '—',
                'payment_method' => $r->paymentMethod?->name,
                'reference' => $r->reference,
                'amount' => (int) $r->amount_cents,
                'included' => $this->deposit !== null && in_array($r->id, $currentCustomerIds, true),
            ]));

        // --- Pay-now Sales Receipts ---
        $currentSalesIds = $this->deposit
            ? $this->deposit->lines->whereNotNull('sales_receipt_id')->pluck('sales_receipt_id')->map(fn ($id) => (int) $id)->all()
            : [];

        SalesReceipt::query()
            ->with(['contact', 'paymentMethod'])
            ->where('deposit_to_account_id', $undep->id)
            ->where('status', 'posted')
            ->whereNotIn('id', $depositedIds('sales_receipt_id'))
            ->orderBy('receipt_date')
            ->get()
            ->each(fn ($r) => $rows->push([
                'source' => 'sales',
                'receipt_id' => $r->id,
                'date' => $r->receipt_date->toDateString(),
                'receipt_no' => $r->sales_receipt_no,
                'contact' => $r->contact?->display_name ?? __('Cash sale'),
                'payment_method' => $r->paymentMethod?->name,
                'reference' => $r->reference,
                'amount' => (int) $r->total_cents,
                'included' => $this->deposit !== null && in_array($r->id, $currentSalesIds, true),
            ]));

        $this->availableReceipts = $rows->values()->all();

        $this->applySort();
    }

    /**
     * Re-order the picker by the clicked column, flipping direction when the
     * same column is clicked twice. The rows are sorted in place (rather than in
     * the view) so each row's `included` binding keeps pointing at its own row.
     */
    public function sortBy(string $field): void
    {
        if (! in_array($field, self::SORT_FIELDS, true)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDir = 'asc';
        }

        $this->applySort();
    }

    protected function applySort(): void
    {
        $this->receiptOrder = collect($this->availableReceipts)
            ->sortBy(fn (array $r) => match ($this->sortField) {
                'included' => (int) $r['included'],
                'amount' => (int) $r['amount'],
                'receipt_no' => mb_strtolower((string) ($r['receipt_no'] ?? '')),
                'contact' => mb_strtolower((string) ($r['contact'] ?? '')),
                'payment_method' => mb_strtolower((string) ($r['payment_method'] ?? '')),
                'reference' => mb_strtolower((string) ($r['reference'] ?? '')),
                default => (string) $r['date'],
            }, SORT_REGULAR, $this->sortDir === 'desc')
            ->keys()
            ->map(fn ($i) => (int) $i)
            ->all();
    }

    /** True only when there is at least one receipt and every one is ticked. */
    #[Computed]
    public function allReceiptsSelected(): bool
    {
        return $this->availableReceipts !== []
            && collect($this->availableReceipts)->every(fn (array $r) => (bool) $r['included']);
    }

    /** Header checkbox: tick every receipt, or clear them all when all are ticked. */
    public function toggleAllReceipts(): void
    {
        $include = ! $this->allReceiptsSelected;

        foreach (array_keys($this->availableReceipts) as $i) {
            $this->availableReceipts[$i]['included'] = $include;
        }

        unset($this->allReceiptsSelected);
    }

    /**
     * The picker's rows in the order they are displayed. Every export reads
     * this, so a downloaded copy matches the sort the operator is looking at
     * — and carries the tick state, which is what makes it a deposit slip.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function orderedReceipts(): Collection
    {
        $rows = [];

        foreach ($this->receiptOrder as $i) {
            if (isset($this->availableReceipts[$i])) {
                $rows[] = $this->availableReceipts[$i];
            }
        }

        return collect($rows);
    }

    /** Export filename, dated by the deposit being prepared. */
    protected function exportFilename(string $extension): string
    {
        $date = $this->deposit_date !== '' ? $this->deposit_date : $this->company->currentDateTime()->toDateString();

        return 'undeposited-receipts-'.$date.'.'.$extension;
    }

    /** @return array<int, string> */
    protected function exportHeaders(): array
    {
        return [__('Selected'), __('Date'), __('Receipt #'), __('From'), __('Payment type'), __('Ref'), __('Amount')];
    }

    /** Bank and selection summary printed under the title on XLSX/PDF. */
    protected function exportMetaLines(): array
    {
        $rows = $this->orderedReceipts();
        $selected = $rows->where('included', true);
        $bank = $this->bankAccounts->firstWhere('id', (int) $this->bank_account_id);

        return array_values(array_filter([
            $bank ? __('Deposit to: :account', ['account' => $bank->code.' — '.$bank->name]) : null,
            __(':selected of :total selected · :amount', [
                'selected' => $selected->count(),
                'total' => $rows->count(),
                'amount' => number_format((int) $selected->sum('amount') / 100, 2),
            ]),
        ]));
    }

    public function exportCsv()
    {
        $rows = $this->orderedReceipts();

        return app(CsvExporter::class)->stream(
            $this->exportFilename('csv'),
            $this->exportHeaders(),
            $rows->map(fn (array $r) => [
                $r['included'] ? __('Yes') : __('No'),
                $r['date'],
                $r['receipt_no'],
                $r['contact'],
                $r['payment_method'] ?? '',
                $r['reference'] ?? '',
                CsvExporter::cents((int) $r['amount']),
            ])->push(['', '', '', '', '', __('TOTAL'), CsvExporter::cents((int) $rows->sum('amount'))]),
        );
    }

    public function exportXlsx()
    {
        $rows = $this->orderedReceipts();

        return app(XlsxExporter::class)->listTable(
            $this->exportFilename('xlsx'),
            'Undeposited receipts',
            __('Undeposited receipts'),
            $this->company,
            $this->exportMetaLines(),
            $this->exportHeaders(),
            $rows->map(fn (array $r) => [
                $r['included'] ? __('Yes') : __('No'),
                $r['date'],
                $r['receipt_no'],
                $r['contact'],
                $r['payment_method'] ?? '',
                $r['reference'] ?? '',
                (int) $r['amount'],
            ])->all(),
            moneyColumns: [7],
            columnWidths: [1 => 10, 2 => 12, 3 => 18, 4 => 32, 5 => 18, 6 => 16, 7 => 14],
            totals: ['', '', '', '', '', __('TOTAL'), (int) $rows->sum('amount')],
        );
    }

    public function exportPdf()
    {
        $rows = $this->orderedReceipts();

        return app(PdfExporter::class)->download('pdf.reports.list-table', [
            'company' => $this->company,
            'title' => __('Undeposited receipts'),
            'period' => __('as of :date', ['date' => $this->deposit_date]),
            'metaLines' => $this->exportMetaLines(),
            'headers' => [
                ['label' => __('Selected')],
                ['label' => __('Date')],
                ['label' => __('Receipt #')],
                ['label' => __('From')],
                ['label' => __('Payment type')],
                ['label' => __('Ref')],
                ['label' => __('Amount'), 'num' => true],
            ],
            'rows' => $rows->map(fn (array $r) => [
                ['value' => $r['included'] ? __('Yes') : __('No')],
                ['value' => $r['date']],
                ['value' => $r['receipt_no']],
                ['value' => $r['contact']],
                ['value' => $r['payment_method'] ?? '—'],
                ['value' => $r['reference'] ?? ''],
                ['value' => number_format((int) $r['amount'] / 100, 2), 'num' => true],
            ])->all(),
            'totals' => [
                ['value' => ''], ['value' => ''], ['value' => ''], ['value' => ''], ['value' => ''],
                ['value' => __('TOTAL')],
                ['value' => number_format((int) $rows->sum('amount') / 100, 2), 'num' => true],
            ],
            'emptyMessage' => __('No undeposited receipts.'),
        ], $this->exportFilename('pdf'));
    }

    public function addOtherLine(): void
    {
        $this->otherLines[] = [
            'account_id' => null,
            'contact_id' => null,
            'description' => '',
            'amount' => '0.00',
            'class_id' => null,
            'location_id' => null,
        ];
    }

    public function removeOtherLine(int $i): void
    {
        unset($this->otherLines[$i]);
        $this->otherLines = array_values($this->otherLines);
    }

    /**
     * Validate the form and build the SaveDeposit payload, or null after adding a
     * validation error. Shared by save() (posts) and saveDraft() (leaves unposted).
     *
     * @return array<string, mixed>|null
     */
    protected function prepareDepositData(): ?array
    {
        $companyId = $this->company->id;

        $validated = $this->validate([
            'bank_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('company_id', $companyId)->where('subtype', AccountSubtype::Bank->value)],
            'deposit_no' => [
                'required', 'string', 'max:40',
                Rule::unique('deposits', 'deposit_no')
                    ->where('company_id', $companyId)
                    ->ignore($this->deposit?->id),
            ],
            'deposit_date' => ['required', 'date'],
            'memo' => ['nullable', 'string'],
            'otherLines.*.account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('company_id', $companyId)],
            'otherLines.*.amount' => ['nullable', 'string', new MoneyString],
            'otherLines.*.class_id' => ['nullable', 'integer', Rule::exists('classifications', 'id')->where('company_id', $companyId)],
            'otherLines.*.location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')->where('company_id', $companyId)],
        ]);

        // Validate at least one included line
        $included = collect($this->availableReceipts)->where('included', true);
        $otherWithAmount = collect($this->otherLines)->filter(function ($l) {
            try {
                // Non-zero: positive = added funds, negative = a fee/deduction.
                return Money::fromString($l['amount'] === '' ? '0' : $l['amount'])->cents !== 0;
            } catch (Throwable) {
                return false;
            }
        });

        if ($included->isEmpty() && $otherWithAmount->isEmpty()) {
            $this->addError('deposit', __('Select at least one receipt or add an "other" line.'));

            return null;
        }

        foreach ($otherWithAmount as $line) {
            if (! $line['account_id']) {
                $this->addError('otherLines', __('Account required for other deposit lines.'));

                return null;
            }
        }

        $lines = [];

        foreach ($included as $row) {
            if (($row['source'] ?? 'customer') === 'sales') {
                $lines[] = [
                    'sales_receipt_id' => $row['receipt_id'],
                    'description' => 'Sales receipt '.$row['receipt_no'].' — '.$row['contact'],
                    'amount_cents' => $row['amount'],
                ];

                continue;
            }

            $lines[] = [
                'customer_receipt_id' => $row['receipt_id'],
                'description' => 'Receipt '.$row['receipt_no'].' — '.$row['contact'],
                'amount_cents' => $row['amount'],
            ];
        }

        foreach ($otherWithAmount as $line) {
            $lines[] = [
                'account_id' => $line['account_id'],
                'contact_id' => $line['contact_id'] ?: null,
                'description' => $line['description'] ?: null,
                'amount_cents' => Money::fromString($line['amount'])->cents,
                'class_id' => $line['class_id'] ?? null,
                'location_id' => $line['location_id'] ?? null,
            ];
        }

        return [
            'bank_account_id' => $validated['bank_account_id'],
            'deposit_no' => $validated['deposit_no'],
            'deposit_date' => $validated['deposit_date'],
            'memo' => $validated['memo'] ?: null,
            'lines' => $lines,
        ];
    }

    public function save(DepositPoster $poster): void
    {
        $data = $this->prepareDepositData();

        if ($data === null) {
            return;
        }

        $wasPosted = $this->deposit?->journal_entry_id !== null;

        // Save the deposit and (re)post its GL entry in one transaction so a lock
        // failure on repost can't leave the deposit lines out of sync with the ledger.
        try {
            $deposit = DB::transaction(function () use ($data, $poster, $wasPosted): Deposit {
                $deposit = app(SaveDeposit::class)->handle($data, $this->deposit);

                $wasPosted ? $poster->repost($deposit) : $poster->post($deposit);

                return $deposit;
            });
        } catch (PeriodLockedException|RuntimeException $e) {
            $this->addError('deposit', $e->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: $wasPosted ? __('Deposit updated.') : __('Deposit posted.'));
        $this->redirectRoute('deposits.show', ['company' => $this->company->slug, 'deposit' => $deposit->id], navigate: true);
    }

    /**
     * Save the deposit slip without posting it to the GL — leaves it in Draft so
     * it can be reviewed and posted later from the deposit page.
     */
    public function saveDraft(): void
    {
        if ($this->deposit?->journal_entry_id) {
            $this->addError('deposit', __('This deposit is already posted. Use Save changes to update it.'));

            return;
        }

        $data = $this->prepareDepositData();

        if ($data === null) {
            return;
        }

        $deposit = app(SaveDeposit::class)->handle($data, $this->deposit);

        Flux::toast(variant: 'success', text: __('Draft saved.'));
        $this->redirectRoute('deposits.show', ['company' => $this->company->slug, 'deposit' => $deposit->id], navigate: true);
    }

    /**
     * Default "Deposit to" for a new deposit: the account this operator last
     * worked in anywhere in Banking, else the bank account from the most recent
     * deposit, else the lowest-code active bank account.
     */
    protected function defaultBankAccountId(): ?int
    {
        $activeBankIds = $this->bankAccounts->pluck('id');

        if ($remembered = LastBankAccount::recall($this->company, $activeBankIds)) {
            return $remembered;
        }

        $lastUsed = Deposit::query()
            ->whereNotNull('bank_account_id')
            ->latest('id')
            ->value('bank_account_id');

        return $activeBankIds->contains($lastUsed) ? (int) $lastUsed : $activeBankIds->first();
    }

    public function updatedBankAccountId(): void
    {
        LastBankAccount::remember($this->company, $this->bank_account_id);
    }

    #[Computed]
    public function bankAccounts()
    {
        return Account::query()->where('subtype', AccountSubtype::Bank->value)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function otherAccountOptions()
    {
        return Account::query()
            ->whereIn('type', [AccountType::Income->value, AccountType::Equity->value, AccountType::Liability->value, AccountType::Asset->value])
            ->where('id', '!=', $this->bank_account_id)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
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

    public function totalCents(): int
    {
        $sum = 0;

        foreach ($this->availableReceipts as $r) {
            if ($r['included']) {
                $sum += $r['amount'];
            }
        }

        foreach ($this->otherLines as $l) {
            try {
                $sum += Money::fromString($l['amount'] === '' ? '0' : $l['amount'])->cents;
            } catch (Throwable) {
            }
        }

        return $sum;
    }
}; ?>

<section class="w-full">
    @if ($editLockBlocked) <x-edit-lock.blocked :lock="$this->editLockView" /> @else
    <x-edit-lock.status :lock="$this->editLockView" />

    <flux:heading size="xl" level="1" class="mb-6">{{ $deposit ? __('Edit deposit') : __('Make deposit') }}</flux:heading>

    <form wire:submit="save" class="space-y-6">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <flux:select wire:model="bank_account_id" :label="__('Deposit to')" required data-test="deposit-bank-select">
                <flux:select.option value="">{{ __('— Select bank —') }}</flux:select.option>
                @foreach ($this->bankAccounts as $opt)
                    <flux:select.option :value="$opt->id">{{ $opt->code }} — {{ $opt->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="deposit_no" :label="__('Deposit #')" required data-test="deposit-no-input" />
            <flux:input type="date" wire:model="deposit_date" :label="__('Date')" required />
        </div>

        <flux:input wire:model="memo" :label="__('Memo')" />

        <div>
            <div class="mb-2 flex items-center justify-between gap-2">
                <flux:heading>{{ __('Undeposited receipts') }}</flux:heading>

                @if (! empty($availableReceipts))
                    <flux:dropdown align="end">
                        <flux:button size="sm" variant="filled" icon="arrow-down-tray" icon:trailing="chevron-down" data-test="export-receipts-menu">
                            {{ __('Export') }}
                        </flux:button>
                        <flux:menu>
                            <flux:menu.item icon="document" wire:click="exportPdf" data-test="export-receipts-pdf">{{ __('PDF') }}</flux:menu.item>
                            <flux:menu.item icon="document-text" wire:click="exportCsv" data-test="export-receipts-csv">{{ __('CSV') }}</flux:menu.item>
                            <flux:menu.item icon="table-cells" wire:click="exportXlsx" data-test="export-receipts-xlsx">{{ __('Excel') }}</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                @endif
            </div>

            @if (empty($availableReceipts))
                <flux:text class="py-4 text-center text-muted-foreground">{{ __('No undeposited receipts.') }}</flux:text>
            @else
                <div class="overflow-x-auto rounded-lg border border-border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted">
                            <tr>
                                <th class="px-3 py-2">
                                    <flux:checkbox
                                        wire:click="toggleAllReceipts"
                                        :checked="$this->allReceiptsSelected"
                                        :aria-label="__('Select all receipts')"
                                        data-test="select-all-receipts"
                                    />
                                </th>
                                <th class="px-3 py-2 text-left"><x-sort-header field="date" :current-field="$sortField" :current-dir="$sortDir" :label="__('Date')" /></th>
                                <th class="px-3 py-2 text-left"><x-sort-header field="receipt_no" :current-field="$sortField" :current-dir="$sortDir" :label="__('Receipt #')" /></th>
                                <th class="px-3 py-2 text-left"><x-sort-header field="contact" :current-field="$sortField" :current-dir="$sortDir" :label="__('From')" /></th>
                                <th class="px-3 py-2 text-left"><x-sort-header field="payment_method" :current-field="$sortField" :current-dir="$sortDir" :label="__('Payment type')" /></th>
                                <th class="px-3 py-2 text-left"><x-sort-header field="reference" :current-field="$sortField" :current-dir="$sortDir" :label="__('Ref')" /></th>
                                <th class="px-3 py-2 text-right"><x-sort-header field="amount" :current-field="$sortField" :current-dir="$sortDir" :label="__('Amount')" align="right" /></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($receiptOrder as $i)
                                @php($r = $availableReceipts[$i] ?? null)
                                @continue($r === null)
                                <tr wire:key="receipt-{{ $r['source'] }}-{{ $r['receipt_id'] }}" data-test="receipt-pick-row">
                                    <td class="px-3 py-2"><flux:checkbox wire:model.live="availableReceipts.{{ $i }}.included" data-test="receipt-pick-check" /></td>
                                    <td class="px-3 py-2 whitespace-nowrap">{{ $r['date'] }}</td>
                                    <td class="px-3 py-2 font-mono">
                                        <a
                                            href="{{ route($r['source'] === 'sales' ? 'sales-receipts.edit' : 'receipts.edit', ['company' => $company->slug, 'receipt' => $r['receipt_id']]) }}"
                                            target="_blank"
                                            class="underline decoration-dotted underline-offset-2 hover:decoration-solid"
                                            title="{{ __('Edit this receipt in a new tab') }}"
                                            data-test="receipt-pick-link"
                                        >{{ $r['receipt_no'] }}</a>
                                    </td>
                                    <td class="px-3 py-2">{{ $r['contact'] }}</td>
                                    <td class="px-3 py-2 text-muted-foreground" data-test="receipt-pick-method">{{ $r['payment_method'] ?? '—' }}</td>
                                    <td class="px-3 py-2 text-muted-foreground">{{ $r['reference'] }}</td>
                                    <td class="px-3 py-2 text-right font-mono">{{ number_format($r['amount'] / 100, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div>
            <div class="mb-2 flex items-center justify-between">
                <div>
                    <flux:heading>{{ __('Other deposits') }}</flux:heading>
                    <flux:text class="text-xs text-muted-foreground">{{ __('Add owner contributions or refunds; enter a negative amount to net out a bank/merchant fee.') }}</flux:text>
                </div>
                <flux:button variant="filled" size="sm" icon="plus" type="button" wire:click="addOtherLine">{{ __('Add line') }}</flux:button>
            </div>

            @if (! empty($otherLines))
                <div class="overflow-x-auto rounded-lg border border-border">
                    <table class="w-full text-sm" x-resizable-columns="'deposit-other-lines'" wire:ignore.self>
                        <thead class="bg-muted">
                            <tr>
                                <th class="relative px-3 py-2 text-left w-72" data-col="account" data-col-min="120" wire:ignore.self>{{ __('Account') }}<x-col-resize-handle /></th>
                                <th class="px-3 py-2 text-left" data-col-flex>{{ __('Description') }}</th>
                                @if ($this->tracksClasses)
                                    <th class="relative px-3 py-2 text-left w-40" data-col="class" data-col-min="80" wire:ignore.self><x-col-resize-handle edge="start" />{{ __('Class') }}</th>
                                @endif
                                @if ($this->tracksLocations)
                                    <th class="relative px-3 py-2 text-left w-40" data-col="location" data-col-min="80" wire:ignore.self><x-col-resize-handle edge="start" />{{ __('Location') }}</th>
                                @endif
                                <th class="relative px-3 py-2 text-right w-28" data-col="amount" data-col-min="88" wire:ignore.self><x-col-resize-handle edge="start" />{{ __('Amount') }}</th>
                                <th class="px-3 py-2 w-14"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <x-account-combo.options key="otherAccounts" :options="$this->otherAccountOptions" />
                            @foreach ($otherLines as $i => $line)
                                <tr wire:key="other-{{ $i }}" data-test="other-line-row">
                                    <td class="px-3 py-2">
                                        <x-account-combo model="otherLines.{{ $i }}.account_id" options="otherAccounts" :live="false" data-test="other-line-account" />
                                    </td>
                                    <td class="px-3 py-2"><flux:input wire:model="otherLines.{{ $i }}.description" /></td>
                                    @if ($this->tracksClasses)
                                        <td class="px-3 py-2">
                                            <flux:select wire:model="otherLines.{{ $i }}.class_id" data-test="other-line-class">
                                                <flux:select.option value="">{{ __('—') }}</flux:select.option>
                                                @foreach ($this->classificationOptions as $opt)
                                                    <flux:select.option :value="$opt->id">{{ $opt->name }}</flux:select.option>
                                                @endforeach
                                            </flux:select>
                                        </td>
                                    @endif
                                    @if ($this->tracksLocations)
                                        <td class="px-3 py-2">
                                            <flux:select wire:model="otherLines.{{ $i }}.location_id" data-test="other-line-location">
                                                <flux:select.option value="">{{ __('—') }}</flux:select.option>
                                                @foreach ($this->locationOptions as $opt)
                                                    <flux:select.option :value="$opt->id">{{ $opt->name }}</flux:select.option>
                                                @endforeach
                                            </flux:select>
                                        </td>
                                    @endif
                                    <td class="px-3 py-2"><x-amount-input model="otherLines.{{ $i }}.amount" class="text-right" data-test="other-amount" /></td>
                                    <td class="px-3 py-2 text-right">
                                        <flux:button variant="ghost" size="sm" icon="x-mark" type="button" wire:click="removeOtherLine({{ $i }})" />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="flex items-center justify-between rounded-lg bg-muted p-4">
            <span class="text-base font-semibold">{{ __('Deposit total') }}</span>
            <span class="text-lg font-mono font-semibold" data-test="deposit-total">{{ number_format($this->totalCents() / 100, 2) }}</span>
        </div>

        @error('deposit') <flux:text class="text-red-600">{{ $message }}</flux:text> @enderror

        <div class="flex justify-end gap-2">
            @if ($deposit?->journal_entry_id)
                <flux:button variant="primary" type="submit" data-test="save-deposit-button">{{ __('Save changes') }}</flux:button>
            @else
                <flux:button variant="filled" type="button" wire:click="saveDraft" data-test="save-deposit-draft-button">{{ __('Save draft') }}</flux:button>
                <flux:button variant="primary" type="submit" data-test="save-deposit-button">{{ __('Save & post') }}</flux:button>
            @endif
        </div>
    </form>
    @endif
</section>
