<?php

namespace App\Services\Reporting;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Enums\ReportStatement;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\ReportSection;
use App\Models\TaxAgency;
use App\Support\Reporting\CashFlowBucket;
use App\Support\Reporting\SectionPartitioner;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pure read service. Computes balances and activity from posted journal lines.
 * The cached balance_cents on accounts is *only* a hint — every report here
 * recomputes from the underlying ledger so it's always correct.
 *
 * Returned values are signed in the account's natural-balance direction:
 *   Debit-normal account (assets, expenses): positive = debit balance
 *   Credit-normal account (liab, equity, income): positive = credit balance
 */
class ReportCalculator
{
    /**
     * Net balance for an account as of the end of the given date (inclusive).
     */
    public function balanceAsOf(Account $account, CarbonInterface $date, ?int $fundId = null): int
    {
        return $this->signedToNatural($account, $this->rawBalanceAsOf($account, $date, $fundId));
    }

    /**
     * Balance for a report presented the QuickBooks way: balance-sheet accounts
     * (asset / liability / equity) carry forward cumulatively, while income and
     * expense accounts reset at the start of each fiscal year — so a P&L account
     * shows only its current-fiscal-year activity. Prior-year P&L is rolled into
     * Retained Earnings via {@see priorRetainedEarnings()} rather than lingering
     * on the income statement accounts.
     */
    public function reportingBalanceAsOf(Company $company, Account $account, CarbonInterface $asOf): int
    {
        if ($account->type === AccountType::Income || $account->type === AccountType::Expense) {
            return $this->periodChange($account, $this->fiscalYearStart($company, $asOf), $asOf);
        }

        return $this->balanceAsOf($account, $asOf);
    }

    /**
     * Net income (income − expense) accumulated in every fiscal year *before* the
     * one containing $asOf. This is the prior-period profit that QuickBooks rolls
     * into Retained Earnings; LineLedger posts no closing entries, so reports add
     * it to equity dynamically to keep the books balanced across years.
     */
    public function priorRetainedEarnings(Company $company, CarbonInterface $asOf): int
    {
        $dayBeforeFiscalYear = $this->fiscalYearStart($company, $asOf)->subDay();

        $income = $this->totalForTypeAsOf($company, AccountType::Income, $dayBeforeFiscalYear);
        $expense = $this->totalForTypeAsOf($company, AccountType::Expense, $dayBeforeFiscalYear);

        return $income - $expense;
    }

    /**
     * Cumulative natural-balance total for all accounts of a type as of a date.
     */
    public function totalForTypeAsOf(Company $company, AccountType $type, CarbonInterface $asOf): int
    {
        return $this->sumNaturalForType(

            $company,
            $type,
            fn ($query) => $query->where('journal_lines.entry_date', '<=', $asOf),
        );
    }

    /**
     * Change in account balance over a period (used for I/E). Optionally restricted
     * to journal lines tagged with a given class and/or location dimension.
     */
    public function periodChange(Account $account, CarbonInterface $start, CarbonInterface $end, ?int $classId = null, ?int $locationId = null, ?int $fundId = null): int
    {
        return $this->signedToNatural($account, $this->rawPeriodChange($account, $start, $end, $classId, $locationId, $fundId));
    }

    /**
     * Raw (debit − credit) balance as of the end of the given date, with NO
     * natural-balance conversion. Positive = net debit. Used to combine accounts
     * across companies where the *target line's* type — not the source account's —
     * decides the presentation sign.
     */
    public function rawBalanceAsOf(Account $account, CarbonInterface $date, ?int $fundId = null): int
    {
        $query = JournalLine::query()
            ->where('account_id', $account->id)
            ->where('is_posted', true)
            ->where('entry_date', '<=', $date);

        if ($fundId !== null) {
            $query->where('fund_id', $fundId);
        }

        return (int) $query
            ->selectRaw('COALESCE(SUM(debit_cents - credit_cents), 0) AS bal')
            ->value('bal');
    }

    /**
     * Raw (debit − credit) change over a period, with NO natural-balance conversion.
     * See {@see rawBalanceAsOf()} for why the raw form is needed when combining accounts.
     */
    public function rawPeriodChange(Account $account, CarbonInterface $start, CarbonInterface $end, ?int $classId = null, ?int $locationId = null, ?int $fundId = null): int
    {
        $query = JournalLine::query()
            ->where('account_id', $account->id)
            ->where('is_posted', true)
            ->whereBetween('entry_date', [$start, $end]);

        if ($classId !== null) {
            $query->where('class_id', $classId);
        }

        if ($locationId !== null) {
            $query->where('location_id', $locationId);
        }

        if ($fundId !== null) {
            $query->where('fund_id', $fundId);
        }

        return (int) $query
            ->selectRaw('COALESCE(SUM(debit_cents - credit_cents), 0) AS bal')
            ->value('bal');
    }

    /**
     * Year-to-date net income for the company up to the given date.
     * Income (credit normal) – Expense (debit normal) → positive when profitable.
     */
    public function netIncomeYtd(Company $company, CarbonInterface $asOf): int
    {
        $startOfYear = $this->fiscalYearStart($company, $asOf);

        $income = $this->totalForType($company, AccountType::Income, $startOfYear, $asOf);
        $expense = $this->totalForType($company, AccountType::Expense, $startOfYear, $asOf);

        return $income - $expense;
    }

