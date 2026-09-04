<?php

namespace App\Services\Reporting;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Enums\ReportStatement;
use App\Models\Account;
use App\Models\Company;
use App\Models\ReportGroup;
use App\Models\ReportGroupLine;
use App\Models\ReportGroupSection;
use App\Support\Reporting\CashFlowBucket;
use App\Support\Reporting\CombinedSectionPartitioner;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Pure read service that combines several companies' ledgers into one set of
 * reports, following a {@see ReportGroup}'s account mappings.
 *
 * The trick: source accounts are summed in their RAW (debit − credit) form and
 * only converted to a natural-balance figure using the *combined line's* declared
 * type. That makes "net" lines (e.g. rental income mapped together with rental
 * expense) collapse correctly — the income's credit and the expense's debit sum,
 * then present under whatever type the user chose for the line.
 *
 * This is "combination", not GAAP consolidation: no inter-company eliminations.
 */
class CombinedReportCalculator
{
    public function __construct(protected ReportCalculator $calc) {}

    /**
     * Member companies, keyed by id, ordered by name. Loaded without the company
     * global scope since groups span tenants.
     *
     * @return Collection<int, Company>
     */
    public function companies(ReportGroup $group): Collection
    {
        return $group->companies()
            ->withoutGlobalScopes()
            ->orderByRaw('LOWER(companies.name)')
            ->get()
            ->keyBy('id');
    }

    /**
     * Combined balance sheet as of a date. Each bucket is keyed by group_key
     * (subtype value, or type value for lines without a subtype) carrying a display
     * label and an ordered list of section/unassigned blocks (see
     * {@see CombinedSectionPartitioner}). Sections only regroup — totals are unchanged.
     *
     * @return array{
     *   assets: array<string, array{label: string, blocks: array<int, array<string, mixed>>}>,
     *   liabilities: array<string, array{label: string, blocks: array<int, array<string, mixed>>}>,
     *   equity: array<string, array{label: string, blocks: array<int, array<string, mixed>>}>,
     *   total_assets: int, total_liabilities: int, total_equity: int,
     *   net_income_ytd: int, net_income_ytd_by_company: array<int, int>,
     *   retained_earnings_prior: int, retained_earnings_prior_by_company: array<int, int>,
     *   total_le: int,
     *   companies: array<int, array{id: int, name: string}>,
     * }
     */
    public function balanceSheet(ReportGroup $group, CarbonInterface $asOf): array
    {
        $accounts = $this->mappedAccounts($group);
        $sections = $this->sectionsByGroupKey($group, ReportStatement::BalanceSheet);

        // Each bucket: groupKey => ['label' => string, 'rows' => [...]].
        $groups = ['assets' => [], 'liabilities' => [], 'equity' => []];
        $totals = ['assets' => 0, 'liabilities' => 0, 'equity' => 0];

        foreach ($this->orderedLines($group) as $line) {
            $bucket = match ($line->type) {
                AccountType::Asset => 'assets',
                AccountType::Liability => 'liabilities',
                AccountType::Equity => 'equity',
                default => null,
            };

            if ($bucket === null) {
                continue;
            }

            [$balance, $byCompany] = $this->sumLine(
                $line,
                $accounts,
                fn (Account $a) => $this->calc->rawBalanceAsOf($a, $asOf),
            );

            if ($balance === 0 && array_sum($byCompany) === 0) {
                continue;
            }

            $groupKey = $line->subtype?->value ?? $line->type->value;
            $groups[$bucket][$groupKey] ??= ['label' => $this->subtypeLabel($line), 'rows' => []];
            $groups[$bucket][$groupKey]['rows'][] = [
                'line_id' => $line->id,
                'name' => $line->name,
                'balance' => $balance,
                'by_company' => $byCompany,
                'section_id' => $line->report_group_section_id,
            ];

            $totals[$bucket] += $balance;
        }

        [$netIncomeYtd, $netIncomeByCompany] = $this->combinedNetIncomeYtd($group, $asOf);
        [$priorRetained, $priorRetainedByCompany] = $this->combinedPriorRetainedEarnings($group, $asOf);

        $partition = fn (array $bucketGroups): array => $this->partitionBucket($bucketGroups, $sections, 'balance');

        return [
            'assets' => $partition($groups['assets']),
            'liabilities' => $partition($groups['liabilities']),
            'equity' => $partition($groups['equity']),
            'total_assets' => $totals['assets'],
            'total_liabilities' => $totals['liabilities'],
            'total_equity' => $totals['equity'],
            'net_income_ytd' => $netIncomeYtd,
            'net_income_ytd_by_company' => $netIncomeByCompany,
            'retained_earnings_prior' => $priorRetained,
            'retained_earnings_prior_by_company' => $priorRetainedByCompany,
            'total_le' => $totals['liabilities'] + $totals['equity'] + $netIncomeYtd + $priorRetained,
            'companies' => $this->companyColumns($group),
        ];
    }

