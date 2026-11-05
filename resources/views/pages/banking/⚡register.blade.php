<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\Banking\AccountCurrencyAmounts;
use App\Support\Access\CurrentViewer;
use App\Support\Access\PayrollDetail;
use App\Support\Access\PermissionSet;
use App\Support\Banking\LastBankAccount;
use App\Support\Preferences\UserPreferences;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Bank register')] class extends Component {
    use WithPagination;

    /** Sortable columns => the direction a first click sorts in. */
    public const SORT_FIELDS = [
        'date' => 'desc',
        'entry' => 'desc',
        'memo' => 'asc',
        'payment' => 'desc',
        'deposit' => 'desc',
        'cleared' => 'asc',
    ];

    public const PER_PAGE = 50;

    public Company $company;

    #[Url(as: 'sort')]
    public string $sortField = 'date';

    #[Url(as: 'dir')]
    public string $sortDir = 'desc';

    public ?int $account_id = null;

    public bool $showCleared = true;

    /** The viewer's permissions, resolved once per request (not Livewire state). */
    private ?PermissionSet $viewerPermissions = null;

    public function mount(Company $company): void
    {
        $this->company = $company;

        if (request('account')) {
            // Another organization's account (or a missing one) is a 404.
            $account = $this->companyAccount((int) request('account'));
            abort_if($account === null, 404);

            $this->account_id = $account->id;
        } else {
            // Reopen on the account this operator last worked in anywhere in
            // Banking; fall back to the lowest-numbered active account.
            $first = Account::query()
                ->whereIn('subtype', [AccountSubtype::Bank->value, AccountSubtype::CreditCard->value])
                ->where('is_active', true)
                ->orderBy('code')
                ->first();
            $this->account_id = LastBankAccount::recall($company, $this->bankAccounts) ?? $first?->id;
        }

        LastBankAccount::remember($company, $this->account_id);

        $this->showCleared = (bool) UserPreferences::get($company, 'banking.register.show_cleared', true);
    }

    public function updatedAccountId(): void
    {
        // account_id arrives from the browser: only one of the organization's
        // own accounts is accepted.
        abort_if($this->account_id !== null && $this->companyAccount($this->account_id) === null, 404);

        unset($this->account);
        LastBankAccount::remember($this->company, $this->account_id);
        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        if (! array_key_exists($field, self::SORT_FIELDS)) {
            return;
        }

        if ($this->sortField === $field) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDir = self::SORT_FIELDS[$field];
        }

        $this->resetPage();
    }

    public function updatedShowCleared(): void
    {
        UserPreferences::put($this->company, 'banking.register.show_cleared', $this->showCleared);
        $this->resetPage();
    }

    /**
     * The register's account, resolved through the organization. journal_lines
     * carry no company_id and JournalLine has no CompanyScope, so every line
     * query keys on this account — never on the raw, client-held account_id.
     */
    #[Computed]
    public function account(): ?Account
    {
        return $this->account_id === null ? null : $this->companyAccount($this->account_id);
    }

    protected function companyAccount(int $id): ?Account
    {
        return Account::query()->whereBelongsTo($this->company)->find($id);
    }

    protected function linesQuery()
    {
        return JournalLine::query()
            ->where('account_id', $this->account?->id)
            ->where('is_posted', true);
    }

    /**
     * How the selected account's lines are counted: in its own currency, so a
     * foreign account's register reads like its statement. Null with no account.
     */
    #[Computed]
    public function amounts(): ?AccountCurrencyAmounts
    {
        $account = $this->account_id ? Account::query()->find($this->account_id) : null;

        return $account ? AccountCurrencyAmounts::for($account) : null;
    }

    /**
     * The account's currency when it isn't the home currency — the register's
     * amounts and balances are then in it — else null.
     */
    public function foreignCurrency(): ?string
    {
        return $this->amounts?->foreign ? $this->amounts->currencyCode : null;
    }

    #[Computed]
    public function lines()
    {
        return $this->linesQuery()
            ->with(['journalEntry'])
            // Hiding cleared rows leaves what is still outstanding, and a
            // voided cheque and its reversal never are: neither reaches the bank.
            ->when(! $this->showCleared, fn ($q) => $q->whereNull('cleared_at')->withoutUnsettledVoids())
            ->when($this->amounts, fn ($q, AccountCurrencyAmounts $amounts) => $amounts->withAmounts($q))
            ->tap(fn ($q) => $this->applySort($q))
            ->paginate(self::PER_PAGE);
    }

    /**
     * Every sort ends on a unique tiebreaker so rows sharing a date or amount
     * keep a stable order from one page load (and page) to the next.
     */
    protected function applySort($query): void
    {
        $field = array_key_exists($this->sortField, self::SORT_FIELDS) ? $this->sortField : 'date';
        $dir = $this->sortDir === 'asc' ? 'asc' : 'desc';

        match ($field) {
            'entry' => $query->orderBy(
                JournalEntry::query()->select('entry_no')->whereColumn('journal_entries.id', 'journal_lines.journal_entry_id'),
                $dir,
            ),
            // A line without its own memo shows (and so sorts by) its entry's memo.
            'memo' => $query->orderByRaw(
                "COALESCE(journal_lines.memo, (SELECT je.memo FROM journal_entries je WHERE je.id = journal_lines.journal_entry_id)) {$dir}"
            ),
            'payment' => $this->orderByAmount($query, 'payments', $dir),
            'deposit' => $this->orderByAmount($query, 'deposits', $dir),
            // asc = outstanding rows first, then cleared.
            'cleared' => $query->orderByRaw("CASE WHEN journal_lines.cleared_at IS NULL THEN 0 ELSE 1 END {$dir}"),
            default => $query->orderBy('journal_lines.entry_date', $dir),
        };

        $tieDir = $field === 'date' || $field === 'entry' ? $dir : 'desc';

        if ($field !== 'date') {
            $query->orderBy('journal_lines.entry_date', $tieDir);
        }

        $query->orderBy('journal_lines.id', $tieDir);
    }

    /**
     * Sort on the Payment or Deposit column: the home column on a
     * home-currency account, the amount in the account's own currency (money
     * out or money in) on a foreign one.
     */
    protected function orderByAmount($query, string $side, string $dir): void
    {
        if (! $this->amounts?->foreign) {
            $query->orderBy($side === 'payments' ? 'journal_lines.credit_cents' : 'journal_lines.debit_cents', $dir);

            return;
        }

        [$signed, $bindings] = $this->amounts->signedSql();
        $amount = $side === 'payments'
            ? "CASE WHEN {$signed} < 0 THEN -{$signed} ELSE 0 END"
            : "CASE WHEN {$signed} > 0 THEN {$signed} ELSE 0 END";

        $query->orderByRaw("{$amount} {$dir}", [...$bindings, ...$bindings]);
    }

    /**
     * Whether the line's payroll detail is withheld from this viewer
     * (PayrollDetail): a payroll cheque or remittance leg then reads
     * "Payroll", with no link to its entry. Its amount stays.
     */
    public function hidesLine(JournalLine $line): bool
    {
        $this->viewerPermissions ??= CurrentViewer::permissions($this->company);

        return PayrollDetail::hidesEntry($line->journalEntry, $this->viewerPermissions);
    }

    /** The line's memo as this viewer may see it. */
    public function lineMemo(JournalLine $line): ?string
    {
        return $this->hidesLine($line) ? PayrollDetail::label() : ($line->memo ?? $line->journalEntry?->memo);
    }

    /**
     * The account's balance after each line on the current page, always taken
     * in date order over every posted line — so it stays true whatever the
     * sort, page, or Show cleared setting. In the account's own currency.
     *
     * @return array<int, int> line id => running balance in cents
     */
    #[Computed]
    public function runningBalances(): array
    {
        $ids = $this->lines->pluck('id')->all();

        if ($ids === [] || $this->amounts === null) {
            return [];
        }

        [$signed, $bindings] = $this->amounts->signedSql();

        // Each line's amount first, then the running sum over those, so the
        // window function only ever adds up a plain column.
        $lineAmounts = $this->linesQuery()
            ->select(['journal_lines.id', 'journal_lines.entry_date'])
            ->selectRaw("{$signed} AS amount_cents", $bindings);

        $running = DB::query()
            ->fromSub($lineAmounts, 'rl')
            ->select('rl.id')
            ->selectRaw('SUM(rl.amount_cents) OVER (ORDER BY rl.entry_date, rl.id) AS balance_cents');

        return DB::query()
            ->fromSub($running, 'rb')
            ->whereIn('rb.id', $ids)
            ->pluck('rb.balance_cents', 'rb.id')
            ->map(fn ($cents) => (int) $cents)
            ->all();
    }

    /**
     * Ids of the rows that are one half of a void nobody has cleared, so the
     * register can mark them void rather than as waiting to clear.
     *
     * @return array<int, true>
     */
    #[Computed]
    public function voidLineIds(): array
    {
        return $this->linesQuery()
            ->unsettledVoids()
            ->pluck('id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    #[Computed]
    public function bankAccounts()
    {
        return Account::query()
            ->whereIn('subtype', [AccountSubtype::Bank->value, AccountSubtype::CreditCard->value])
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'subtype']);
    }

    /**
     * What has cleared the bank so far, in the account's own currency.
     */
    public function clearedBalanceCents(): int
    {
        return $this->amounts?->sum($this->linesQuery()->whereNotNull('cleared_at')) ?? 0;
    }

    /**
     * Every posted line, in the account's own currency.
     */
    public function ledgerBalanceCents(): int
    {
        return $this->amounts?->sum($this->linesQuery()) ?? 0;
    }

    /**
     * Every posted line in home cents: the account's general-ledger balance.
     * The same as {@see ledgerBalanceCents()} on a home-currency account.
     */
    public function homeLedgerBalanceCents(): int
    {
        return (int) $this->linesQuery()
            ->selectRaw('COALESCE(SUM(debit_cents - credit_cents), 0) AS bal')
            ->value('bal');
    }
}; ?>