    /**
     * Total natural-balance for all accounts of a given type over a period.
     */
    public function totalForType(Company $company, AccountType $type, CarbonInterface $start, CarbonInterface $end): int
    {
        return $this->sumNaturalForType(
            $company,
            $type,
            fn ($query) => $query->whereBetween('journal_lines.entry_date', [$start, $end]),
        );
    }

    /**
     * Roll-forward of net assets by class for the ASNPO Statement of Changes in
     * Net Assets. Net-asset classes map from equity subtype:
     *   - unrestricted: UnrestrictedNetAssets + generic Equity + RetainedEarnings
     *   - restricted:   RestrictedNetAssets
     *   - endowment:    EndowmentNetAssets (only surfaced when present)
     *
     * For each class: opening (balance the day before $start) + excess/(deficiency)
     * of revenue over expenses for the period + other changes (direct postings to
     * the class's net-asset accounts — endowment contributions, interfund
     * transfers) = closing. The period excess lands in unrestricted, mirroring how
     * the balance sheet folds prior + current net income into equity, so the
     * statement always ties to the Statement of Financial Position's total net
     * assets and reconciles by construction.
     *
     * @return array{
     *   classes: array<string, array{label: string, opening: int, excess: int, other: int, closing: int}>,
     *   total: array{opening: int, excess: int, other: int, closing: int},
     *   reconciles: bool,
     * }
     */
    public function netAssetRollForward(Company $company, CarbonInterface $start, CarbonInterface $end): array
    {
        $beforeStart = CarbonImmutable::parse($start)->subDay();

        $classOf = [
            AccountSubtype::UnrestrictedNetAssets->value => 'unrestricted',
            AccountSubtype::Equity->value => 'unrestricted',
            AccountSubtype::RetainedEarnings->value => 'unrestricted',
            AccountSubtype::RestrictedNetAssets->value => 'restricted',
            AccountSubtype::EndowmentNetAssets->value => 'endowment',
        ];

        $accounts = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('type', AccountType::Equity->value)
            ->get();

        $opening = ['unrestricted' => 0, 'restricted' => 0, 'endowment' => 0];
        $closing = ['unrestricted' => 0, 'restricted' => 0, 'endowment' => 0];

        foreach ($accounts as $account) {
            $class = $classOf[$account->subtype->value] ?? 'unrestricted';
            $opening[$class] += $this->balanceAsOf($account, $beforeStart);
            $closing[$class] += $this->balanceAsOf($account, $end);
        }

        // Accumulated surplus (un-posted net income — LineLedger posts no closing
        // entries) belongs to unrestricted net assets.
        $opening['unrestricted'] += $this->accumulatedSurplus($company, $beforeStart);
        $closing['unrestricted'] += $this->accumulatedSurplus($company, $end);

        // Period excess of revenue over expenses, recognized into unrestricted.
        $excess = $this->totalForType($company, AccountType::Income, $start, $end)
            - $this->totalForType($company, AccountType::Expense, $start, $end);

        $labels = [
            'unrestricted' => __('Unrestricted net assets'),
            'restricted' => __('Restricted net assets'),
        ];

        $hasEndowment = $accounts->contains(fn (Account $a) => $a->subtype === AccountSubtype::EndowmentNetAssets);
        if ($hasEndowment || $opening['endowment'] !== 0 || $closing['endowment'] !== 0) {
            $labels['endowment'] = __('Endowment net assets');
        }

        $classes = [];
        $totalOpening = $totalExcess = $totalOther = $totalClosing = 0;

        foreach ($labels as $key => $label) {
            $classExcess = $key === 'unrestricted' ? $excess : 0;
            $other = $closing[$key] - $opening[$key] - $classExcess;

            $classes[$key] = [
                'label' => $label,
                'opening' => $opening[$key],
                'excess' => $classExcess,
                'other' => $other,
                'closing' => $closing[$key],
            ];

            $totalOpening += $opening[$key];
            $totalExcess += $classExcess;
            $totalOther += $other;
            $totalClosing += $closing[$key];
        }

        return [
            'classes' => $classes,
            'total' => ['opening' => $totalOpening, 'excess' => $totalExcess, 'other' => $totalOther, 'closing' => $totalClosing],
            'reconciles' => ($totalOpening + $totalExcess + $totalOther) === $totalClosing,
        ];
    }

    /**
     * Cumulative net income (income − expense) across all time up to $asOf. Equals
     * priorRetainedEarnings($asOf) + netIncomeYtd($asOf); used to fold un-posted
     * surplus into unrestricted net assets.
     */
    private function accumulatedSurplus(Company $company, CarbonInterface $asOf): int
    {
        return $this->totalForTypeAsOf($company, AccountType::Income, $asOf)
            - $this->totalForTypeAsOf($company, AccountType::Expense, $asOf);
    }