    /**
     * Combined income statement over a period, with optional prior-period comparison.
     *
     * Each bucket (income/cogs/expense) is an ordered list of section/unassigned
     * blocks (see {@see CombinedSectionPartitioner}); sections only regroup, totals
     * are unchanged.
     *
     * @return array{
     *   income: array<int, array<string, mixed>>,
     *   cogs: array<int, array<string, mixed>>,
     *   expense: array<int, array<string, mixed>>,
     *   total_income: int, total_cogs: int, total_expense: int, gross_profit: int, net_income: int,
     *   prior_total_income: int, prior_total_cogs: int, prior_total_expense: int, prior_gross_profit: int, prior_net_income: int,
     *   companies: array<int, array{id: int, name: string}>,
     * }
     */
    public function incomeStatement(ReportGroup $group, CarbonInterface $start, CarbonInterface $end, bool $comparison = false): array
    {
        $accounts = $this->mappedAccounts($group);
        $sections = $this->sectionsByGroupKey($group, ReportStatement::IncomeStatement);

        $priorEnd = $start->copy()->subDay();
        $priorStart = $priorEnd->copy()->subDays((int) $end->diffInDays($start));

        $buckets = ['income' => [], 'cogs' => [], 'expense' => []];
        $totals = ['income' => 0, 'cogs' => 0, 'expense' => 0];
        $priorTotals = ['income' => 0, 'cogs' => 0, 'expense' => 0];

        foreach ($this->orderedLines($group) as $line) {
            if (! in_array($line->type, [AccountType::Income, AccountType::Expense], true)) {
                continue;
            }

            [$current, $byCompany] = $this->sumLine(
                $line,
                $accounts,
                fn (Account $a) => $this->calc->rawPeriodChange($a, $start, $end),
            );

            $prior = 0;
            if ($comparison) {
                [$prior] = $this->sumLine(
                    $line,
                    $accounts,
                    fn (Account $a) => $this->calc->rawPeriodChange($a, $priorStart, $priorEnd),
                );
            }

            if ($current === 0 && $prior === 0) {
                continue;
            }

            $bucket = match (true) {
                $line->subtype === AccountSubtype::CostOfGoodsSold => 'cogs',
                $line->type === AccountType::Income => 'income',
                default => 'expense',
            };

            $buckets[$bucket][] = [
                'line_id' => $line->id,
                'name' => $line->name,
                'current' => $current,
                'prior' => $prior,
                'by_company' => $byCompany,
                'section_id' => $line->report_group_section_id,
            ];

            $totals[$bucket] += $current;
            $priorTotals[$bucket] += $prior;
        }

        $grossProfit = $totals['income'] - $totals['cogs'];
        $priorGrossProfit = $priorTotals['income'] - $priorTotals['cogs'];

        $partition = fn (string $key): array => CombinedSectionPartitioner::partition(
            $sections[$key] ?? collect(),
            $buckets[$key],
            'current',
        );

        return [
            'income' => $partition('income'),
            'cogs' => $partition('cogs'),
            'expense' => $partition('expense'),
            'total_income' => $totals['income'],
            'total_cogs' => $totals['cogs'],
            'total_expense' => $totals['expense'],
            'gross_profit' => $grossProfit,
            'net_income' => $grossProfit - $totals['expense'],
            'prior_total_income' => $priorTotals['income'],
            'prior_total_cogs' => $priorTotals['cogs'],
            'prior_total_expense' => $priorTotals['expense'],
            'prior_gross_profit' => $priorGrossProfit,
            'prior_net_income' => $priorGrossProfit - $priorTotals['expense'],
            'companies' => $this->companyColumns($group),
        ];
    }