<section class="w-full">
    @php
        $foreignCurrency = $this->foreignCurrency();
        $amounts = $this->amounts;
    @endphp

    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Bank register') }}</flux:heading>
            <flux:subheading>{{ __(':label. Cleared rows are ticked off during reconciliation.', ['label' => $company->jurisdiction->chequeLabel('checkbook')]) }}</flux:subheading>
            @if ($foreignCurrency)
                <flux:text class="mt-1 text-sm" data-test="account-currency-notice">{{ __('Amounts are in :currency, the currency of this account and its statements.', ['currency' => $foreignCurrency]) }}</flux:text>
            @endif
        </div>

        <div class="flex items-end gap-3">
            <flux:select wire:model.live="account_id" :label="__('Account')" class="min-w-[260px]">
                @foreach ($this->bankAccounts as $opt)
                    <flux:select.option :value="$opt->id">{{ $opt->code }} — {{ $opt->displayName() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:dropdown align="end">
                <flux:button variant="primary" icon:trailing="chevron-down" data-test="bank-register-actions-menu">{{ __('Actions') }}</flux:button>
                <flux:menu>
                    <flux:menu.item icon="check-circle" :href="route('banking.reconcile', ['company' => $company->slug, 'account' => $account_id])" wire:navigate data-test="reconcile-menu-item">
                        {{ __('Reconcile') }}
                    </flux:menu.item>
                    <flux:menu.item icon="arrow-up-tray" :href="route('banking.import', ['company' => $company->slug])" wire:navigate data-test="import-statement-menu-item">
                        {{ __('Import statement') }}
                    </flux:menu.item>
                    <flux:menu.item icon="funnel" :href="route('banking.rules', ['company' => $company->slug])" wire:navigate data-test="bank-rules-menu-item">
                        {{ __('Bank rules') }}
                    </flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div class="rounded-lg border border-border p-3">
            <flux:text class="text-muted-foreground">{{ __('Ledger balance') }}</flux:text>
            <div class="text-lg font-mono">{{ number_format($this->ledgerBalanceCents() / 100, 2) }}</div>
            @if ($foreignCurrency)
                <flux:text class="text-xs text-muted-foreground" data-test="home-ledger-balance">{{ __(':amount in the general ledger', ['amount' => number_format($this->homeLedgerBalanceCents() / 100, 2).' '.$company->currency_code]) }}</flux:text>
            @endif
        </div>
        <div class="rounded-lg border border-border p-3">
            <flux:text class="text-muted-foreground">{{ __('Cleared balance') }}</flux:text>
            <div class="text-lg font-mono" data-test="cleared-balance">{{ number_format($this->clearedBalanceCents() / 100, 2) }}</div>
        </div>
    </div>

    <div class="mb-4 flex items-center gap-4">
        <flux:switch wire:model.live="showCleared" :label="__('Show cleared')" />
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-3 py-2 w-24"><x-sort-header field="cleared" :current-field="$sortField" :current-dir="$sortDir" :label="__('Cleared')" /></th>
                    <th class="px-3 py-2 text-left"><x-sort-header field="date" :current-field="$sortField" :current-dir="$sortDir" :label="__('Date')" /></th>
                    <th class="px-3 py-2 text-left"><x-sort-header field="entry" :current-field="$sortField" :current-dir="$sortDir" :label="__('Entry #')" /></th>
                    <th class="px-3 py-2 text-left"><x-sort-header field="memo" :current-field="$sortField" :current-dir="$sortDir" :label="__('Memo')" /></th>
                    <th class="px-3 py-2 text-right"><x-sort-header field="payment" :current-field="$sortField" :current-dir="$sortDir" :label="__('Payment')" align="right" /></th>
                    <th class="px-3 py-2 text-right"><x-sort-header field="deposit" :current-field="$sortField" :current-dir="$sortDir" :label="__('Deposit')" align="right" /></th>
                    <th class="px-3 py-2 text-right">{{ __('Balance') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->lines as $line)
                    @php $isVoid = isset($this->voidLineIds[$line->id]); @endphp
                    <tr data-test="register-row" class="@if ($line->cleared_at) bg-green-50 dark:bg-green-900/10 @elseif ($isVoid) text-muted-foreground @endif">
                        <td class="px-3 py-2 text-center">
                            @if ($line->cleared_at)
                                <flux:icon name="check" variant="micro" class="size-4 text-green-600" title="{{ __('Cleared') }}" data-test="register-cleared-mark" />
                            @elseif ($isVoid)
                                <flux:icon name="no-symbol" variant="micro" class="size-4 text-muted-foreground" title="{{ __('Voided — this and its reversal cancel out and never reach the bank') }}" data-test="register-void-mark" />
                            @else
                                <span class="inline-block size-4 rounded border border-border" title="{{ __('Not cleared') }}"></span>
                            @endif
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">{{ $line->journalEntry->entry_date->toDateString() }}</td>
                        <td class="px-3 py-2 font-mono">
                            @if ($this->hidesLine($line))
                                {{ $line->journalEntry->entry_no }}
                            @else
                                <a href="{{ route('journal.show', ['company' => $company->slug, 'entry' => $line->journalEntry->id]) }}" wire:navigate class="underline">{{ $line->journalEntry->entry_no }}</a>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-muted-foreground">{{ $this->lineMemo($line) }}</td>
                        @if ($foreignCurrency)
                            @foreach (['payments', 'deposits'] as $side)
                                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">
                                    @if ($amounts->isOnSide($line, $side))
                                        @if ($amounts->isMissing($line))
                                            <flux:icon name="exclamation-triangle" variant="micro" class="inline size-4 text-amber-600" title="{{ __('No :currency amount was recorded for this transaction, so it counts as 0.00 here. Its home value is :amount.', ['currency' => $foreignCurrency, 'amount' => number_format(abs((int) $line->debit_cents - (int) $line->credit_cents) / 100, 2).' '.$company->currency_code]) }}" data-test="missing-account-amount" />
                                        @endif
                                        {{ number_format($amounts->sideCents($line, $side) / 100, 2) }}
                                    @endif
                                </td>
                            @endforeach
                        @else
                            <td class="px-3 py-2 text-right font-mono">{{ $line->credit_cents > 0 ? number_format($line->credit_cents / 100, 2) : '' }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ $line->debit_cents > 0 ? number_format($line->debit_cents / 100, 2) : '' }}</td>
                        @endif
                        <td class="px-3 py-2 text-right font-mono" data-test="register-balance">{{ number_format(($this->runningBalances[$line->id] ?? 0) / 100, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-3 py-8 text-center text-muted-foreground">{{ __('No transactions on this account.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->lines->links() }}</div>
</section>