    /**
     * Natural-balance total across every account of a type in one grouped query,
     * rather than a per-account aggregate (which is an N+1 over the chart of
     * accounts on hot paths like the dashboard and income statement). Grouping by
     * `normal_balance` reproduces the per-account {@see signedToNatural()} sign
     * exactly, since the sum is linear and every row in a group shares a sign.
     * Soft-deleted accounts are included to match the previous
     * `Account::withoutGlobalScopes()` behaviour.
     *
     * @param  Closure(Builder<JournalLine>): mixed  $dateConstraint
     */
    private function sumNaturalForType(Company $company, AccountType $type, Closure $dateConstraint): int
    {
        $query = JournalLine::query()
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('accounts.company_id', $company->id)
            ->where('accounts.type', $type->value)
            ->where('journal_lines.is_posted', true)
            ->groupBy('accounts.normal_balance')
            ->selectRaw('accounts.normal_balance AS normal_balance, COALESCE(SUM(journal_lines.debit_cents - journal_lines.credit_cents), 0) AS signed_total');

        $dateConstraint($query);

        $total = 0;

        foreach ($query->get() as $row) {
            $signed = (int) $row->signed_total;
            $total += $row->normal_balance === NormalBalance::Debit->value ? $signed : -$signed;
        }

        return $total;
    }

    /**
     * Indirect Statement of Cash Flows for a period.
     *
     * Cash is every {@see AccountSubtype::Bank} account. Because each posted entry
     * is balanced, the period change in cash equals the negated raw (debit − credit)
     * change of every other account — so net income (collapsing all P&L) plus the
     * negated change of each non-cash balance-sheet account always reconciles to the
     * bank balance movement. Activity-row values use a uniform sign (positive = source
     * of cash) regardless of account type, so no natural-balance conversion is applied.
     *
     * Each activity is an ordered list of section/unassigned blocks (see
     * {@see SectionPartitioner}); sectioning only regroups rows, never changes totals.
     *
     * @return array{
     *   operating: array<int, array<string, mixed>>,
     *   investing: array<int, array<string, mixed>>,
     *   financing: array<int, array<string, mixed>>,
     *   net_income: int, total_operating: int, total_investing: int, total_financing: int,
     *   net_change: int, cash_beginning: int, cash_ending: int, reconciles: bool,
     *   prior_net_income: int, prior_total_operating: int, prior_total_investing: int,
     *   prior_total_financing: int, prior_net_change: int, prior_cash_beginning: int, prior_cash_ending: int,
     * }
     */
    public function cashFlow(Company $company, CarbonInterface $start, CarbonInterface $end, bool $comparison = false, ?CarbonInterface $priorStart = null, ?CarbonInterface $priorEnd = null): array
    {
        $start = CarbonImmutable::parse($start);
        $end = CarbonImmutable::parse($end);

        // Prior comparison period: caller-supplied range when given, otherwise
        // the same calendar range one year earlier (QuickBooks "previous year").
        $priorStart = $priorStart !== null ? CarbonImmutable::parse($priorStart) : $start->subYear();
        $priorEnd = $priorEnd !== null ? CarbonImmutable::parse($priorEnd) : $end->subYear();

        $accounts = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereIn('type', [AccountType::Asset->value, AccountType::Liability->value, AccountType::Equity->value])
            ->orderBy('code')
            ->get();

        $sections = ReportSection::query()
            ->where('company_id', $company->id)
            ->where('statement', ReportStatement::CashFlow->value)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('group_key');

        $buckets = ['operating' => [], 'investing' => [], 'financing' => []];
        $activityTotals = ['operating' => 0, 'investing' => 0, 'financing' => 0];
        $priorActivityTotals = ['operating' => 0, 'investing' => 0, 'financing' => 0];

        $cashBeginning = 0;
        $cashEnding = 0;
        $priorCashBeginning = 0;
        $priorCashEnding = 0;

        foreach ($accounts as $account) {
            if ($account->subtype === AccountSubtype::Bank) {
                $cashBeginning += $this->balanceAsOf($account, $start->subDay());
                $cashEnding += $this->balanceAsOf($account, $end);

                if ($comparison) {
                    $priorCashBeginning += $this->balanceAsOf($account, $priorStart->subDay());
                    $priorCashEnding += $this->balanceAsOf($account, $priorEnd);
                }

                continue;
            }

            $activity = CashFlowBucket::for($account);

            if ($activity === null) {
                continue;
            }

            $current = -$this->rawPeriodChange($account, $start, $end);
            $prior = $comparison ? -$this->rawPeriodChange($account, $priorStart, $priorEnd) : 0;

            if ($current === 0 && $prior === 0) {
                continue;
            }

            $buckets[$activity][] = [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'current' => $current,
                'prior' => $prior,
                'section_id' => $account->report_section_id,
            ];

            $activityTotals[$activity] += $current;
            $priorActivityTotals[$activity] += $prior;
        }

        $netIncome = $this->totalForType($company, AccountType::Income, $start, $end)
            - $this->totalForType($company, AccountType::Expense, $start, $end);
        $priorNetIncome = $comparison
            ? $this->totalForType($company, AccountType::Income, $priorStart, $priorEnd)
                - $this->totalForType($company, AccountType::Expense, $priorStart, $priorEnd)
            : 0;

        $partition = fn (string $key): array => SectionPartitioner::partition(
            $sections[$key] ?? collect(),
            $buckets[$key],
            'current',
        );

        $totalOperating = $netIncome + $activityTotals['operating'];
        $netChange = $totalOperating + $activityTotals['investing'] + $activityTotals['financing'];

        $priorTotalOperating = $priorNetIncome + $priorActivityTotals['operating'];
        $priorNetChange = $priorTotalOperating + $priorActivityTotals['investing'] + $priorActivityTotals['financing'];

        return [
            'operating' => $partition('operating'),
            'investing' => $partition('investing'),
            'financing' => $partition('financing'),
            'net_income' => $netIncome,
            'total_operating' => $totalOperating,
            'total_investing' => $activityTotals['investing'],
            'total_financing' => $activityTotals['financing'],
            'net_change' => $netChange,
            'cash_beginning' => $cashBeginning,
            'cash_ending' => $cashEnding,
            'reconciles' => $cashBeginning + $netChange === $cashEnding,
            'prior_net_income' => $priorNetIncome,
            'prior_total_operating' => $priorTotalOperating,
            'prior_total_investing' => $priorActivityTotals['investing'],
            'prior_total_financing' => $priorActivityTotals['financing'],
            'prior_net_change' => $priorNetChange,
            'prior_cash_beginning' => $priorCashBeginning,
            'prior_cash_ending' => $priorCashEnding,
        ];
    }