    /**
     * Combined indirect Statement of Cash Flows over a period.
     *
     * Cash is every line whose subtype is {@see AccountSubtype::Bank}. Net income
     * collapses all P&L lines into one operating line, derived from
     * {@see incomeStatement()} so the two reports never disagree. Every other
     * balance-sheet line contributes the negated raw change of its mapped accounts
     * (uniform sign: positive = source of cash), so the statement reconciles to the
     * bank balance movement OVER THE MAPPED ACCOUNTS — like the balance sheet, an
     * incomplete mapping can leave it out of balance, which the view flags.
     *
     * Every scalar has a `*_by_company` sibling (company id => value) so the
     * per-company columns can show the same subtotals as the Combined column.
     * The maps are seeded with a 0 for every member company, so a company that
     * contributes nothing still has a key.
     *
     * @return array{
     *   operating: array<int, array<string, mixed>>,
     *   investing: array<int, array<string, mixed>>,
     *   financing: array<int, array<string, mixed>>,
     *   net_income: int, net_income_by_company: array<int, int>,
     *   total_operating: int, total_operating_by_company: array<int, int>,
     *   total_investing: int, total_investing_by_company: array<int, int>,
     *   total_financing: int, total_financing_by_company: array<int, int>,
     *   net_change: int, net_change_by_company: array<int, int>,
     *   cash_beginning: int, cash_beginning_by_company: array<int, int>,
     *   cash_ending: int, cash_ending_by_company: array<int, int>,
     *   reconciles: bool, reconciles_by_company: array<int, bool>,
     *   companies: array<int, array{id: int, name: string}>,
     * }
     */
    public function cashFlow(ReportGroup $group, CarbonInterface $start, CarbonInterface $end): array
    {
        $accounts = $this->mappedAccounts($group);
        $sections = $this->sectionsByGroupKey($group, ReportStatement::CashFlow);
        $companyIds = $this->companies($group)->keys()->all();
        $zeroed = array_fill_keys($companyIds, 0);

        $buckets = ['operating' => [], 'investing' => [], 'financing' => []];
        $activityTotals = ['operating' => 0, 'investing' => 0, 'financing' => 0];
        $activityTotalsByCompany = ['operating' => $zeroed, 'investing' => $zeroed, 'financing' => $zeroed];

        $cashBeginning = 0;
        $cashEnding = 0;
        $cashBeginningByCompany = $zeroed;
        $cashEndingByCompany = $zeroed;

        foreach ($this->orderedLines($group) as $line) {
            // Bank lines are the cash being explained — accumulate their balances
            // (asset/debit-normal, so natural equals raw) and skip as an activity.
            if ($line->subtype === AccountSubtype::Bank) {
                [$begin, $beginByCompany] = $this->sumLine($line, $accounts, fn (Account $a) => $this->calc->rawBalanceAsOf($a, $start->copy()->subDay()));
                [$close, $closeByCompany] = $this->sumLine($line, $accounts, fn (Account $a) => $this->calc->rawBalanceAsOf($a, $end));
                $cashBeginning += $begin;
                $cashEnding += $close;
                $cashBeginningByCompany = $this->addByCompany($cashBeginningByCompany, $beginByCompany);
                $cashEndingByCompany = $this->addByCompany($cashEndingByCompany, $closeByCompany);

                continue;
            }

            $activity = CashFlowBucket::forLine($line);

            if ($activity === null) {
                continue;
            }

            [$current, $byCompany] = $this->sumLineRawNegated(
                $line,
                $accounts,
                fn (Account $a) => $this->calc->rawPeriodChange($a, $start, $end),
            );

            if ($current === 0 && array_sum($byCompany) === 0) {
                continue;
            }

            $buckets[$activity][] = [
                'line_id' => $line->id,
                'name' => $line->name,
                'current' => $current,
                'by_company' => $byCompany,
                'section_id' => $line->report_group_section_id,
            ];

            $activityTotals[$activity] += $current;
            $activityTotalsByCompany[$activity] = $this->addByCompany($activityTotalsByCompany[$activity], $byCompany);
        }

        // Net income (total + per company) is taken from the combined income
        // statement so the cash-flow figure always matches it.
        $is = $this->incomeStatement($group, $start, $end);
        $netIncome = $is['net_income'];
        $netIncomeByCompany = $this->addByCompany($zeroed, $this->netIncomeByCompany($is));

        $partition = fn (string $key): array => CombinedSectionPartitioner::partition(
            $sections[$key] ?? collect(),
            $buckets[$key],
            'current',
        );

        $totalOperating = $netIncome + $activityTotals['operating'];
        $totalOperatingByCompany = $this->addByCompany($netIncomeByCompany, $activityTotalsByCompany['operating']);

        $netChange = $totalOperating + $activityTotals['investing'] + $activityTotals['financing'];
        $netChangeByCompany = $this->addByCompany(
            $this->addByCompany($totalOperatingByCompany, $activityTotalsByCompany['investing']),
            $activityTotalsByCompany['financing'],
        );

        // A company's column foots only when all of its cash-moving accounts are
        // mapped; the combined flag can be true while a column is not (offsetting
        // unmapped movements), so the per-company flags are reported separately.
        $reconcilesByCompany = [];
        foreach ($companyIds as $companyId) {
            $reconcilesByCompany[$companyId] = $cashEndingByCompany[$companyId] === $cashBeginningByCompany[$companyId] + $netChangeByCompany[$companyId];
        }

        return [
            'operating' => $partition('operating'),
            'investing' => $partition('investing'),
            'financing' => $partition('financing'),
            'net_income' => $netIncome,
            'net_income_by_company' => $netIncomeByCompany,
            'total_operating' => $totalOperating,
            'total_operating_by_company' => $totalOperatingByCompany,
            'total_investing' => $activityTotals['investing'],
            'total_investing_by_company' => $activityTotalsByCompany['investing'],
            'total_financing' => $activityTotals['financing'],
            'total_financing_by_company' => $activityTotalsByCompany['financing'],
            'net_change' => $netChange,
            'net_change_by_company' => $netChangeByCompany,
            'cash_beginning' => $cashBeginning,
            'cash_beginning_by_company' => $cashBeginningByCompany,
            'cash_ending' => $cashEnding,
            'cash_ending_by_company' => $cashEndingByCompany,
            'reconciles' => $cashBeginning + $netChange === $cashEnding,
            'reconciles_by_company' => $reconcilesByCompany,
            'companies' => $this->companyColumns($group),
        ];
    }

