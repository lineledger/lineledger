<?php

use App\Actions\Banking\BulkCategorizeStatementLines;
use App\Actions\Banking\BulkSetStatementLineStatus;
use App\Actions\Banking\CreateBankRuleFromLine;
use App\Actions\Banking\PairStatementTransfer;
use App\Actions\Banking\RecordStatementLine;
use App\Actions\Banking\SplitStatementLine;
use App\Enums\AccountSubtype;
use App\Enums\BillType;
use App\Enums\StatementLineMatchStatus;
use App\Enums\StatementSuggestionSource;
use App\Exceptions\Posting\PostingValidationException;
use App\Livewire\Concerns\ManagesStatementLineContacts;
use App\Livewire\Concerns\ManagesStatementLineTax;
use App\Models\Account;
use App\Models\BankStatementLine;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Expense;
use App\Services\Banking\Import\BankRuleEngine;
use App\Services\Banking\Import\OpenBillMatcher;
use App\Services\Banking\TransferPairMatcher;
use App\Services\Classification\CategorySuggester;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('For Review')] class extends Component {
    use ManagesStatementLineContacts, ManagesStatementLineTax;

    public Company $company;

    public ?int $accountFilter = null;

    public bool $showExcluded = false;

    /** @var array<int, int|string> selected line ids for bulk actions */
    public array $selected = [];

    public ?int $bulkCategory = null;

    public ?int $bulkContactId = null;

    /** @var array<int, int|null> lineId => chosen category account id */
    public array $categories = [];

    /** @var array<int, int|string> lineId => bill to pay ('' = record as expense) */
    public array $lineBill = [];

    // Split modal.
    public ?int $splittingLineId = null;

    public int $splitTargetCents = 0;

    /** @var array<int, array{account_id: ?int, amount: string, tax_code_ids: array<int, int|string>}> */
    public array $splits = [];

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    /**
     * @return Collection<int, BankStatementLine>
     */
    #[Computed]
    public function rows(): Collection
    {
        $base = $this->showExcluded
            ? BankStatementLine::query()
                ->where('match_status', StatementLineMatchStatus::Ignored->value)
                ->whereNull('created_journal_entry_id')
            : BankStatementLine::query()->forReview();

        return $base
            ->when($this->accountFilter, fn ($q) => $q->where('account_id', $this->accountFilter))
            ->with(['account:id,code,name', 'suggestedAccount:id,code,name', 'suggestedBill:id,bill_no'])
            ->orderBy('txn_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Open-bill offers for every vendor-tagged outflow in the feed, keyed by line id.
     *
     * @return array<int, Collection<int, \App\Models\Bill>>
     */
    #[Computed]
    public function billCandidates(): array
    {
        if ($this->showExcluded) {
            return [];
        }

        $overrides = [];
        foreach ($this->rows as $line) {
            $overrides[$line->id] = $this->lineContactId($line);
        }

        return app(OpenBillMatcher::class)->forLines($this->rows, $overrides);
    }

    /**
     * Lines an active bank rule already covers, keyed by line id.
     *
     * @return array<int, \App\Models\BankRule>
     */
    #[Computed]
    public function ruleCoveredLines(): array
    {
        if ($this->rows->isEmpty()) {
            return [];
        }

        return app(BankRuleEngine::class)->firstMatchingForMany(
            (int) $this->company->id,
            $this->rows->mapWithKeys(fn (BankStatementLine $l) => [$l->id => $l->description])->all(),
        );
    }

    public function updatedLineBill(mixed $value, int|string $key): void
    {
        $line = $this->lineForReview((int) $key);

        if ($line === null) {
            return;
        }

        $line->forceFill(match (true) {
            $value === '' || $value === null => ['suggested_bill_id' => null, 'suggested_bill_allocations' => null],
            $value === 'allocations' => ['suggested_bill_id' => null],
            default => ['suggested_bill_id' => (int) $value, 'suggested_bill_allocations' => null],
        })->save();
        unset($this->rows);
    }

    // ---- "Pay bills…" picker: apply one outflow across several open bills ----

    public ?int $payBillsLineId = null;

    public int $payBillsTargetCents = 0;

    /** @var array<int, array{bill_id: int, bill_no: string, type: string, due_date: string, balance: int, apply: string}> */
    public array $payBillsRows = [];

    /**
     * Open bills (both kinds) per payee shown on the page, one grouped query —
     * gates the "Pay bills…" button.
     *
     * @return array<int, int> contact id => open bill count
     */
    #[Computed]
    public function openBillCounts(): array
    {
        $contactIds = $this->rows
            ->map(fn (BankStatementLine $l) => $l->isOutflow() ? $this->lineContactId($l) : null)
            ->filter()
            ->unique()
            ->values();

        if ($contactIds->isEmpty()) {
            return [];
        }

        return Bill::query()
            ->openWithBalance()
            ->whereIn('contact_id', $contactIds)
            ->selectRaw('contact_id, COUNT(*) AS n')
            ->groupBy('contact_id')
            ->pluck('n', 'contact_id')
            ->map(fn ($n): int => (int) $n)
            ->all();
    }

    public function openPayBills(int $lineId): void
    {
        $line = $this->lineForReview($lineId);

        if ($line === null) {
            return;
        }

        $contactId = $this->lineContactId($line);
        $contact = $contactId !== null ? Contact::query()->find($contactId) : null;

        if ($contact === null) {
            Flux::toast(variant: 'danger', text: __('Choose the payee first.'));

            return;
        }

        $bills = app(OpenBillMatcher::class)->openBillsFor($line, $contact);

        if ($bills->isEmpty()) {
            Flux::toast(variant: 'danger', text: __('No open bills for this payee.'));

            return;
        }

        $target = abs((int) $line->amount_cents);

        // Pre-fill: the saved allocation, else the offered single bill, else the
        // oldest bills first up to the transaction total (last one partial).
        $existing = collect($line->suggestedBillAllocations())->pluck('amount_cents', 'bill_id');
        if ($existing->isEmpty() && $line->suggested_bill_id !== null) {
            $existing = collect([(int) $line->suggested_bill_id => $target]);
        }
        $remaining = $target;

        $this->payBillsRows = [];
        foreach ($bills as $bill) {
            $balance = $bill->balanceCents();

            if ($existing->isNotEmpty()) {
                $apply = (int) ($existing[$bill->id] ?? 0);
            } else {
                $apply = min($balance, $remaining);
                $remaining -= $apply;
            }

            $type = $bill->getAttribute('bill_type');

            $this->payBillsRows[] = [
                'bill_id' => (int) $bill->id,
                'bill_no' => (string) ($bill->bill_no ?: '#'.$bill->id),
                'type' => $type instanceof BillType ? $type->value : BillType::Vendor->value,
                'due_date' => $bill->due_date ? \Carbon\CarbonImmutable::parse($bill->due_date)->toDateString() : '',
                'balance' => $balance,
                'apply' => $apply > 0 ? number_format($apply / 100, 2, '.', '') : '',
            ];
        }

        $this->payBillsLineId = $lineId;
        $this->payBillsTargetCents = $target;
        $this->resetErrorBag('payBillsRows');

        Flux::modal('pay-bills')->show();
    }

    public function payBillsTotal(): int
    {
        return (int) collect($this->payBillsRows)->sum(fn (array $row): int => Money::tryFromString(trim((string) $row['apply']))?->cents ?? 0);
    }

    public function savePayBills(): void
    {
        $line = $this->payBillsLineId ? $this->lineForReview($this->payBillsLineId) : null;

        if ($line === null) {
            $this->cancelPayBills();

            return;
        }

        $allocations = [];

        foreach ($this->payBillsRows as $row) {
            $cents = Money::tryFromString(trim((string) $row['apply']))?->cents ?? 0;

            if ($cents <= 0) {
                continue;
            }

            if ($cents > (int) $row['balance']) {
                $this->addError('payBillsRows', __('Bill :no cannot be paid more than its open balance.', ['no' => $row['bill_no']]));

                return;
            }

            $allocations[] = ['bill_id' => (int) $row['bill_id'], 'amount_cents' => $cents];
        }

        if (array_sum(array_column($allocations, 'amount_cents')) !== $this->payBillsTargetCents) {
            $this->addError('payBillsRows', __('The amounts applied must add up to the transaction total.'));

            return;
        }

        // Always stored as a set: a partial payment of even one bill is a set.
        $line->forceFill(['suggested_bill_id' => null, 'suggested_bill_allocations' => $allocations])->save();
        $this->lineBill[$line->id] = 'allocations';

        $this->cancelPayBills();
        unset($this->rows, $this->billCandidates);

        Flux::toast(variant: 'success', text: __('Bills chosen — confirm the line to record the payment.'));
    }

    public function cancelPayBills(): void
    {
        $this->payBillsLineId = null;
        $this->payBillsTargetCents = 0;
        $this->payBillsRows = [];
        Flux::modal('pay-bills')->close();
    }

    /**
     * "Pay :n bills (:amount)" for a stored allocation, or null.
     */
    public function allocationLabel(BankStatementLine $line): ?string
    {
        $allocations = $line->suggestedBillAllocations();

        if ($allocations === []) {
            return null;
        }

        return __('Pay :n bills (:amount)', [
            'n' => count($allocations),
            'amount' => number_format(array_sum(array_column($allocations, 'amount_cents')) / 100, 2),
        ]);
    }

    protected function statementLineForContact(int $lineId): ?BankStatementLine
    {
        return $this->lineForReview($lineId);
    }

    protected function statementLineForTax(int $lineId): ?BankStatementLine
    {
        return $this->lineForReview($lineId);
    }

    protected function afterLineTaxChanged(BankStatementLine $line): void
    {
        unset($this->rows);
    }

    /**
     * @return Collection<int, int>
     */
    protected function suggestedContactIdsOnPage(): Collection
    {
        return $this->rows->pluck('suggested_contact_id')->filter()->map(fn ($id): int => (int) $id)->values();
    }

    /**
     * Picking a payee pre-fills an empty category from their default (or most
     * used) account and looks for an open bill of this amount.
     */
    protected function afterLineContactChanged(BankStatementLine $line, ?Contact $contact): void
    {
        $lineId = $line->id;

        if ($contact === null) {
            $this->lineBill[$lineId] = '';
            unset($this->lineHint[$lineId], $this->rows, $this->billCandidates);

            return;
        }

        $category = (int) ($this->categories[$lineId] ?? $line->suggested_account_id ?? 0);
        $suggester = app(CategorySuggester::class);
        $suggestion = null;
        $accountPrefilled = false;

        if ($category === 0) {
            $suggestion = $suggester->fromContact((int) $line->company_id, (int) $contact->id);

            if ($suggestion !== null) {
                $this->categories[$lineId] = $suggestion->accountId;
                $line->forceFill([
                    'suggested_account_id' => $suggestion->accountId,
                    'suggestion_source' => $line->suggestion_source?->value ?? StatementSuggestionSource::ContactDefault->value,
                    'match_reason' => $suggestion->reason,
                ])->save();
                $this->lineHint[$lineId] = __("Pre-filled from :name's default account.", ['name' => $contact->display_name]);
                $accountPrefilled = true;
            }
        } elseif ($line->isOutflow() && $contact->default_expense_account_id === null) {
            $this->lineHint[$lineId] = __("Will use as :name's default account.", ['name' => $contact->display_name]);
        }

        if ($line->isOutflow() && array_filter($this->lineTaxIds($line)) === []) {
            $taxCodeId = $suggestion?->taxCodeId ?? $suggester->defaultTaxCodeFor((int) $line->company_id, (int) $contact->id);

            if ($taxCodeId !== null) {
                $this->lineTax[$lineId] = [$taxCodeId];
                $line->forceFill(['suggested_tax_code_id' => $taxCodeId, 'suggested_secondary_tax_code_id' => $suggestion?->secondaryTaxCodeId])->save();
                $this->lineHint[$lineId] = match (true) {
                    $accountPrefilled => __("Pre-filled from :name's default account and tax.", ['name' => $contact->display_name]),
                    isset($this->lineHint[$lineId]) => $this->lineHint[$lineId].' '.__('Tax pre-filled from their default.'),
                    default => __("Tax pre-filled from :name's default.", ['name' => $contact->display_name]),
                };
            }
        }

        $matcher = app(OpenBillMatcher::class);
        $fresh = $line->fresh() ?? $line;
        $offer = $matcher->forLine($fresh, (int) $contact->id);
        $allocation = $offer === null ? $matcher->allocationFor($fresh, (int) $contact->id) : null;

        $this->lineBill[$lineId] = $offer?->id ?? ($allocation !== null ? 'allocations' : '');
        $line->forceFill(['suggested_bill_id' => $offer?->id, 'suggested_bill_allocations' => $allocation])->save();

        unset($this->rows, $this->billCandidates, $this->openBillCounts);
    }

    /**
     * "Always do this": write a bank rule for this payee with the row's
     * category (and vendor).
     */
    public function createRule(int $lineId): void
    {
        $line = $this->lineForReview($lineId);

        if ($line === null) {
            return;
        }

        $accountId = (int) ($this->categories[$lineId] ?? 0) ?: (int) $line->suggested_account_id;

        if ($accountId === 0) {
            Flux::toast(variant: 'danger', text: __('Choose a category first.'));

            return;
        }

        try {
            $rule = app(CreateBankRuleFromLine::class)->handle($line, $accountId, $this->lineContactId($line));
        } catch (ValidationException $e) {
            Flux::toast(variant: 'danger', text: collect($e->errors())->flatten()->first() ?? $e->getMessage());

            return;
        }

        unset($this->rows, $this->ruleCoveredLines, $this->billCandidates, $this->contactNames);

        Flux::toast(variant: 'success', text: __('Rule ":name" created — edit it under Bank rules.', ['name' => $rule->name]));
    }

    /**
     * Suggested inter-account transfer pairs hiding in the feed.
     *
     * @return Collection<int, array{out: BankStatementLine, in: BankStatementLine, days: int}>
     */
    #[Computed]
    public function transferCandidates(): Collection
    {
        if ($this->showExcluded) {
            return collect();
        }

        return app(TransferPairMatcher::class)->candidates($this->company);
    }

    public function recordTransfer(int $outId, int $inId, PairStatementTransfer $action): void
    {
        $out = $this->lineForReview($outId);
        $in = $this->lineForReview($inId);

        if ($out === null || $in === null) {
            return;
        }

        try {
            $action->handle($out, $in);
            unset($this->rows, $this->transferCandidates);
            Flux::toast(variant: 'success', text: __('Transfer recorded.'));
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }

    public function updatedShowExcluded(): void
    {
        $this->selected = [];
    }

    public function selectAllVisible(): void
    {
        $this->selected = $this->rows->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    public function bulkCategorize(BulkCategorizeStatementLines $action): void
    {
        if (! $this->bulkCategory || $this->selected === []) {
            return;
        }

        try {
            $count = $action->handle($this->selected, (int) $this->bulkCategory, $this->bulkContactId ?: null);
            $this->selected = [];
            $this->bulkCategory = null;
            $this->bulkContactId = null;
            unset($this->rows, $this->billCandidates, $this->contactNames);
            Flux::toast(variant: 'success', text: __(':count added to your books.', ['count' => $count]));
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }

    public function bulkExclude(BulkSetStatementLineStatus $action): void
    {
        $action->exclude($this->selected);
        $this->selected = [];
        unset($this->rows);
    }

    public function bulkInclude(BulkSetStatementLineStatus $action): void
    {
        $action->include($this->selected);
        $this->selected = [];
        unset($this->rows);
    }

    public function includeLine(int $lineId, BulkSetStatementLineStatus $action): void
    {
        $action->include([$lineId]);
        unset($this->rows);
    }

    /**
     * @return Collection<int, Account>
     */
    #[Computed]
    public function bankAccounts(): Collection
    {
        return Account::query()
            ->whereIn('subtype', [AccountSubtype::Bank->value, AccountSubtype::CreditCard->value])
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    #[Computed]
    public function categoryOptions(): array
    {
        return Account::query()
            ->selectableForItemAccount()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Account $a) => ['value' => $a->id, 'label' => "{$a->code} — {$a->name}"])
            ->all();
    }

    /**
     * @return Collection<int, Contact>
     */
    #[Computed]
    public function contactOptions(): Collection
    {
        return Contact::query()->where('is_active', true)->orderBy('display_name')->get(['id', 'display_name']);
    }

    public function accept(int $lineId, RecordStatementLine $action): void
    {
        $line = $this->lineForReview($lineId);

        if ($line === null) {
            return;
        }

        $accountId = $this->categories[$lineId] ?? $line->suggested_account_id;
        $billRaw = $this->lineBill[$lineId] ?? ($line->suggested_bill_id ?? ($line->suggestedBillAllocations() !== [] ? 'allocations' : null));
        $allocations = $billRaw === 'allocations' ? $line->suggestedBillAllocations() : [];
        $billId = ($billRaw === '' || $billRaw === null || $billRaw === 'allocations') ? null : (int) $billRaw;

        if (! $accountId && $billId === null && $allocations === []) {
            Flux::toast(variant: 'danger', text: __('Choose a category first.'));

            return;
        }

        [$taxCodeId, $secondaryTaxCodeId] = $this->lineTaxIds($line);

        try {
            $entry = $action->handle(
                $line,
                $accountId ? (int) $accountId : null,
                $this->lineContactId($line),
                $billId,
                taxCodeId: $taxCodeId,
                secondaryTaxCodeId: $secondaryTaxCodeId,
                billAllocations: $allocations !== [] ? $allocations : null,
            );
            unset($this->rows, $this->billCandidates, $this->contactNames, $this->openBillCounts, $this->lineHint[$lineId]);
            Flux::toast(variant: 'success', text: match ($entry->source_type) {
                Expense::class => __('Recorded as an expense.'),
                BillPayment::class => __('Bill payment recorded.'),
                default => __('Added to your books.'),
            });
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }

    public function exclude(int $lineId): void
    {
        BankStatementLine::query()
            ->forReview()
            ->whereKey($lineId)
            ->update(['match_status' => StatementLineMatchStatus::Ignored->value]);

        unset($this->rows);
    }

    public function openSplit(int $lineId): void
    {
        $line = $this->lineForReview($lineId);

        if ($line === null) {
            return;
        }

        $this->splittingLineId = $lineId;
        $this->splitTargetCents = abs((int) $line->amount_cents);
        $this->splits = [['account_id' => null, 'amount' => number_format($this->splitTargetCents / 100, 2, '.', ''), 'tax_code_ids' => []]];

        Flux::modal('split-line')->show();
    }

    public function addSplit(): void
    {
        $this->splits[] = ['account_id' => null, 'amount' => '', 'tax_code_ids' => []];
    }

    /** The payee the split will be recorded against — the row's chosen contact. */
    public function splitPayeeName(): ?string
    {
        $line = $this->splittingLineId ? $this->lineForReview($this->splittingLineId) : null;
        $contactId = $line ? $this->lineContactId($line) : null;

        return $contactId ? ($this->contactNames[$contactId] ?? null) : null;
    }

    public function removeSplit(int $index): void
    {
        unset($this->splits[$index]);
        $this->splits = array_values($this->splits);
    }

    public function saveSplit(SplitStatementLine $action): void
    {
        $line = $this->splittingLineId ? $this->lineForReview($this->splittingLineId) : null;

        if ($line === null) {
            $this->cancelSplit();

            return;
        }

        $payload = collect($this->splits)
            ->filter(fn (array $s): bool => $s['account_id'] && trim((string) $s['amount']) !== '')
            ->map(function (array $s) use ($line): array {
                $taxIds = array_values(array_unique(array_filter(array_map('intval', (array) ($s['tax_code_ids'] ?? [])))));

                return [
                    'account_id' => (int) $s['account_id'],
                    'amount_cents' => Money::fromString((string) $s['amount'])->cents,
                    'tax_code_id' => $line->isOutflow() ? ($taxIds[0] ?? null) : null,
                    'secondary_tax_code_id' => $line->isOutflow() ? ($taxIds[1] ?? null) : null,
                ];
            })
            ->values()
            ->all();

        try {
            $action->handle($line, $payload, $this->lineContactId($line));
            $this->cancelSplit();
            unset($this->rows);
            Flux::toast(variant: 'success', text: __('Split added to your books.'));
        } catch (PostingValidationException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }

    public function cancelSplit(): void
    {
        $this->splittingLineId = null;
        $this->splits = [];
        $this->splitTargetCents = 0;
        Flux::modal('split-line')->close();
    }

    private function lineForReview(int $lineId): ?BankStatementLine
    {
        return BankStatementLine::query()->forReview()->whereKey($lineId)->first();
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('For Review') }}</flux:heading>
            <flux:subheading>{{ __('Imported bank transactions waiting to be categorized, across every account.') }}</flux:subheading>
        </div>
        <div class="flex items-end gap-3">
            <flux:select wire:model.live="accountFilter" :label="__('Account')" class="max-w-[240px]" data-test="review-account-filter">
                <flux:select.option value="">{{ __('All accounts') }}</flux:select.option>
                @foreach ($this->bankAccounts as $acct)
                    <flux:select.option :value="$acct->id">{{ $acct->code }} — {{ $acct->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:switch wire:model.live="showExcluded" :label="__('Excluded')" data-test="review-show-excluded" />
        </div>
    </div>

    {{-- Suggested transfers --}}
    @if ($this->transferCandidates->isNotEmpty())
        <div class="mb-4 overflow-hidden rounded-lg border border-border" data-test="transfer-suggestions">
            <div class="border-b border-border bg-muted px-4 py-2 text-sm font-medium">{{ __('Suggested transfers') }}</div>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-border">
                    @foreach ($this->transferCandidates as $pair)
                        <tr data-test="transfer-pair" wire:key="xfer-{{ $pair['out']->id }}-{{ $pair['in']->id }}">
                            <td class="px-4 py-2">
                                {{ $pair['out']->account?->name }} → {{ $pair['in']->account?->name }}
                                <span class="text-muted-foreground">· {{ $pair['out']->txn_date?->toDateString() }}</span>
                            </td>
                            <td class="px-4 py-2 text-right font-mono">{{ number_format(abs($pair['out']->amount_cents) / 100, 2) }}</td>
                            <td class="px-4 py-2 text-right">
                                <flux:button size="xs" variant="primary" wire:click="recordTransfer({{ $pair['out']->id }}, {{ $pair['in']->id }})" data-test="record-transfer">{{ __('Record transfer') }}</flux:button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Bulk action bar --}}
    @if (count($selected) > 0)
        <div class="mb-4 flex flex-wrap items-center gap-2 rounded-lg border border-border bg-muted px-4 py-2" data-test="review-bulk-bar">
            <span class="text-sm font-medium">{{ __(':n selected', ['n' => count($selected)]) }}</span>
            <flux:button size="xs" variant="ghost" wire:click="clearSelection">{{ __('Clear') }}</flux:button>
            <div class="ml-auto flex flex-wrap items-center gap-2">
                @if ($showExcluded)
                    <flux:button size="sm" variant="primary" wire:click="bulkInclude" data-test="bulk-include">{{ __('Include') }}</flux:button>
                @else
                    <flux:select wire:model="bulkCategory" size="sm" class="min-w-[200px]" data-test="bulk-category">
                        <flux:select.option value="">{{ __('Categorize as…') }}</flux:select.option>
                        @foreach ($this->categoryOptions as $opt)
                            <flux:select.option :value="$opt['value']">{{ $opt['label'] }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="bulkContactId" size="sm" class="min-w-[180px]" data-test="bulk-contact">
                        <flux:select.option value="">{{ __('No vendor') }}</flux:select.option>
                        @foreach ($this->contactOptions as $contact)
                            <flux:select.option :value="$contact->id">{{ $contact->display_name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:button size="sm" variant="primary" wire:click="bulkCategorize" :disabled="! $bulkCategory" data-test="bulk-categorize">{{ __('Categorize') }}</flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="bulkExclude" data-test="bulk-exclude">{{ __('Exclude') }}</flux:button>
                @endif
            </div>
        </div>
    @endif

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-3 py-2 text-left"><flux:checkbox wire:click="selectAllVisible" :checked="count($selected) > 0 && count($selected) === $this->rows->count()" data-test="select-all" /></th>
                    <th class="px-3 py-2 text-left">{{ __('Date') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('Account') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('Description') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('Amount') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('Category') }}</th>
                    <th class="px-3 py-2 text-left">{{ __('Contact') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('Actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->rows as $line)
                    @php
                        $suggested = $line->hasUnconfirmedSuggestion();
                        $contactId = $this->lineContactId($line);
                        $contactName = $contactId ? ($this->contactNames[$contactId] ?? null) : null;
                        $contactState = $this->lineContactState($line->id);
                        $candidates = $this->billCandidates[$line->id] ?? null;
                        $coveringRule = $this->ruleCoveredLines[$line->id] ?? null;
                        $categoryChosen = ($categories[$line->id] ?? null) ?: $line->suggested_account_id;
                        $sourceIcon = match ($line->suggestion_source) {
                            \App\Enums\StatementSuggestionSource::Rule => 'bolt',
                            \App\Enums\StatementSuggestionSource::History => 'clock',
                            \App\Enums\StatementSuggestionSource::OpenBill => 'document-text',
                            \App\Enums\StatementSuggestionSource::ContactDefault => 'user',
                            \App\Enums\StatementSuggestionSource::Ai => 'sparkles',
                            default => null,
                        };
                    @endphp
                    <tr data-test="review-row" wire:key="line-{{ $line->id }}" data-state="{{ $suggested ? 'suggested' : 'unmatched' }}">
                        <td class="px-3 py-2"><flux:checkbox wire:model.live="selected" value="{{ $line->id }}" data-test="review-select" /></td>
                        <td class="px-3 py-2 whitespace-nowrap">{{ $line->txn_date?->toDateString() }}</td>
                        <td class="px-3 py-2 text-muted-foreground">{{ $line->account?->code }}</td>
                        <td class="px-3 py-2">{{ $line->description }}</td>
                        <td class="px-3 py-2 text-right font-mono {{ $line->amount_cents < 0 ? 'text-red-600 dark:text-red-500' : 'text-green-600 dark:text-green-500' }}">
                            {{ number_format($line->amount_cents / 100, 2) }}
                        </td>
                        <td class="px-3 py-2 align-top">
                            <flux:select wire:model="categories.{{ $line->id }}" size="sm" class="min-w-[180px]" data-test="review-category">
                                <flux:select.option value="">{{ $line->suggested_account_id && $line->suggestedAccount ? $line->suggestedAccount->code.' — '.$line->suggestedAccount->name : __('— pick —') }}</flux:select.option>
                                @foreach ($this->categoryOptions as $opt)
                                    <flux:select.option :value="$opt['value']">{{ $opt['label'] }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            @if ($line->isOutflow() && ! $showExcluded)
                                @php($taxIds = array_values(array_filter($this->lineTaxIds($line))))
                                <flux:dropdown class="mt-1">
                                    <flux:button size="xs" variant="outline" icon:trailing="chevron-down" class="font-normal" data-test="review-tax">
                                        {{ $this->lineTaxLabel($line) ?: __('No tax') }}
                                    </flux:button>
                                    <flux:menu>
                                        <flux:menu.checkbox.group wire:model.live="lineTax.{{ $line->id }}">
                                            @foreach ($this->purchaseTaxCodes as $code)
                                                <flux:menu.checkbox value="{{ $code->id }}" :disabled="count($taxIds) === 2 && ! in_array($code->id, $taxIds)" keep-open>{{ $code->code }}</flux:menu.checkbox>
                                            @endforeach
                                        </flux:menu.checkbox.group>
                                    </flux:menu>
                                </flux:dropdown>
                            @endif
                            @if ($suggested)
                                <div class="mt-1 flex items-start gap-1 text-xs text-muted-foreground" data-test="review-reason">
                                    <flux:badge size="sm" color="violet" :icon="$sourceIcon">{{ __('Suggested') }}</flux:badge>
                                    @if ($line->match_reason)
                                        <span class="max-w-[16rem]">{{ $line->match_reason }}</span>
                                    @endif
                                </div>
                            @endif
                            @php($allocationLabel = $this->allocationLabel($line))
                            @if ((($candidates && $candidates->isNotEmpty()) || $allocationLabel) && ! $showExcluded)
                                <flux:select wire:model.live="lineBill.{{ $line->id }}" size="sm" class="mt-1 min-w-[180px]" data-test="review-record-as">
                                    <flux:select.option value="">{{ __('Record as expense') }}</flux:select.option>
                                    @if ($allocationLabel)
                                        <flux:select.option value="allocations">{{ $allocationLabel }}</flux:select.option>
                                    @endif
                                    @foreach ($candidates ?? [] as $bill)
                                        <flux:select.option :value="$bill->id">
                                            {{ __('Pay bill :no (:amount)', ['no' => $bill->bill_no ?: '#'.$bill->id, 'amount' => number_format($bill->balanceCents() / 100, 2)]) }}
                                        </flux:select.option>
                                    @endforeach
                                </flux:select>
                            @endif
                            @if (($lineHint[$line->id] ?? '') !== '')
                                <div class="mt-1 text-xs text-muted-foreground" data-test="review-hint">{{ $lineHint[$line->id] }}</div>
                            @endif
                        </td>
                        <td class="px-3 py-2 align-top">
                            <x-statement-contact-combo
                                :line-id="$line->id"
                                :add-label="$line->isInflow() ? 'customer' : 'vendor'"
                                :options="$this->lineContactOptions($line->id)"
                                :selected-id="$contactId"
                                :selected-name="$contactName"
                                :query="$contactState['query']"
                                :creating="$contactState['creating']"
                                :new-name="$contactState['new_name']"
                                data-test="review-contact"
                            />
                        </td>
                        <td class="px-3 py-2 align-top">
                            <div class="flex justify-end gap-1">
                                @if ($showExcluded)
                                    <flux:button size="xs" variant="ghost" wire:click="includeLine({{ $line->id }})" data-test="row-include">{{ __('Include') }}</flux:button>
                                @else
                                    @if ($line->isOutflow() && $contactId && ($this->openBillCounts[$contactId] ?? 0) > 0)
                                        <flux:tooltip :content="__('Pay one or more open bills with this transaction')">
                                            <flux:button size="xs" variant="ghost" icon="document-text" wire:click="openPayBills({{ $line->id }})" data-test="review-pay-bills" />
                                        </flux:tooltip>
                                    @endif
                                    @if ($categoryChosen)
                                        @if ($coveringRule)
                                            <flux:tooltip :content="__('Rule “:name” already covers this — edit it under Bank rules.', ['name' => $coveringRule->name])">
                                                <flux:button size="xs" variant="ghost" icon="bolt" disabled data-test="review-rule-exists" />
                                            </flux:tooltip>
                                        @else
                                            <flux:tooltip :content="__('Always do this — create a rule for this payee')">
                                                <flux:button size="xs" variant="ghost" icon="bolt" wire:click="createRule({{ $line->id }})" data-test="review-make-rule" />
                                            </flux:tooltip>
                                        @endif
                                    @endif
                                    <flux:button size="xs" variant="primary" wire:click="accept({{ $line->id }})" data-test="review-accept">{{ $suggested ? __('Confirm') : __('Accept') }}</flux:button>
                                    <flux:button size="xs" variant="ghost" wire:click="openSplit({{ $line->id }})" data-test="review-split">{{ __('Split') }}</flux:button>
                                    <flux:button size="xs" variant="ghost" wire:click="exclude({{ $line->id }})" data-test="review-exclude">{{ __('Exclude') }}</flux:button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-3 py-8 text-center text-muted-foreground">{{ $showExcluded ? __('No excluded transactions.') : __('Nothing to review — every imported transaction is categorized.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Split modal --}}
    <flux:modal name="split-line" class="md:w-[760px]" wire:close="cancelSplit">
        @php($splitSum = collect($splits)->sum(fn ($s) => trim((string) $s['amount']) === '' ? 0 : Money::fromString((string) $s['amount'])->cents))
        @php($splitLine = $splittingLineId ? $this->rows->firstWhere('id', $splittingLineId) : null)
        @php($splitIsOutflow = $splitLine !== null && $splitLine->isOutflow())
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Split transaction') }}</flux:heading>
            <flux:subheading>
                {{ __('Divide this transaction across categories. The parts must add up to the total.') }}
                @if ($splitIsOutflow)
                    <span class="block" data-test="split-payee">
                        @if ($this->splitPayeeName())
                            {{ __('Recorded as an expense to :name.', ['name' => $this->splitPayeeName()]) }}
                        @else
                            {{ __('No payee — pick a vendor on the row to record the expense to them.') }}
                        @endif
                    </span>
                @endif
            </flux:subheading>

            @foreach ($splits as $i => $split)
                @php($splitTaxIds = array_values(array_filter(array_map('intval', (array) ($split['tax_code_ids'] ?? [])))))
                <div class="grid grid-cols-12 gap-2" wire:key="split-{{ $i }}" data-test="split-row">
                    <flux:select wire:model="splits.{{ $i }}.account_id" class="{{ $splitIsOutflow ? 'col-span-5' : 'col-span-7' }}" data-test="split-account">
                        <flux:select.option value="">{{ __('— category —') }}</flux:select.option>
                        @foreach ($this->categoryOptions as $opt)
                            <flux:select.option :value="$opt['value']">{{ $opt['label'] }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @if ($splitIsOutflow)
                        <flux:dropdown class="col-span-3">
                            <flux:button variant="outline" icon:trailing="chevron-down" class="w-full justify-between font-normal" data-test="split-tax">
                                <span class="truncate">{{ $this->purchaseTaxCodes->whereIn('id', $splitTaxIds)->pluck('code')->implode(', ') ?: __('No tax') }}</span>
                            </flux:button>
                            <flux:menu>
                                <flux:menu.checkbox.group wire:model.live="splits.{{ $i }}.tax_code_ids">
                                    @foreach ($this->purchaseTaxCodes as $code)
                                        <flux:menu.checkbox value="{{ $code->id }}" :disabled="count($splitTaxIds) === 2 && ! in_array($code->id, $splitTaxIds)" keep-open>{{ $code->code }}</flux:menu.checkbox>
                                    @endforeach
                                </flux:menu.checkbox.group>
                            </flux:menu>
                        </flux:dropdown>
                    @endif
                    <x-amount-input model="splits.{{ $i }}.amount" class="{{ $splitIsOutflow ? 'col-span-3' : 'col-span-4' }}" placeholder="0.00" data-test="split-amount" />
                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="removeSplit({{ $i }})" class="col-span-1" />
                </div>
            @endforeach

            <div class="flex items-center justify-between text-sm">
                <flux:button size="sm" variant="ghost" icon="plus" wire:click="addSplit" data-test="split-add">{{ __('Add line') }}</flux:button>
                <span class="font-mono {{ $splitSum === $splitTargetCents ? 'text-green-600 dark:text-green-500' : 'text-muted-foreground' }}" data-test="split-remaining">
                    {{ __('Allocated :a of :t', ['a' => number_format($splitSum / 100, 2), 't' => number_format($splitTargetCents / 100, 2)]) }}
                </span>
            </div>

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="cancelSplit">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" wire:click="saveSplit" :disabled="$splitSum !== $splitTargetCents" data-test="split-save">{{ __('Add split') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="pay-bills" class="md:w-[680px]" wire:close="cancelPayBills" data-test="pay-bills-modal">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Pay bills from this transaction') }}</flux:heading>
            <flux:subheading>{{ __('Apply :amount across the open bills below. The amounts must add up to the transaction; a bill may be paid in part.', ['amount' => number_format($payBillsTargetCents / 100, 2)]) }}</flux:subheading>

            <div class="overflow-x-auto rounded-lg border border-border">
                <table class="w-full text-sm">
                    <thead class="bg-muted text-xs uppercase text-muted-foreground">
                        <tr>
                            <th class="px-3 py-2 text-left">{{ __('Bill #') }}</th>
                            <th class="px-3 py-2 text-left">{{ __('Type') }}</th>
                            <th class="px-3 py-2 text-left">{{ __('Due') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('Open balance') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('Apply') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($payBillsRows as $i => $row)
                            <tr wire:key="pay-bill-{{ $row['bill_id'] }}" data-test="pay-bills-row">
                                <td class="px-3 py-2">{{ $row['bill_no'] }}</td>
                                <td class="px-3 py-2">
                                    @if ($row['type'] === \App\Enums\BillType::Reimbursement->value)
                                        <flux:badge size="sm" color="zinc">{{ __('Reimbursement') }}</flux:badge>
                                    @else
                                        <span class="text-muted-foreground">{{ __('Bill') }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap text-muted-foreground">{{ $row['due_date'] }}</td>
                                <td class="px-3 py-2 text-right font-mono">{{ number_format($row['balance'] / 100, 2) }}</td>
                                <td class="px-3 py-2 text-right">
                                    <x-amount-input model="payBillsRows.{{ $i }}.apply" modifiers=".live.debounce.300ms" size="sm" placeholder="0.00" class="ml-auto w-32 text-right" data-test="pay-bills-apply" />
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @error('payBillsRows')
                <flux:text class="text-red-600" data-test="pay-bills-error">{{ $message }}</flux:text>
            @enderror

            <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                <span class="font-mono {{ $this->payBillsTotal() === $payBillsTargetCents ? 'text-green-600 dark:text-green-500' : 'text-muted-foreground' }}" data-test="pay-bills-total">
                    {{ __('Allocated :a of :t', ['a' => number_format($this->payBillsTotal() / 100, 2), 't' => number_format($payBillsTargetCents / 100, 2)]) }}
                </span>
                <div class="flex gap-2">
                    <flux:button variant="ghost" wire:click="cancelPayBills" data-test="pay-bills-cancel">{{ __('Cancel') }}</flux:button>
                    <flux:button variant="primary" wire:click="savePayBills" :disabled="$this->payBillsTotal() !== $payBillsTargetCents" data-test="pay-bills-save">{{ __('Save') }}</flux:button>
                </div>
            </div>
        </div>
    </flux:modal>
</section>