    /**
     * @return Collection<int, array{account: Account, balance: int}>
     */
    public function trialBalance(Company $company, CarbonInterface $asOf): Collection
    {
        $accounts = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->orderBy('code')
            ->get();

        // Prior-year P&L is rolled into the Retained Earnings account so the trial
        // balance stays balanced once income/expense accounts are scoped to the
        // current fiscal year.
        $retainedEarnings = $accounts->first(fn (Account $a) => $a->subtype === AccountSubtype::RetainedEarnings);
        $priorEarnings = $this->priorRetainedEarnings($company, $asOf);

        return $accounts->map(function (Account $a) use ($company, $asOf, $retainedEarnings, $priorEarnings) {
            $balance = $this->reportingBalanceAsOf($company, $a, $asOf);

            if ($retainedEarnings !== null && $a->id === $retainedEarnings->id) {
                $balance += $priorEarnings;
            }

            return ['account' => $a, 'balance' => $balance];
        })->filter(fn ($row) => $row['balance'] !== 0)->values();
    }

    /**
     * @return array{lines: Collection<int, array{date: string, entry_no: string, memo: string, debit: int, credit: int, running: int}>, opening: int, closing: int}
     */
    public function generalLedger(Account $account, CarbonInterface $start, CarbonInterface $end): array
    {
        $dayBeforeStart = CarbonImmutable::parse($start)->subDay();

        // P&L accounts reset at the fiscal year start (QuickBooks behaviour): the
        // opening balance is only the activity from the fiscal-year start up to the
        // day before the report window, never cumulative prior-year activity.
        if ($account->type === AccountType::Income || $account->type === AccountType::Expense) {
            $fiscalYearStart = $this->fiscalYearStart($account->company, CarbonImmutable::parse($start));
            $opening = $this->periodChange($account, $fiscalYearStart, $dayBeforeStart);
        } else {
            $opening = $this->balanceAsOf($account, $dayBeforeStart);
        }

        $rows = JournalLine::query()
            ->where('account_id', $account->id)
            ->whereHas('journalEntry', fn ($q) => $q
                ->where('is_posted', true)
                ->whereBetween('entry_date', [$start, $end])
            )
            ->with('journalEntry')
            ->get()
            ->sortBy(fn ($l) => [$l->journalEntry->entry_date->format('Y-m-d'), $l->id])
            ->values();

        $signMultiplier = $account->normal_balance === NormalBalance::Debit ? 1 : -1;

        $running = $opening;
        $lines = [];

        foreach ($rows as $line) {
            $delta = ((int) $line->debit_cents - (int) $line->credit_cents) * $signMultiplier;
            $running += $delta;

            $lines[] = [
                'date' => $line->journalEntry->entry_date->toDateString(),
                'entry_no' => $line->journalEntry->entry_no,
                'memo' => $line->memo ?? $line->journalEntry->memo,
                'debit' => (int) $line->debit_cents,
                'credit' => (int) $line->credit_cents,
                'running' => $running,
            ];
        }

        return [
            'lines' => collect($lines),
            'opening' => $opening,
            'closing' => $running,
        ];
    }