    /**
     * Per-company net income extracted from a combined income statement result:
     * income rows add, COGS and expense rows subtract.
     *
     * @param  array<string, mixed>  $incomeStatement
     * @return array<int, int>
     */
    protected function netIncomeByCompany(array $incomeStatement): array
    {
        $byCompany = [];

        foreach (['income' => 1, 'cogs' => -1, 'expense' => -1] as $key => $sign) {
            foreach ($incomeStatement[$key] as $block) {
                foreach ($block['rows'] as $row) {
                    $byCompany = $this->addByCompany(
                        $byCompany,
                        array_map(fn (int $value): int => $sign * $value, $row['by_company']),
                    );
                }
            }
        }

        return $byCompany;
    }

    /**
     * Add one per-company map into another, keeping every key from both.
     *
     * @param  array<int, int>  $into
     * @param  array<int, int>  $add
     * @return array<int, int>
     */
    protected function addByCompany(array $into, array $add): array
    {
        foreach ($add as $companyId => $value) {
            $into[$companyId] = ($into[$companyId] ?? 0) + $value;
        }

        return $into;
    }

    /**
     * Combined trial balance. Deliberately IGNORES line mappings and lists raw
     * individual accounts per company, so debits always equal credits — netting
     * lines would destroy the double-entry identity a trial balance exists to prove.
     *
     * @return array{
     *   companies: array<int, array{company: array{id: int, name: string}, rows: array<int, array{code: string, name: string, type: string, debit: int, credit: int}>, total_debit: int, total_credit: int}>,
     *   total_debit: int, total_credit: int,
     * }
     */
    public function trialBalance(ReportGroup $group, CarbonInterface $asOf): array
    {
        $sections = [];
        $grandDebit = 0;
        $grandCredit = 0;

        foreach ($this->companies($group) as $company) {
            $rows = [];
            $companyDebit = 0;
            $companyCredit = 0;

            $accounts = Account::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->orderBy('code')
                ->get();

            foreach ($accounts as $account) {
                $balance = $this->calc->balanceAsOf($account, $asOf);

                if ($balance === 0) {
                    continue;
                }

                if ($account->normal_balance === NormalBalance::Debit) {
                    $debit = $balance > 0 ? $balance : 0;
                    $credit = $balance < 0 ? -$balance : 0;
                } else {
                    $credit = $balance > 0 ? $balance : 0;
                    $debit = $balance < 0 ? -$balance : 0;
                }

                $rows[] = [
                    'code' => $account->code,
                    'name' => $account->name,
                    'type' => $account->type->label(),
                    'debit' => $debit,
                    'credit' => $credit,
                ];

                $companyDebit += $debit;
                $companyCredit += $credit;
            }

            $sections[] = [
                'company' => ['id' => $company->id, 'name' => $company->name],
                'rows' => $rows,
                'total_debit' => $companyDebit,
                'total_credit' => $companyCredit,
            ];

            $grandDebit += $companyDebit;
            $grandCredit += $companyCredit;
        }

        return [
            'companies' => $sections,
            'total_debit' => $grandDebit,
            'total_credit' => $grandCredit,
        ];
    }

