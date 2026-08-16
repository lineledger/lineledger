<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\BankReconciliationStatus;
use App\Enums\BankStatementFormat;
use App\Exceptions\Posting\ReconciliationOutOfBalanceException;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\BankReconciliation;
use App\Models\BillPayment;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\CustomerReceipt;
use App\Models\JournalLine;
use App\Services\AttachmentService;
use App\Services\Reconciliation\BankReconciliationService;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Reconcile')] class extends Component {
    use WithFileUploads;

    private const PAYMENT_SORT_FIELDS = ['date', 'entry', 'amount', 'payment_method'];

    private const DEPOSIT_SORT_FIELDS = ['date', 'entry', 'amount'];

    public Company $company;

    public ?int $account_id = null;

    /** @var array<int, mixed> */
    public array $newAttachments = [];

    /** Single statement dropped on the Begin form to auto-fill the ending balance + date. */
    public $statementForFill = null;

    public string $paymentsSortField = 'date';

    public string $paymentsSortDir = 'asc';

    public string $depositsSortField = 'date';

    public string $depositsSortDir = 'asc';

    // Begin / edit modal form fields
    public string $statementDate = '';

    public string $beginningBalance = '';

    public string $endingBalance = '';

    public string $serviceChargeAmount = '';

    public string $serviceChargeDate = '';

    public ?int $serviceChargeAccountId = null;

    public string $interestAmount = '';

    public string $interestDate = '';

    public ?int $interestAccountId = null;

    public function mount(Company $company): void
    {
        $this->company = $company;

        $requested = request('account');

        if ($requested) {
            $this->account_id = (int) $requested;
        } else {
            $first = Account::query()
                ->whereIn('subtype', [AccountSubtype::Bank->value, AccountSubtype::CreditCard->value])
                ->where('is_active', true)
                ->orderBy('code')
                ->first();
            $this->account_id = $first?->id;
        }

        $this->statementDate = $this->defaultStatementDate();
        $this->serviceChargeDate = $this->statementDate;
        $this->interestDate = $this->statementDate;

        $bankCharges = Account::query()->where('code', '6010')->first();
        $this->serviceChargeAccountId = $bankCharges?->id;

        $otherIncome = Account::query()
            ->where('subtype', AccountSubtype::OtherIncome->value)
            ->orderBy('code')
            ->first();
        $this->interestAccountId = $otherIncome?->id;
    }

    /**
     * Switching accounts re-derives the suggested statement date for the newly
     * selected account's last reconciliation.
     */
    public function updatedAccountId(): void
    {
        $this->statementDate = $this->defaultStatementDate();
        $this->serviceChargeDate = $this->statementDate;
        $this->interestDate = $this->statementDate;
    }

    /**
     * Suggest the next statement date: one month after the last completed
     * reconciliation on this account, or the current month-end when there is
     * no prior reconciliation to advance from.
     */
    protected function defaultStatementDate(): string
    {
        $last = $this->lastCompleted;

        if ($last) {
            return \Illuminate\Support\Carbon::parse($last->statement_date)
                ->addMonthNoOverflow()
                ->toDateString();
        }

        return $this->company->currentDateTime()->endOfMonth()->toDateString();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Account>
     */
    #[Computed]
    public function bankAccounts()
    {
        return Account::query()
            ->whereIn('subtype', [AccountSubtype::Bank->value, AccountSubtype::CreditCard->value])
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'subtype']);
    }

    #[Computed]
    public function account(): ?Account
    {
        return $this->account_id ? Account::query()->find($this->account_id) : null;
    }

    #[Computed]
    public function current(): ?BankReconciliation
    {
        if (! $this->account_id) {
            return null;
        }

        return BankReconciliation::query()
            ->forAccount($this->account_id)
            ->inProgress()
            ->first();
    }

    /**
     * @return \Illuminate\Support\Collection<int, BankReconciliation>
     */
    #[Computed]
    public function history()
    {
        if (! $this->account_id) {
            return collect();
        }

        return BankReconciliation::query()
            ->forAccount($this->account_id)
            ->completed()
            ->with('completedBy')
            ->orderByDesc('statement_date')
            ->orderByDesc('id')
            ->limit(20)
            ->get();
    }

    #[Computed]
    public function lastCompleted(): ?BankReconciliation
    {
        return $this->history->first();
    }

    public function beginningBalanceCents(): int
    {
        return $this->lastCompleted?->ending_balance_cents ?? 0;
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    #[Computed]
    public function expenseAccountOptions(): array
    {
        return Account::query()
            ->whereIn('type', [AccountType::Expense->value])
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Account $a) => ['value' => $a->id, 'label' => "{$a->code} — {$a->name}"])
            ->all();
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    #[Computed]
    public function incomeAccountOptions(): array
    {
        return Account::query()
            ->whereIn('type', [AccountType::Income->value])
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Account $a) => ['value' => $a->id, 'label' => "{$a->code} — {$a->name}"])
            ->all();
    }

    /**
     * Reads the ending balance and statement date off a dropped statement and
     * pre-fills the (still editable) Begin form fields — only when they're empty,
     * so a value the user already typed is never clobbered. The file is carried
     * into the reconciliation's attachments. Anything unreadable falls back to
     * manual entry.
     */
    public function updatedStatementForFill(): void
    {
        if ($this->statementForFill === null) {
            return;
        }

        $format = BankStatementFormat::tryFrom(strtolower((string) $this->statementForFill->getClientOriginalExtension()));

        if ($format === null) {
            $this->statementForFill = null;
            Flux::toast(variant: 'warning', text: __('Drop a PDF or OFX/QFX statement to auto-fill.'));

            return;
        }

        $meta = app(\App\Services\Banking\Import\StatementMetadataExtractor::class)
            ->extract($this->statementForFill->getRealPath(), $format);

        $filled = false;

        // The ending balance can be a number the user already tweaked — only fill it
        // when blank. The statement date is the statement's own closing date, so it
        // wins over the form's default.
        if ($this->endingBalance === '' && $meta['endBalanceCents'] !== null) {
            $this->endingBalance = number_format($meta['endBalanceCents'] / 100, 2, '.', '');
            $filled = true;
        }
        if ($meta['endDate'] !== null) {
            $this->statementDate = $meta['endDate']->toDateString();
            $filled = true;
        }

        // Keep the dropped statement so it attaches to the reconciliation on Begin.
        $this->newAttachments[] = $this->statementForFill;
        $this->statementForFill = null;

        Flux::toast(
            variant: $filled ? 'success' : 'warning',
            text: $filled
                ? __('Filled from your statement — please verify the values.')
                : __("Couldn't read the balance automatically — enter it manually."),
        );
    }

    public function startReconciliation(): void
    {
        if (! $this->account_id) {
            return;
        }

        $account = Account::query()->findOrFail($this->account_id);

        try {
            $endingCents = $this->endingBalance === '' ? 0 : Money::fromString($this->endingBalance)->cents;

            $serviceCharge = null;
            if ($this->serviceChargeAmount !== '' && Money::fromString($this->serviceChargeAmount)->cents > 0) {
                if (! $this->serviceChargeAccountId) {
                    Flux::toast(variant: 'danger', text: __('Pick an account for the service charge.'));

                    return;
                }
                $serviceCharge = [
                    'cents' => Money::fromString($this->serviceChargeAmount)->cents,
                    'date' => \Illuminate\Support\Carbon::parse($this->serviceChargeDate ?: $this->statementDate),
                    'account_id' => $this->serviceChargeAccountId,
                ];
            }

            $interestEarned = null;
            if ($this->interestAmount !== '' && Money::fromString($this->interestAmount)->cents > 0) {
                if (! $this->interestAccountId) {
                    Flux::toast(variant: 'danger', text: __('Pick an account for interest earned.'));

                    return;
                }
                $interestEarned = [
                    'cents' => Money::fromString($this->interestAmount)->cents,
                    'date' => \Illuminate\Support\Carbon::parse($this->interestDate ?: $this->statementDate),
                    'account_id' => $this->interestAccountId,
                ];
            }

            app(BankReconciliationService::class)->begin(
                $account,
                \Illuminate\Support\Carbon::parse($this->statementDate),
                $endingCents,
                $serviceCharge,
                $interestEarned,
            );

            Flux::modal('begin-reconciliation')->close();
            Flux::toast(variant: 'success', text: __('Reconciliation started.'));

            unset($this->current, $this->history, $this->lastCompleted);

            // Carry any statement dropped on the Begin form onto the new rec.
            if ($this->newAttachments !== [] && $this->current !== null) {
                app(AttachmentService::class)->upload($this->current, $this->newAttachments, Auth::id());
                $this->newAttachments = [];
                unset($this->attachments);
            }

            $this->resetBeginForm();
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }

    public function openEditDetails(): void
    {
        $rec = $this->current;

        if (! $rec) {
            return;
        }

        $this->statementDate = $rec->statement_date->toDateString();
        $this->beginningBalance = number_format($rec->beginning_balance_cents / 100, 2, '.', '');
        $this->endingBalance = number_format($rec->ending_balance_cents / 100, 2, '.', '');

        $this->serviceChargeAmount = $rec->service_charge_cents ? number_format($rec->service_charge_cents / 100, 2, '.', '') : '';
        $this->serviceChargeDate = ($rec->service_charge_date ?? $rec->statement_date)->toDateString();
        $this->serviceChargeAccountId = $rec->service_charge_account_id ?? $this->serviceChargeAccountId;

        $this->interestAmount = $rec->interest_earned_cents ? number_format($rec->interest_earned_cents / 100, 2, '.', '') : '';
        $this->interestDate = ($rec->interest_earned_date ?? $rec->statement_date)->toDateString();
        $this->interestAccountId = $rec->interest_earned_account_id ?? $this->interestAccountId;

        Flux::modal('edit-reconciliation')->show();
    }

    public function saveDetails(): void
    {
        $rec = $this->current;

        if (! $rec) {
            return;
        }

        try {
            $beginningCents = $this->beginningBalance === '' ? 0 : Money::fromString($this->beginningBalance)->cents;
            $endingCents = $this->endingBalance === '' ? 0 : Money::fromString($this->endingBalance)->cents;

            $serviceCharge = null;
            if ($this->serviceChargeAmount !== '' && Money::fromString($this->serviceChargeAmount)->cents > 0) {
                if (! $this->serviceChargeAccountId) {
                    Flux::toast(variant: 'danger', text: __('Pick an account for the service charge.'));

                    return;
                }
                $serviceCharge = [
                    'cents' => Money::fromString($this->serviceChargeAmount)->cents,
                    'date' => \Illuminate\Support\Carbon::parse($this->serviceChargeDate ?: $this->statementDate),
                    'account_id' => $this->serviceChargeAccountId,
                ];
            }

            $interestEarned = null;
            if ($this->interestAmount !== '' && Money::fromString($this->interestAmount)->cents > 0) {
                if (! $this->interestAccountId) {
                    Flux::toast(variant: 'danger', text: __('Pick an account for interest earned.'));

                    return;
                }
                $interestEarned = [
                    'cents' => Money::fromString($this->interestAmount)->cents,
                    'date' => \Illuminate\Support\Carbon::parse($this->interestDate ?: $this->statementDate),
                    'account_id' => $this->interestAccountId,
                ];
            }

            app(BankReconciliationService::class)->updateDetails(
                $rec,
                \Illuminate\Support\Carbon::parse($this->statementDate),
                $endingCents,
                $beginningCents,
                $serviceCharge,
                $interestEarned,
            );

            Flux::modal('edit-reconciliation')->close();
            Flux::toast(variant: 'success', text: __('Reconciliation details updated.'));

            unset($this->current);
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }

    public function toggleMark(int $lineId): void
    {
        if (! $this->current) {
            return;
        }

        try {
            app(BankReconciliationService::class)->toggleMark($this->current, $lineId);
            unset($this->current);
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }

    public function markAll(string $side): void
    {
        if (! $this->current) {
            return;
        }

        $rec = $this->current;
        $ids = collect($rec->markedLineIds());

        $lineIds = $this->availableLines($side)->pluck('id');

        $rec->forceFill(['marked_line_ids' => $ids->merge($lineIds)->unique()->values()->all()])->save();

        unset($this->current);
    }

    public function unmarkAll(string $side): void
    {
        if (! $this->current) {
            return;
        }

        $rec = $this->current;
        $remove = $this->availableLines($side)->pluck('id')->all();

        $remaining = array_values(array_diff($rec->markedLineIds(), $remove));

        $rec->forceFill(['marked_line_ids' => $remaining])->save();

        unset($this->current);
    }

    public function reconcileNow(AttachmentService $attachmentService): void
    {
        if (! $this->current) {
            return;
        }

        if ($this->newAttachments !== []) {
            $this->validate(AttachmentService::uploadRules());
        }

        try {
            app(BankReconciliationService::class)->complete($this->current, auth()->user());

            if ($this->newAttachments !== []) {
                $attachmentService->upload($this->current, $this->newAttachments, Auth::id());
                $this->newAttachments = [];
                unset($this->attachments);
            }

            Flux::toast(variant: 'success', text: __('Reconciliation complete.'));

            unset($this->current, $this->history, $this->lastCompleted);
            $this->resetBeginForm();
        } catch (ReconciliationOutOfBalanceException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, Attachment>
     */
    #[Computed]
    public function attachments()
    {
        return $this->current?->attachments()->get() ?? collect();
    }

    public function uploadAttachments(AttachmentService $service): void
    {
        if (! $this->current) {
            return;
        }

        $this->validate(AttachmentService::uploadRules());

        $service->upload($this->current, $this->newAttachments, Auth::id());

        $this->newAttachments = [];
        unset($this->attachments);

        Flux::toast(variant: 'success', text: __('Attachments uploaded.'));
    }

    public function removeAttachment(int $id, AttachmentService $service): void
    {
        if (! $this->current) {
            return;
        }

        $service->remove(Attachment::findOrFail($id), $this->current);

        unset($this->attachments);

        Flux::toast(variant: 'success', text: __('Attachment removed.'));
    }

    public function cancelReconciliation(): void
    {
        if (! $this->current) {
            return;
        }

        try {
            app(BankReconciliationService::class)->cancel($this->current);
            Flux::toast(variant: 'success', text: __('Reconciliation cancelled.'));
            unset($this->current, $this->history, $this->lastCompleted);
            $this->resetBeginForm();
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }

    public function undoLast(): void
    {
        $last = $this->lastCompleted;

        if (! $last) {
            return;
        }

        try {
            app(BankReconciliationService::class)->undo($last);
            Flux::toast(variant: 'success', text: __('Last reconciliation undone.'));
            unset($this->current, $this->history, $this->lastCompleted);
            $this->resetBeginForm();
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }

    public function sortPayments(string $field): void
    {
        $this->applySort($field, self::PAYMENT_SORT_FIELDS, 'paymentsSortField', 'paymentsSortDir');
    }

    public function sortDeposits(string $field): void
    {
        $this->applySort($field, self::DEPOSIT_SORT_FIELDS, 'depositsSortField', 'depositsSortDir');
    }

    private function applySort(string $field, array $allowed, string $fieldProp, string $dirProp): void
    {
        if (! in_array($field, $allowed, true)) {
            return;
        }

        if ($this->{$fieldProp} === $field) {
            $this->{$dirProp} = $this->{$dirProp} === 'asc' ? 'desc' : 'asc';
        } else {
            $this->{$fieldProp} = $field;
            $this->{$dirProp} = 'asc';
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, JournalLine>
     */
    public function availableLines(string $side)
    {
        if (! $this->current) {
            return collect();
        }

        $rec = $this->current;

        $lines = JournalLine::query()
            ->with('journalEntry')
            ->where('account_id', $rec->account_id)
            ->where(fn ($q) => $q->whereNull('bank_reconciliation_id')->orWhere('bank_reconciliation_id', $rec->id))
            ->whereHas('journalEntry', fn ($q) => $q->where('is_posted', true)
                ->whereNull('voided_at')
                // Hide the reversal half of a replaced/removed service-charge or
                // interest entry so editing a reconciliation never leaves a
                // phantom line behind.
                ->where(fn ($q) => $q->whereNull('reverses_entry_id')
                    ->orWhereDoesntHave('reverses', fn ($q) => $q->where('source_type', BankReconciliation::class))))
            ->when($side === 'payments', fn ($q) => $q->where('credit_cents', '>', 0))
            ->when($side === 'deposits', fn ($q) => $q->where('debit_cents', '>', 0))
            ->orderBy('id')
            ->get();

        if ($side === 'payments') {
            $labels = $this->paymentMethodLabels($lines);
            foreach ($lines as $line) {
                $line->setAttribute('payment_method_label', $labels[$line->id] ?? null);
            }
        }

        return $this->sortLines($lines, $side);
    }

    /**
     * Build a map of journal_line_id => payment method label for "Cheques and Payments"
     * lines by looking through the journal entry's source document. Cheque-sourced lines
     * label as "Cheque"; payments/receipts surface the linked PaymentMethod.name.
     *
     * @param  \Illuminate\Support\Collection<int, JournalLine>  $lines
     * @return array<int, string|null>
     */
    private function paymentMethodLabels(Collection $lines): array
    {
        $sourceIdsByType = [];
        foreach ($lines as $line) {
            $type = $line->journalEntry->source_type ?? null;
            $id = $line->journalEntry->source_id ?? null;
            if (! $type || ! $id) {
                continue;
            }
            $sourceIdsByType[$type] ??= [];
            $sourceIdsByType[$type][] = (int) $id;
        }

        $methodByBillPayment = [];
        if (! empty($sourceIdsByType[BillPayment::class] ?? [])) {
            $methodByBillPayment = BillPayment::with('paymentMethod')
                ->whereIn('id', array_unique($sourceIdsByType[BillPayment::class]))
                ->get()
                ->mapWithKeys(fn ($p) => [$p->id => $p->paymentMethod?->name])
                ->all();
        }

        $methodByReceipt = [];
        if (! empty($sourceIdsByType[CustomerReceipt::class] ?? [])) {
            $methodByReceipt = CustomerReceipt::with('paymentMethod')
                ->whereIn('id', array_unique($sourceIdsByType[CustomerReceipt::class]))
                ->get()
                ->mapWithKeys(fn ($r) => [$r->id => $r->paymentMethod?->name])
                ->all();
        }

        $labels = [];
        foreach ($lines as $line) {
            $type = $line->journalEntry->source_type ?? null;
            $id = (int) ($line->journalEntry->source_id ?? 0);

            $labels[$line->id] = match ($type) {
                BillPayment::class => $methodByBillPayment[$id] ?? null,
                CustomerReceipt::class => $methodByReceipt[$id] ?? null,
                Cheque::class => __('Cheque'),
                default => null,
            };
        }

        return $labels;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, JournalLine>  $lines
     * @return \Illuminate\Support\Collection<int, JournalLine>
     */
    private function sortLines(Collection $lines, string $side): Collection
    {
        if ($side === 'payments') {
            $field = $this->paymentsSortField;
            $dir = $this->paymentsSortDir;
            $amountCol = 'credit_cents';
        } else {
            $field = $this->depositsSortField;
            $dir = $this->depositsSortDir;
            $amountCol = 'debit_cents';
        }

        $dirMul = $dir === 'desc' ? -1 : 1;

        return $lines
            ->sort(function (JournalLine $a, JournalLine $b) use ($field, $amountCol, $dirMul) {
                $cmp = match ($field) {
                    'entry' => strnatcasecmp(
                        (string) ($a->journalEntry->entry_no ?? ''),
                        (string) ($b->journalEntry->entry_no ?? ''),
                    ),
                    'amount' => ((int) $a->{$amountCol}) <=> ((int) $b->{$amountCol}),
                    'payment_method' => strcasecmp(
                        (string) ($a->getAttribute('payment_method_label') ?? ''),
                        (string) ($b->getAttribute('payment_method_label') ?? ''),
                    ),
                    default => strcmp(
                        (string) ($a->journalEntry->entry_date?->format('Y-m-d') ?? ''),
                        (string) ($b->journalEntry->entry_date?->format('Y-m-d') ?? ''),
                    ),
                };

                return $cmp === 0
                    ? $a->id <=> $b->id
                    : $cmp * $dirMul;
            })
            ->values();
    }

    public function clearedBalanceCents(): int
    {
        if (! $this->current) {
            return 0;
        }

        return app(BankReconciliationService::class)->clearedBalanceCents($this->current);
    }

    public function differenceCents(): int
    {
        if (! $this->current) {
            return 0;
        }

        return app(BankReconciliationService::class)->differenceCents($this->current);
    }

    /**
     * @return array{count:int,cents:int}
     */
    public function markedTotals(string $side): array
    {
        if (! $this->current) {
            return ['count' => 0, 'cents' => 0];
        }

        $ids = $this->current->markedLineIds();

        if (empty($ids)) {
            return ['count' => 0, 'cents' => 0];
        }

        $col = $side === 'payments' ? 'credit_cents' : 'debit_cents';

        $rows = JournalLine::query()
            ->where('account_id', $this->current->account_id)
            ->whereIn('id', $ids)
            ->where($col, '>', 0)
            ->get([$col]);

        return [
            'count' => $rows->count(),
            'cents' => (int) $rows->sum($col),
        ];
    }

    protected function resetBeginForm(): void
    {
        $this->statementDate = $this->defaultStatementDate();
        $this->serviceChargeDate = $this->statementDate;
        $this->interestDate = $this->statementDate;
        $this->endingBalance = '';
        $this->serviceChargeAmount = '';
        $this->interestAmount = '';
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Reconcile') }}</flux:heading>
            <flux:subheading>
                @if ($this->lastCompleted)
                    {{ __('Last reconciled on :date', ['date' => $this->lastCompleted->statement_date->toFormattedDateString()]) }}
                @else
                    {{ __('No completed reconciliations on this account yet.') }}
                @endif
            </flux:subheading>
        </div>

        <flux:select wire:model.live="account_id" :label="__('Account')" class="min-w-[280px]">
            @foreach ($this->bankAccounts as $opt)
                <flux:select.option :value="$opt->id">{{ $opt->code }} — {{ $opt->name }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @if (! $this->current)
        {{-- STATE A: history + Begin Reconciliation --}}
        <div class="mb-6 flex flex-wrap items-center gap-3">
            <flux:modal.trigger name="begin-reconciliation">
                <flux:button variant="primary" icon="check-circle">{{ __('Reconcile') }}</flux:button>
            </flux:modal.trigger>

            <flux:button
                variant="filled"
                icon="arrow-uturn-left"
                wire:click="undoLast"
                wire:confirm="{{ __('Undo the last completed reconciliation? This will un-clear its lines and reverse the service charge / interest entries.') }}"
                :disabled="! $this->lastCompleted"
            >
                {{ __('Undo last reconciliation') }}
            </flux:button>
        </div>

        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-3 py-2 text-left">{{ __('Statement date') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('Beginning') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('Ending') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('Service charge') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('Interest') }}</th>
                        <th class="px-3 py-2 text-left">{{ __('Completed') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($this->history as $rec)
                        <tr
                            wire:key="rec-{{ $rec->id }}"
                            class="cursor-pointer hover:bg-muted"
                            wire:navigate.hover
                            onclick="window.Livewire.navigate(@js(route('banking.reconciliations.show', ['company' => $company->slug, 'reconciliation' => $rec->id])))"
                        >
                            <td class="px-3 py-2 whitespace-nowrap">
                                <a href="{{ route('banking.reconciliations.show', ['company' => $company->slug, 'reconciliation' => $rec->id]) }}" wire:navigate class="underline">
                                    {{ $rec->statement_date->toDateString() }}
                                </a>
                            </td>
                            <td class="px-3 py-2 text-right font-mono">{{ number_format($rec->beginning_balance_cents / 100, 2) }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ number_format($rec->ending_balance_cents / 100, 2) }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ $rec->service_charge_cents ? number_format($rec->service_charge_cents / 100, 2) : '—' }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ $rec->interest_earned_cents ? number_format($rec->interest_earned_cents / 100, 2) : '—' }}</td>
                            <td class="px-3 py-2 text-muted-foreground">
                                {{ $rec->completed_at?->toDateTimeString() }}
                                @if ($rec->completedBy)
                                    <span class="text-muted-foreground"> — {{ $rec->completedBy->name }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-8 text-center text-muted-foreground">{{ __('No reconciliations yet for this account.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Begin Reconciliation modal --}}
        <flux:modal name="begin-reconciliation" class="md:w-[640px]">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Begin reconciliation') }}</flux:heading>
                    <flux:subheading>
                        {{ __('Enter the ending balance from your bank statement, plus any service charges or interest earned.') }}
                    </flux:subheading>
                </div>

                <div class="rounded-lg border border-dashed border-border bg-muted/40 p-3" data-test="statement-autofill">
                    <flux:input type="file" wire:model="statementForFill" accept=".pdf,.ofx,.qfx,.qbo" :label="__('Drop your statement to auto-fill')" :description="__('PDF or OFX/QFX — reads the ending balance and date. Both stay editable.')" data-test="statement-autofill-input" />
                    <div wire:loading wire:target="statementForFill" class="mt-2 text-sm text-muted-foreground">{{ __('Reading statement…') }}</div>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <flux:input type="date" wire:model="statementDate" :label="__('Statement date')" />
                    <div>
                        <flux:label>{{ __('Beginning balance') }}</flux:label>
                        <div class="mt-1 h-9 rounded-md border border-border bg-muted px-3 py-2 font-mono text-sm">
                            {{ number_format($this->beginningBalanceCents() / 100, 2) }}
                        </div>
                    </div>
                </div>

                <x-amount-input model="endingBalance" modifiers="" :label="__('Ending balance')" placeholder="0.00" />

                <div class="rounded-lg border border-border p-4">
                    <flux:heading size="sm">{{ __('Service charge') }}</flux:heading>
                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <x-amount-input model="serviceChargeAmount" modifiers="" :label="__('Amount')" placeholder="0.00" />
                        <flux:input type="date" wire:model="serviceChargeDate" :label="__('Date')" />
                        <flux:select wire:model="serviceChargeAccountId" :label="__('Account')">
                            <flux:select.option value="">—</flux:select.option>
                            @foreach ($this->expenseAccountOptions as $opt)
                                <flux:select.option :value="$opt['value']">{{ $opt['label'] }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                </div>

                <div class="rounded-lg border border-border p-4">
                    <flux:heading size="sm">{{ __('Interest earned') }}</flux:heading>
                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <x-amount-input model="interestAmount" modifiers="" :label="__('Amount')" placeholder="0.00" />
                        <flux:input type="date" wire:model="interestDate" :label="__('Date')" />
                        <flux:select wire:model="interestAccountId" :label="__('Account')">
                            <flux:select.option value="">—</flux:select.option>
                            @foreach ($this->incomeAccountOptions as $opt)
                                <flux:select.option :value="$opt['value']">{{ $opt['label'] }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" wire:click="startReconciliation">{{ __('Continue') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @else
        {{-- STATE B: two-pane reconcile screen --}}
        @php
            $rec = $this->current;
            $payments = $this->availableLines('payments');
            $deposits = $this->availableLines('deposits');
            $paymentTotals = $this->markedTotals('payments');
            $depositTotals = $this->markedTotals('deposits');
            $cleared = $this->clearedBalanceCents();
            $diff = $this->differenceCents();
            $marked = $rec->markedLineIds();
        @endphp

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-muted p-3">
            <div class="text-sm">
                <span class="font-semibold">{{ $rec->account?->code }} — {{ $rec->account?->name }}</span>
                <span class="ml-3 text-muted-foreground">{{ __('Statement') }} {{ $rec->statement_date->toDateString() }}</span>
                <span class="ml-3 text-muted-foreground">{{ __('Ending') }} <span class="font-mono">{{ number_format($rec->ending_balance_cents / 100, 2) }}</span></span>
            </div>
            <div class="flex gap-2">
                <flux:button
                    size="sm"
                    variant="filled"
                    icon="pencil-square"
                    wire:click="openEditDetails"
                >
                    {{ __('Edit details') }}
                </flux:button>
                <flux:button
                    size="sm"
                    variant="filled"
                    icon="arrow-left-start-on-rectangle"
                    :href="route('dashboard')"
                    wire:navigate
                >
                    {{ __('Leave') }}
                </flux:button>
                <flux:button
                    size="sm"
                    variant="danger"
                    wire:click="cancelReconciliation"
                    wire:confirm="{{ __('Cancel this in-progress reconciliation? Any pre-posted service-charge / interest entries will be reversed.') }}"
                >
                    {{ __('Cancel') }}
                </flux:button>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            {{-- Cheques and Payments pane --}}
            <div class="overflow-x-auto rounded-lg border border-border">
                <div class="flex items-center justify-between border-b border-border bg-muted px-3 py-2">
                    <flux:heading size="sm">{{ $company->jurisdiction->chequeLabel('section') }}</flux:heading>
                    <div class="flex gap-1">
                        <flux:button size="xs" variant="ghost" wire:click="markAll('payments')">{{ __('Mark all') }}</flux:button>
                        <flux:button size="xs" variant="ghost" wire:click="unmarkAll('payments')">{{ __('Unmark all') }}</flux:button>
                    </div>
                </div>
                <table class="w-full text-sm">
                    <thead class="bg-muted text-xs uppercase text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 w-10"></th>
                            <th class="px-3 py-2 text-left"><x-sort-header field="date" :current-field="$paymentsSortField" :current-dir="$paymentsSortDir" :label="__('Date')" action="sortPayments" /></th>
                            <th class="px-3 py-2 text-left"><x-sort-header field="entry" :current-field="$paymentsSortField" :current-dir="$paymentsSortDir" :label="__('Entry')" action="sortPayments" /></th>
                            <th class="px-3 py-2 text-left">{{ __('Memo') }}</th>
                            <th class="px-3 py-2 text-left"><x-sort-header field="payment_method" :current-field="$paymentsSortField" :current-dir="$paymentsSortDir" :label="__('Payment method')" action="sortPayments" /></th>
                            <th class="px-3 py-2 text-right"><x-sort-header field="amount" :current-field="$paymentsSortField" :current-dir="$paymentsSortDir" :label="__('Amount')" align="right" action="sortPayments" /></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($payments as $line)
                            @php $isMarked = in_array($line->id, $marked, true); @endphp
                            <tr class="@if ($isMarked) bg-green-50 dark:bg-green-900/10 @endif">
                                <td class="px-3 py-2 text-center">
                                    <button type="button" wire:click="toggleMark({{ $line->id }})" class="cursor-pointer" title="{{ $isMarked ? __('Marked') : __('Click to mark') }}">
                                        @if ($isMarked)
                                            <flux:icon name="check" variant="micro" class="size-4 text-green-600" />
                                        @else
                                            <span class="inline-block size-4 rounded border border-border"></span>
                                        @endif
                                    </button>
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap">{{ $line->journalEntry->entry_date->toDateString() }}</td>
                                <td class="px-3 py-2 font-mono text-xs">
                                    <a href="{{ route('journal.show', ['company' => $company->slug, 'entry' => $line->journalEntry->id]) }}" wire:navigate class="underline">{{ $line->journalEntry->entry_no }}</a>
                                </td>
                                <td class="px-3 py-2 text-muted-foreground">{{ $line->memo ?? $line->journalEntry->memo }}</td>
                                <td class="px-3 py-2 text-muted-foreground">{{ $line->getAttribute('payment_method_label') ?? '—' }}</td>
                                <td class="px-3 py-2 text-right font-mono">{{ number_format(((int) $line->credit_cents) / 100, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-8 text-center text-muted-foreground">{{ __('Nothing to reconcile on this side.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Deposits and Other Credits pane --}}
            <div class="overflow-x-auto rounded-lg border border-border">
                <div class="flex items-center justify-between border-b border-border bg-muted px-3 py-2">
                    <flux:heading size="sm">{{ __('Deposits and Other Credits') }}</flux:heading>
                    <div class="flex gap-1">
                        <flux:button size="xs" variant="ghost" wire:click="markAll('deposits')">{{ __('Mark all') }}</flux:button>
                        <flux:button size="xs" variant="ghost" wire:click="unmarkAll('deposits')">{{ __('Unmark all') }}</flux:button>
                    </div>
                </div>
                <table class="w-full text-sm">
                    <thead class="bg-muted text-xs uppercase text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 w-10"></th>
                            <th class="px-3 py-2 text-left"><x-sort-header field="date" :current-field="$depositsSortField" :current-dir="$depositsSortDir" :label="__('Date')" action="sortDeposits" /></th>
                            <th class="px-3 py-2 text-left"><x-sort-header field="entry" :current-field="$depositsSortField" :current-dir="$depositsSortDir" :label="__('Entry')" action="sortDeposits" /></th>
                            <th class="px-3 py-2 text-left">{{ __('Memo') }}</th>
                            <th class="px-3 py-2 text-right"><x-sort-header field="amount" :current-field="$depositsSortField" :current-dir="$depositsSortDir" :label="__('Amount')" align="right" action="sortDeposits" /></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($deposits as $line)
                            @php $isMarked = in_array($line->id, $marked, true); @endphp
                            <tr class="@if ($isMarked) bg-green-50 dark:bg-green-900/10 @endif">
                                <td class="px-3 py-2 text-center">
                                    <button type="button" wire:click="toggleMark({{ $line->id }})" class="cursor-pointer" title="{{ $isMarked ? __('Marked') : __('Click to mark') }}">
                                        @if ($isMarked)
                                            <flux:icon name="check" variant="micro" class="size-4 text-green-600" />
                                        @else
                                            <span class="inline-block size-4 rounded border border-border"></span>
                                        @endif
                                    </button>
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap">{{ $line->journalEntry->entry_date->toDateString() }}</td>
                                <td class="px-3 py-2 font-mono text-xs">
                                    <a href="{{ route('journal.show', ['company' => $company->slug, 'entry' => $line->journalEntry->id]) }}" wire:navigate class="underline">{{ $line->journalEntry->entry_no }}</a>
                                </td>
                                <td class="px-3 py-2 text-muted-foreground">{{ $line->memo ?? $line->journalEntry->memo }}</td>
                                <td class="px-3 py-2 text-right font-mono">{{ number_format(((int) $line->debit_cents) / 100, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-3 py-8 text-center text-muted-foreground">{{ __('Nothing to reconcile on this side.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Sticky summary card --}}
        <div class="sticky bottom-0 mt-4 rounded-lg border border-border bg-card p-4 shadow-lg">
            <div class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm md:grid-cols-4">
                <div>
                    <flux:text class="text-muted-foreground">{{ __('Beginning balance') }}</flux:text>
                    <div class="font-mono">{{ number_format($rec->beginning_balance_cents / 100, 2) }}</div>
                </div>
                <div>
                    <flux:text class="text-muted-foreground">{{ __('Payments marked') }}</flux:text>
                    <div class="font-mono">{{ $paymentTotals['count'] }} · {{ number_format($paymentTotals['cents'] / 100, 2) }}</div>
                </div>
                <div>
                    <flux:text class="text-muted-foreground">{{ __('Deposits marked') }}</flux:text>
                    <div class="font-mono">{{ $depositTotals['count'] }} · {{ number_format($depositTotals['cents'] / 100, 2) }}</div>
                </div>
                <div>
                    <flux:text class="text-muted-foreground">{{ __('Service charge') }} / {{ __('Interest') }}</flux:text>
                    <div class="font-mono">
                        {{ number_format($rec->service_charge_cents / 100, 2) }} /
                        {{ number_format($rec->interest_earned_cents / 100, 2) }}
                    </div>
                </div>
                <div>
                    <flux:text class="text-muted-foreground">{{ __('Ending balance') }}</flux:text>
                    <div class="font-mono">{{ number_format($rec->ending_balance_cents / 100, 2) }}</div>
                </div>
                <div>
                    <flux:text class="text-muted-foreground">{{ __('Cleared balance') }}</flux:text>
                    <div class="font-mono">{{ number_format($cleared / 100, 2) }}</div>
                </div>
                <div class="col-span-2 flex items-center justify-between gap-3 md:col-span-2">
                    <div>
                        <flux:text class="text-muted-foreground">{{ __('Difference') }}</flux:text>
                        <div class="text-lg font-mono @if ($diff === 0) text-green-600 @else text-amber-600 @endif" data-test="reconciliation-difference">
                            {{ number_format($diff / 100, 2) }}
                        </div>
                    </div>
                    <flux:button
                        variant="primary"
                        icon="check-circle"
                        wire:click="reconcileNow"
                        :disabled="$diff !== 0"
                    >
                        {{ __('Reconcile now') }}
                    </flux:button>
                </div>
            </div>
        </div>

        {{-- Statement & supporting documents --}}
        <div class="mt-4 space-y-3 rounded-lg border border-border p-4" data-test="reconciliation-attachments">
            <flux:heading size="sm">{{ __('Statement & documents') }}</flux:heading>

            @forelse ($this->attachments as $att)
                <div class="flex items-center justify-between rounded-md border border-border px-3 py-2" wire:key="rec-att-{{ $att->id }}" data-test="reconciliation-attachment-row">
                    <x-attachment-link :attachment="$att" :company="$company" />
                    <flux:button variant="ghost" size="sm" icon="x-mark"
                        wire:click="removeAttachment({{ $att->id }})"
                        wire:confirm="{{ __('Remove this attachment?') }}"
                        data-test="reconciliation-attachment-remove" />
                </div>
            @empty
                <flux:text class="text-sm text-muted-foreground">{{ __('Attach the bank statement or other supporting files for this reconciliation.') }}</flux:text>
            @endforelse

            <x-attachment-dropzone model="newAttachments"
                accept=".pdf,image/*,.doc,.docx,.xls,.xlsx"
                :description="__('PDF, images, or Office docs up to 10 MB each.')"
                data-test="reconciliation-attachment-input" />

            @error('newAttachments.*') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror

            @if (count($newAttachments) > 0)
                <div class="flex items-center justify-between gap-3">
                    <flux:text class="text-sm text-muted-foreground">
                        {{ __(':count file(s) will be attached when you reconcile.', ['count' => count($newAttachments)]) }}
                    </flux:text>
                    <flux:button variant="ghost" size="sm" wire:click="uploadAttachments" data-test="reconciliation-attachment-upload">
                        {{ __('Upload now') }}
                    </flux:button>
                </div>
            @endif
        </div>

        {{-- Edit details modal --}}
        <flux:modal name="edit-reconciliation" class="md:w-[640px]">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Edit reconciliation details') }}</flux:heading>
                    <flux:subheading>
                        {{ __('Adjust the statement figures, service charge, or interest. Lines you have already marked are kept.') }}
                    </flux:subheading>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <flux:input type="date" wire:model="statementDate" :label="__('Statement date')" />
                    <x-amount-input model="beginningBalance" modifiers="" :label="__('Beginning balance')" placeholder="0.00" />
                </div>

                <x-amount-input model="endingBalance" modifiers="" :label="__('Ending balance')" placeholder="0.00" />

                <div class="rounded-lg border border-border p-4">
                    <flux:heading size="sm">{{ __('Service charge') }}</flux:heading>
                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <x-amount-input model="serviceChargeAmount" modifiers="" :label="__('Amount')" placeholder="0.00" />
                        <flux:input type="date" wire:model="serviceChargeDate" :label="__('Date')" />
                        <flux:select wire:model="serviceChargeAccountId" :label="__('Account')">
                            <flux:select.option value="">—</flux:select.option>
                            @foreach ($this->expenseAccountOptions as $opt)
                                <flux:select.option :value="$opt['value']">{{ $opt['label'] }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                </div>

                <div class="rounded-lg border border-border p-4">
                    <flux:heading size="sm">{{ __('Interest earned') }}</flux:heading>
                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <x-amount-input model="interestAmount" modifiers="" :label="__('Amount')" placeholder="0.00" />
                        <flux:input type="date" wire:model="interestDate" :label="__('Date')" />
                        <flux:select wire:model="interestAccountId" :label="__('Account')">
                            <flux:select.option value="">—</flux:select.option>
                            @foreach ($this->incomeAccountOptions as $opt)
                                <flux:select.option :value="$opt['value']">{{ $opt['label'] }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="primary" wire:click="saveDetails">{{ __('Save changes') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</section>