    /**
     * General ledger across ALL accounts in the date range, grouped by journal entry.
     * Each entry section lists every line on that entry with its account, debit, credit,
     * and memo. Per-entry totals are included so consumers can render or audit easily.
     *
     * @return array{
     *     entries: Collection<int, array{
     *         entry_no: string,
     *         date: string,
     *         memo: ?string,
     *         total_debit: int,
     *         total_credit: int,
     *         lines: array<int, array{
     *             account_code: string,
     *             account_name: string,
     *             memo: ?string,
     *             debit: int,
     *             credit: int,
     *         }>
     *     }>,
     *     total_debit: int,
     *     total_credit: int,
     *     entry_count: int,
     *     line_count: int,
     * }
     */
    public function generalLedgerAllAccounts(CarbonInterface $start, CarbonInterface $end): array
    {
        $entries = JournalEntry::query()
            ->where('is_posted', true)
            ->whereBetween('entry_date', [$start, $end])
            ->with(['lines' => fn ($q) => $q->orderBy('line_order')->orderBy('id'), 'lines.account'])
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get();

        $totalDebit = 0;
        $totalCredit = 0;
        $lineCount = 0;

        $rows = $entries->map(function (JournalEntry $entry) use (&$totalDebit, &$totalCredit, &$lineCount) {
            $entryDebit = 0;
            $entryCredit = 0;

            $lines = $entry->lines->map(function (JournalLine $line) use (&$entryDebit, &$entryCredit) {
                $debit = (int) $line->debit_cents;
                $credit = (int) $line->credit_cents;

                $entryDebit += $debit;
                $entryCredit += $credit;

                return [
                    'account_code' => (string) ($line->account->code ?? ''),
                    'account_name' => (string) ($line->account->name ?? ''),
                    'memo' => $line->memo,
                    'debit' => $debit,
                    'credit' => $credit,
                ];
            })->all();

            $totalDebit += $entryDebit;
            $totalCredit += $entryCredit;
            $lineCount += count($lines);

            return [
                'entry_no' => (string) $entry->entry_no,
                'date' => $entry->entry_date->toDateString(),
                'memo' => $entry->memo,
                'total_debit' => $entryDebit,
                'total_credit' => $entryCredit,
                'lines' => $lines,
            ];
        });

        return [
            'entries' => $rows,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'entry_count' => $rows->count(),
            'line_count' => $lineCount,
        ];
    }