    /**
     * Net income YTD for the group: each company is measured against its OWN
     * fiscal-year start, then summed. Returns [total, byCompany].
     *
     * @return array{0: int, 1: array<int, int>}
     */
    public function combinedNetIncomeYtd(ReportGroup $group, CarbonInterface $asOf): array
    {
        $byCompany = [];
        $total = 0;

        foreach ($this->companies($group) as $company) {
            $ni = $this->calc->netIncomeYtd($company, $asOf);
            $byCompany[$company->id] = $ni;
            $total += $ni;
        }

        return [$total, $byCompany];
    }

    /**
     * Prior fiscal years' net income per member company, summed. LineLedger posts
     * no closing entries, so — like the single-company balance sheet — the combined
     * report must add this to equity dynamically or it goes out of balance by
     * exactly this amount. Each company is measured against its OWN fiscal-year
     * start. Returns [total, byCompany].
     *
     * @return array{0: int, 1: array<int, int>}
     */
    public function combinedPriorRetainedEarnings(ReportGroup $group, CarbonInterface $asOf): array
    {
        $byCompany = [];
        $total = 0;

        foreach ($this->companies($group) as $company) {
            $prior = $this->calc->priorRetainedEarnings($company, $asOf);
            $byCompany[$company->id] = $prior;
            $total += $prior;
        }

        return [$total, $byCompany];
    }

    /**
     * Member companies whose currency differs from the group's. A non-empty result
     * means combined totals mix currencies and should be flagged in the UI.
     *
     * @return Collection<int, Company>
     */
    public function currencyMismatches(ReportGroup $group): Collection
    {
        return $this->companies($group)
            ->filter(fn (Company $company) => $company->currency_code !== $group->currency_code)
            ->values();
    }

    /**
     * Whether member companies have differing fiscal-year starts, which makes the
     * combined "net income YTD" line sum non-aligned periods.
     */
    public function hasMixedFiscalYears(ReportGroup $group): bool
    {
        return $this->companies($group)
            ->pluck('fiscal_year_start_month')
            ->unique()
            ->count() > 1;
    }

    /**
     * Sum a raw-balance closure across a line's mapped accounts, converting the
     * total to natural balance by the LINE's type. Returns [natural, byCompany].
     *
     * @param  Collection<int, Account>  $accounts
     * @param  callable(Account): int  $raw
     * @return array{0: int, 1: array<int, int>}
     */
    protected function sumLine(ReportGroupLine $line, Collection $accounts, callable $raw): array
    {
        $byCompanyRaw = [];
        $totalRaw = 0;

        foreach ($line->accountMaps as $map) {
            $account = $accounts->get($map->account_id);

            if ($account === null) {
                continue;
            }

            $value = $raw($account);
            $totalRaw += $value;
            $byCompanyRaw[$map->company_id] = ($byCompanyRaw[$map->company_id] ?? 0) + $value;
        }

        $byCompany = [];
        foreach ($byCompanyRaw as $companyId => $value) {
            $byCompany[$companyId] = $this->rawToNatural($line->type, $value);
        }

        return [$this->rawToNatural($line->type, $totalRaw), $byCompany];
    }