    /**
     * Single-account general ledger, paginated for on-screen display. Running balances
     * stay globally correct: each page's first row continues from the period opening plus
     * the signed delta of every line before it, so paging never resets the balance.
     *
     * @return array{lines: Collection, paginator: LengthAwarePaginator, opening: int, page_opening: int, closing: int}
     */
    public function generalLedgerPaginated(Account $account, CarbonInterface $start, CarbonInterface $end, int $perPage, ?int $classId = null, ?int $locationId = null): array
    {
        $opening = $this->generalLedgerOpening($account, $start);

        $signMultiplier = $account->normal_balance === NormalBalance::Debit ? 1 : -1;

        // Filters on the denormalized columns hit journal_lines_balance_idx directly.
        $base = fn () => JournalLine::query()
            ->where('account_id', $account->id)
            ->where('is_posted', true)
            ->whereBetween('entry_date', [$start, $end])
            ->when($classId !== null, fn ($q) => $q->where('class_id', $classId))
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId));

        $paginator = $base()
            ->orderBy('entry_date')->orderBy('id')
            ->with('journalEntry.lines.account')
            ->paginate($perPage);

        // Period close = opening plus the signed delta of every line in the window.
        $netInRange = (int) $base()->sum(DB::raw('debit_cents - credit_cents'));
        $closing = $opening + $netInRange * $signMultiplier;

        // This page's opening = period opening plus the signed delta of every line before it.
        $skip = ($paginator->currentPage() - 1) * $paginator->perPage();
        $beforeNet = $skip > 0
            ? (int) DB::query()->fromSub(
                $base()->select(DB::raw('debit_cents - credit_cents as dc'))
                    ->orderBy('entry_date')->orderBy('id')->limit($skip),
                'before',
            )->sum('dc')
            : 0;
        $pageOpening = $opening + $beforeNet * $signMultiplier;

        $running = $pageOpening;
        $lines = [];

        foreach ($paginator->items() as $line) {
            $running += ((int) $line->debit_cents - (int) $line->credit_cents) * $signMultiplier;

            $lines[] = [
                'date' => $line->journalEntry->entry_date->toDateString(),
                'entry_no' => $line->journalEntry->entry_no,
                'memo' => $line->memo ?? $line->journalEntry->memo,
                'split' => $this->contraLabel($line, $account),
                'debit' => (int) $line->debit_cents,
                'credit' => (int) $line->credit_cents,
                'running' => $running,
            ];
        }

        return [
            'lines' => collect($lines),
            'paginator' => $paginator,
            'opening' => $opening,
            'page_opening' => $pageOpening,
            'closing' => $closing,
        ];
    }

    /**
     * The contra ("split") account label for one ledger line: the account on the
     * other side(s) of its journal entry. A single counterpart shows that
     * account's name; two or more distinct counterparts show "—Split—" (the
     * QuickBooks convention). Requires the line's journalEntry.lines.account to
     * be eager-loaded.
     */
    private function contraLabel(JournalLine $line, Account $account): string
    {
        $contra = $line->journalEntry->lines
            ->filter(fn (JournalLine $other): bool => $other->account_id !== $account->id)
            ->map(fn (JournalLine $other) => $other->account)
            ->filter()
            ->unique('id')
            ->values();

        if ($contra->isEmpty()) {
            return '';
        }

        return $contra->count() === 1 ? (string) $contra->first()->name : __('—Split—');
    }

    /**
     * All-accounts general ledger, paginated by journal entry for on-screen display.
     * Grand totals and counts are computed by aggregate query over the whole range so
     * they stay correct regardless of which page is shown.
     *
     * @return array{entries: Collection, paginator: LengthAwarePaginator, total_debit: int, total_credit: int, entry_count: int, line_count: int}
     */
    public function generalLedgerAllAccountsPaginated(CarbonInterface $start, CarbonInterface $end, int $perPage, ?int $classId = null, ?int $locationId = null): array
    {
        // When a dimension filter is active, only entries with at least one matching
        // line are returned (whereHas), and only the matching lines are eager-loaded —
        // so totals and rows reflect the filtered slice, not the full entry.
        $lineFilter = fn ($q) => $q
            ->when($classId !== null, fn ($w) => $w->where('class_id', $classId))
            ->when($locationId !== null, fn ($w) => $w->where('location_id', $locationId));

        $paginator = JournalEntry::query()
            ->where('is_posted', true)
            ->whereBetween('entry_date', [$start, $end])
            ->when($classId !== null || $locationId !== null, fn ($q) => $q->whereHas('lines', $lineFilter))
            ->with(['lines' => fn ($q) => $lineFilter($q)->orderBy('line_order')->orderBy('id'), 'lines.account'])
            ->orderBy('entry_date')
            ->orderBy('id')
            ->paginate($perPage);

        $entries = collect($paginator->items())->map(function (JournalEntry $entry) {
            $entryDebit = 0;
            $entryCredit = 0;

            $lines = $entry->lines->map(function (JournalLine $line) use (&$entryDebit, &$entryCredit) {
                $debit = (int) $line->debit_cents;
                $credit = (int) $line->credit_cents;

                $entryDebit += $debit;
                $entryCredit += $credit;

                return [
                    'account_code' => (string) ($line->account->code ?? ''),
                    'account_name' => (string) ($line->account->name ?? ''),
                    'memo' => $line->memo,
                    'debit' => $debit,
                    'credit' => $credit,
                ];
            })->all();

            return [
                'entry_no' => (string) $entry->entry_no,
                'date' => $entry->entry_date->toDateString(),
                'memo' => $entry->memo,
                'total_debit' => $entryDebit,
                'total_credit' => $entryCredit,
                'lines' => $lines,
            ];
        });

        $totals = JournalLine::query()
            ->where('is_posted', true)
            ->whereBetween('entry_date', [$start, $end])
            ->when($classId !== null, fn ($q) => $q->where('class_id', $classId))
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))
            ->selectRaw('COALESCE(SUM(debit_cents), 0) as d, COALESCE(SUM(credit_cents), 0) as c, COUNT(*) as n')
            ->first();

        return [
            'entries' => $entries,
            'paginator' => $paginator,
            'total_debit' => (int) $totals->d,
            'total_credit' => (int) $totals->c,
            'entry_count' => $paginator->total(),
            'line_count' => (int) $totals->n,
        ];
    }

    /**
     * Opening balance for a single-account general ledger as of the day before $start.
     * P&L accounts reset at the fiscal-year start (QuickBooks behaviour) — the opening is
     * only the fiscal-year-to-date change, never cumulative prior-year activity.
     */
    public function generalLedgerOpening(Account $account, CarbonInterface $start): int
    {
        $dayBeforeStart = CarbonImmutable::parse($start)->subDay();

        if ($account->type === AccountType::Income || $account->type === AccountType::Expense) {
            $fiscalYearStart = $this->fiscalYearStart($account->company, CarbonImmutable::parse($start));

            return $this->periodChange($account, $fiscalYearStart, $dayBeforeStart);
        }

        return $this->balanceAsOf($account, $dayBeforeStart);
    }

    /**
     * Single-account general ledger for export — the full range with no pagination, but
     * memory-flat. The lines are a generator backed by keyset chunking on (entry_date, id),
     * so a 30-year account streams to the export writer without ever materialising. Scalars
     * (opening, closing) are computed up front by cheap aggregates.
     *
     * @return array{lines: \Generator<int, array{date: string, entry_no: string, memo: ?string, debit: int, credit: int, running: int}>, opening: int, closing: int}
     */
    public function generalLedgerStreamReport(Account $account, CarbonInterface $start, CarbonInterface $end, ?int $classId = null, ?int $locationId = null): array
    {
        $opening = $this->generalLedgerOpening($account, $start);
        $signMultiplier = $account->normal_balance === NormalBalance::Debit ? 1 : -1;

        $net = (int) JournalLine::query()
            ->where('account_id', $account->id)
            ->where('is_posted', true)
            ->whereBetween('entry_date', [$start, $end])
            ->when($classId !== null, fn ($q) => $q->where('class_id', $classId))
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))
            ->sum(DB::raw('debit_cents - credit_cents'));

        return [
            'opening' => $opening,
            'closing' => $opening + $net * $signMultiplier,
            'lines' => $this->streamGeneralLedgerLines($account, $start, $end, $opening, $signMultiplier, $classId, $locationId),
        ];
    }

    /**
     * @return \Generator<int, array{date: string, entry_no: string, memo: ?string, debit: int, credit: int, running: int}>
     */
    private function streamGeneralLedgerLines(Account $account, CarbonInterface $start, CarbonInterface $end, int $opening, int $signMultiplier, ?int $classId = null, ?int $locationId = null): \Generator
    {
        $chunk = 500;
        $running = $opening;
        $lastDate = null;
        $lastId = 0;

        do {
            $rows = JournalLine::query()
                ->where('account_id', $account->id)
                ->where('is_posted', true)
                ->whereBetween('entry_date', [$start, $end])
                ->when($classId !== null, fn ($q) => $q->where('class_id', $classId))
                ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))
                ->when($lastDate !== null, fn ($q) => $q->where(fn ($w) => $w
                    ->where('entry_date', '>', $lastDate)
                    ->orWhere(fn ($x) => $x->where('entry_date', $lastDate)->where('id', '>', $lastId))))
                ->with('journalEntry')
                ->orderBy('entry_date')->orderBy('id')
                ->limit($chunk)
                ->get();

            foreach ($rows as $line) {
                $running += ((int) $line->debit_cents - (int) $line->credit_cents) * $signMultiplier;

                yield [
                    'date' => $line->journalEntry->entry_date->toDateString(),
                    'entry_no' => $line->journalEntry->entry_no,
                    'memo' => $line->memo ?? $line->journalEntry->memo,
                    'debit' => (int) $line->debit_cents,
                    'credit' => (int) $line->credit_cents,
                    'running' => $running,
                ];

                $lastDate = $line->entry_date->toDateString();
                $lastId = (int) $line->id;
            }
        } while ($rows->count() === $chunk);
    }

    /**
     * All-accounts general ledger for export — the full range, memory-flat. Entries are a
     * generator backed by keyset chunking on (entry_date, id); totals and counts come from
     * cheap aggregates so headers can be written before the stream begins.
     *
     * @return array{entries: \Generator, total_debit: int, total_credit: int, entry_count: int, line_count: int}
     */
    public function generalLedgerAllAccountsStreamReport(CarbonInterface $start, CarbonInterface $end, ?int $classId = null, ?int $locationId = null): array
    {
        $totals = JournalLine::query()
            ->where('is_posted', true)
            ->whereBetween('entry_date', [$start, $end])
            ->when($classId !== null, fn ($q) => $q->where('class_id', $classId))
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))
            ->selectRaw('COALESCE(SUM(debit_cents), 0) as d, COALESCE(SUM(credit_cents), 0) as c, COUNT(*) as n')
            ->first();

        // With a dimension filter, count only entries that have at least one matching line.
        $entryCount = (int) JournalEntry::query()
            ->where('is_posted', true)
            ->whereBetween('entry_date', [$start, $end])
            ->when($classId !== null || $locationId !== null, fn ($q) => $q->whereHas('lines', fn ($w) => $w
                ->when($classId !== null, fn ($c) => $c->where('class_id', $classId))
                ->when($locationId !== null, fn ($c) => $c->where('location_id', $locationId))))
            ->count();

        return [
            'entries' => $this->streamGeneralLedgerEntries($start, $end, $classId, $locationId),
            'total_debit' => (int) $totals->d,
            'total_credit' => (int) $totals->c,
            'entry_count' => $entryCount,
            'line_count' => (int) $totals->n,
        ];
    }

    /**
     * @return \Generator<int, array{entry_no: string, date: string, memo: ?string, total_debit: int, total_credit: int, lines: array<int, array{account_code: string, account_name: string, memo: ?string, debit: int, credit: int}>}>
     */
    private function streamGeneralLedgerEntries(CarbonInterface $start, CarbonInterface $end, ?int $classId = null, ?int $locationId = null): \Generator
    {
        // Only entries with a matching line are streamed, and only matching lines
        // are loaded — mirrors the paginated all-accounts dimension behaviour.
        $lineFilter = fn ($q) => $q
            ->when($classId !== null, fn ($w) => $w->where('class_id', $classId))
            ->when($locationId !== null, fn ($w) => $w->where('location_id', $locationId));

        $chunk = 300;
        $lastDate = null;
        $lastId = 0;

        do {
            $entries = JournalEntry::query()
                ->where('is_posted', true)
                ->whereBetween('entry_date', [$start, $end])
                ->when($classId !== null || $locationId !== null, fn ($q) => $q->whereHas('lines', $lineFilter))
                ->when($lastDate !== null, fn ($q) => $q->where(fn ($w) => $w
                    ->where('entry_date', '>', $lastDate)
                    ->orWhere(fn ($x) => $x->where('entry_date', $lastDate)->where('id', '>', $lastId))))
                ->with(['lines' => fn ($q) => $lineFilter($q)->orderBy('line_order')->orderBy('id'), 'lines.account'])
                ->orderBy('entry_date')->orderBy('id')
                ->limit($chunk)
                ->get();

            foreach ($entries as $entry) {
                $entryDebit = 0;
                $entryCredit = 0;

                $lines = $entry->lines->map(function (JournalLine $line) use (&$entryDebit, &$entryCredit) {
                    $debit = (int) $line->debit_cents;
                    $credit = (int) $line->credit_cents;

                    $entryDebit += $debit;
                    $entryCredit += $credit;

                    return [
                        'account_code' => (string) ($line->account->code ?? ''),
                        'account_name' => (string) ($line->account->name ?? ''),
                        'memo' => $line->memo,
                        'debit' => $debit,
                        'credit' => $credit,
                    ];
                })->all();

                yield [
                    'entry_no' => (string) $entry->entry_no,
                    'date' => $entry->entry_date->toDateString(),
                    'memo' => $entry->memo,
                    'total_debit' => $entryDebit,
                    'total_credit' => $entryCredit,
                    'lines' => $lines,
                ];

                $lastDate = $entry->entry_date->toDateString();
                $lastId = (int) $entry->id;
            }
        } while ($entries->count() === $chunk);
    }

    /**
     * @return array{collected: int, paid: int, net: int}
     */
    public function salesTaxForAgency(TaxAgency $agency, CarbonInterface $start, CarbonInterface $end): array
    {
        $collected = 0;
        $paid = 0;

        foreach ($this->salesTaxLines($agency, $start, $end) as $line) {
            if ($line['bucket'] === 'collected') {
                $collected += $line['amount_cents'];
            } else {
                $paid += $line['amount_cents'];
            }
        }

        return [
            'collected' => $collected,
            'paid' => $paid,
            'net' => $collected - $paid,
        ];
    }

    /**
     * Source documents contributing to the agency's collected / paid totals for the period.
     *
     * Lines are classified by the originating document — invoices contribute to "collected"
     * regardless of debit/credit polarity, bills and cheques contribute to "paid". A reversal
     * inherits the bucket of the document it reverses, so voiding an invoice subtracts from
     * "collected" rather than turning into a negative ITC.
     *
     * @return Collection<int, array{bucket: 'collected'|'paid', amount_cents: int, journal_line_id: int, entry_id: int, entry_no: string, entry_date: CarbonImmutable, source_type: ?string, source_id: ?int, doc_label: string, is_reversal: bool}>
     */
    public function salesTaxLines(TaxAgency $agency, CarbonInterface $start, CarbonInterface $end): Collection
    {
        $lines = JournalLine::query()
            ->where('account_id', $agency->payable_account_id)
            ->whereHas('journalEntry', fn ($q) => $q
                ->where('is_posted', true)
                ->whereBetween('entry_date', [$start, $end])
            )
            ->with(['journalEntry.source', 'journalEntry.reverses.source'])
            ->get();

        return $lines->map(function (JournalLine $line) {
            $entry = $line->journalEntry;
            $origin = $entry->reverses ?: $entry;
            $isReversal = $entry->reverses_entry_id !== null;

            [$bucket, $signedAmount] = $this->classifySalesTaxLine($origin, $line, $isReversal);

            if ($bucket === null) {
                return null;
            }

            return [
                'bucket' => $bucket,
                'amount_cents' => $signedAmount,
                'journal_line_id' => (int) $line->id,
                'entry_id' => (int) $entry->id,
                'entry_no' => (string) $entry->entry_no,
                'entry_date' => CarbonImmutable::parse($entry->entry_date),
                'source_type' => $origin->source_type,
                'source_id' => $origin->source_id !== null ? (int) $origin->source_id : null,
                'doc_label' => $this->salesTaxDocLabel($origin, $isReversal),
                'is_reversal' => $isReversal,
            ];
        })->filter()->values();
    }

    /**
     * @return array{0: 'collected'|'paid'|null, 1: int}
     */
    protected function classifySalesTaxLine(JournalEntry $origin, JournalLine $line, bool $isReversal): array
    {
        $credit = (int) $line->credit_cents;
        $debit = (int) $line->debit_cents;
        $salesContribution = $credit - $debit;   // positive on a normal sale
        $itcContribution = $debit - $credit;     // positive on a normal purchase

        $sourceType = $origin->source_type;

        if ($sourceType === Invoice::class) {
            return ['collected', $salesContribution];
        }

        if ($sourceType === Bill::class || $sourceType === Cheque::class) {
            return ['paid', $itcContribution];
        }

        // Manual JE or unknown source: classify by polarity. Reversal of a manual JE
        // still inherits the original's polarity since `$origin` is the original.
        if ($credit > 0 && $debit === 0) {
            return ['collected', $credit];
        }
        if ($debit > 0 && $credit === 0) {
            return ['paid', $debit];
        }

        return [null, 0];
    }

    protected function salesTaxDocLabel(JournalEntry $origin, bool $isReversal): string
    {
        $source = $origin->source;

        $base = match (true) {
            $source instanceof Invoice => 'Invoice '.($source->invoice_no ?? ('#'.$source->id)),
            $source instanceof Bill => 'Bill '.($source->bill_no ?? ('#'.$source->id)),
            $source instanceof Cheque => 'Cheque '.($source->cheque_no ?? ('#'.$source->id)),
            default => 'Journal '.$origin->entry_no,
        };

        return $isReversal ? "Void of {$base}" : $base;
    }

    /**
     * Determine fiscal-year start date for an as-of date.
     */
    public function fiscalYearStart(Company $company, CarbonInterface $asOf): CarbonImmutable
    {
        $month = (int) ($company->fiscal_year_start_month ?? 1);
        $asOf = CarbonImmutable::parse($asOf);

        $candidate = CarbonImmutable::create($asOf->year, $month, 1);

        return $candidate->lessThanOrEqualTo($asOf) ? $candidate : $candidate->subYear();
    }

    /**
     * Convert a raw (debit-credit) signed value into a natural-balance signed value.
     */
    protected function signedToNatural(Account $account, int $signed): int
    {
        return $account->normal_balance === NormalBalance::Debit ? $signed : -$signed;
    }
}