    /**
     * Sum a raw closure across a line's mapped accounts and NEGATE it (total + per
     * company), with no natural-balance conversion. Used for cash-flow activity rows,
     * where the contribution sign is uniform (positive = source of cash) regardless of
     * the line's type — unlike {@see sumLine()}, which presents by the line's type.
     *
     * @param  Collection<int, Account>  $accounts
     * @param  callable(Account): int  $raw
     * @return array{0: int, 1: array<int, int>}
     */
    protected function sumLineRawNegated(ReportGroupLine $line, Collection $accounts, callable $raw): array
    {
        $byCompany = [];
        $total = 0;

        foreach ($line->accountMaps as $map) {
            $account = $accounts->get($map->account_id);

            if ($account === null) {
                continue;
            }

            $value = $raw($account);
            $total += $value;
            $byCompany[$map->company_id] = ($byCompany[$map->company_id] ?? 0) + $value;
        }

        return [-$total, array_map(fn (int $v): int => -$v, $byCompany)];
    }

    /**
     * Convert a raw (debit − credit) value to a natural-balance figure for a type.
     */
    protected function rawToNatural(AccountType $type, int $raw): int
    {
        return $type->normalBalance() === NormalBalance::Debit ? $raw : -$raw;
    }

    /**
     * Lines ordered for presentation, with their maps eager-loaded.
     *
     * @return Collection<int, ReportGroupLine>
     */
    protected function orderedLines(ReportGroup $group): Collection
    {
        return $group->lines()
            ->with('accountMaps')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * All accounts mapped anywhere in the group, keyed by id (no global scope).
     *
     * @return Collection<int, Account>
     */
    protected function mappedAccounts(ReportGroup $group): Collection
    {
        $ids = $group->accountMaps()->pluck('account_id')->unique();

        if ($ids->isEmpty()) {
            return collect();
        }

        return Account::withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    protected function companyColumns(ReportGroup $group): array
    {
        return $this->companies($group)
            ->map(fn (Company $c) => ['id' => $c->id, 'name' => $c->name])
            ->values()
            ->all();
    }

    protected function subtypeLabel(ReportGroupLine $line): string
    {
        return $line->subtype?->label() ?? $line->type->label();
    }

    /**
     * The group's display sections for a statement, grouped by group_key. Wrapped
     * in a base collection so grouped Eloquent-collection ops stay safe.
     *
     * @return Collection<string, Collection<int, ReportGroupSection>>
     */
    protected function sectionsByGroupKey(ReportGroup $group, ReportStatement $statement): Collection
    {
        return collect(
            $group->sections()
                ->where('statement', $statement->value)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->groupBy('group_key')
        );
    }

    /**
     * Partition each subtype group's rows into section/unassigned blocks, and
     * pre-compute the subtype-level aggregates the views/exports need (so the
     * Blade templates stay loop-free).
     *
     * @param  array<string, array{label: string, rows: array<int, array<string, mixed>>}>  $bucketGroups
     * @param  Collection<string, Collection<int, ReportGroupSection>>  $sections
     * @return array<string, array{label: string, blocks: array<int, array<string, mixed>>, has_section: bool, subtotal: int, by_company: array<int, int>}>
     */
    protected function partitionBucket(array $bucketGroups, Collection $sections, string $valueKey): array
    {
        $out = [];

        foreach ($bucketGroups as $groupKey => $group) {
            $blocks = CombinedSectionPartitioner::partition(
                $sections[$groupKey] ?? collect(),
                $group['rows'],
                $valueKey,
            );

            $byCompany = [];
            foreach ($blocks as $block) {
                $byCompany = $this->addByCompany($byCompany, $block['by_company']);
            }

            $out[$groupKey] = [
                'label' => $group['label'],
                'blocks' => $blocks,
                'has_section' => collect($blocks)->contains(fn (array $b): bool => $b['type'] === 'section'),
                'subtotal' => array_sum(array_column($blocks, 'subtotal')),
                'by_company' => $byCompany,
            ];
        }

        return $out;
    }
}
