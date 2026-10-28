<?php

namespace App\Services\Reporting;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Company;
use App\Support\Statutory\UkStatutoryCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds UK small-company statutory accounts (FRS 102 Section 1A, condensed for
 * FRS 105) from the ledger: the Statement of Comprehensive Income for a period,
 * the Statement of Financial Position at its end, the Statement of Changes in
 * Equity, and the figures behind the notes — each with the prior-year
 * comparative.
 *
 * Figures are worked in pence and presented in whole pounds the way accounts
 * are cast: every line is rounded on its own and the totals add up the rounded
 * lines. Retained earnings on the balance sheet is the balancing figure, so the
 * statement always balances to the pound, and the change in equity reconciles to
 * it.
 *
 * Periods are explicit dates rather than the company's fiscal-year setting, so a
 * year ending on 5 April (the old tax year many small companies still use) works
 * the same as one ending on 31 March.
 */
class UkStatutoryAccountsBuilder
{
    /** Lines whose balances are presented as positive assets. */
    private const ASSET_LINES = ['intangible_assets', 'tangible_cost', 'investments', 'stocks', 'debtors', 'cash'];

    /** Income lines on the income statement (credit-natured). */
    private const INCOME_LINES = ['turnover', 'other_operating_income', 'interest_receivable'];

    /** Lines that make up the itemised expenses block. */
    private const EXPENSE_LINES = ['staff_costs', 'depreciation', 'administrative_expenses'];

    /** @var Collection<int, Account> */
    private Collection $accounts;

    /** @var array<int, string> account id => line */
    private array $lineOf = [];

    /** @var array<int, string> account id => caption */
    private array $labelOf = [];

    private Company $company;

    /**
     * The ledger figures read so far, for the company loaded — one builder
     * building several years (the report page's summary) reads each balance
     * date and period once. Use a fresh builder after the ledger changes.
     *
     * @var array<string, array<int, int>>
     */
    private array $ledgerReads = [];

    private ?int $loadedCompanyId = null;

    /** The company's earliest posted entry date, or null when it has none. */
    private ?CarbonImmutable $firstEntryDate = null;

    /**
     * @return array<string, mixed>
     */
    public function build(Company $company, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $this->load($company);

        $start = $start->startOfDay();
        $end = $end->startOfDay();
        $priorStart = $start->subYear();
        $priorEnd = $start->subDay();

        $hasPrior = $this->firstEntryDate?->lt($start) ?? false;

        $sfpEnd = $this->sfp($end);
        $sfpStart = $this->sfp($priorEnd); // also the prior year's closing position
        $sfpPriorOpen = $hasPrior ? $this->sfp($priorStart->subDay()) : null;

        // Each balance sheet is presented exactly as its own rounded lines, so
        // this year's comparative is last year's balance sheet as filed. The
        // pound or two that rounding every line on its own leaves between the
        // change in retained earnings and the rounded profit is taken up in
        // that year's income statement — worked out from the balance sheets
        // either side of it alone, so next year's comparative profit matches
        // too, and a first year opens at nil.
        $current = $this->castProfit($this->sciFor($start, $end), $sfpStart, $sfpEnd);
        $prior = $hasPrior ? $this->castProfit($this->sciFor($priorStart, $priorEnd), $sfpPriorOpen, $sfpStart) : null;
        $sci = ['current' => $current, 'prior' => $prior, 'expense_rows' => $this->expenseRows($current, $prior)];

        $soce = [
            'current' => $this->soce($sfpStart, $sfpEnd, $sci['current'], $start, $end),
            'prior' => $hasPrior ? $this->soce($sfpPriorOpen, $sfpStart, $sci['prior'], $priorStart, $priorEnd) : null,
        ];

        $tangible = $this->tangibleNote($start, $end, $sfpStart, $sfpEnd);

        return [
            'start' => $start,
            'end' => $end,
            'prior_start' => $priorStart,
            'prior_end' => $priorEnd,
            'has_prior' => $hasPrior,
            'sci' => $sci,
            'sfp' => ['current' => $sfpEnd, 'prior' => $hasPrior ? $sfpStart : null],
            'soce' => $soce,
            'tangible' => $tangible,
            'analysis' => [
                'debtors' => $this->analysis('debtors_by_label', $sfpEnd, $hasPrior ? $sfpStart : null),
                'creditors_within' => $this->analysis('creditors_within_by_label', $sfpEnd, $hasPrior ? $sfpStart : null),
                'creditors_after' => $this->analysis('creditors_after_by_label', $sfpEnd, $hasPrior ? $sfpStart : null),
            ],
            'captions' => $this->captions(),
            'warnings' => $this->warnings($start, $end),
        ];
    }

    /**
     * The caption a single-figure line prints under: the accounts' shared
     * caption when they agree on one (so "Bank interest received" reads as the
     * accountant wrote it), otherwise the standard heading.
     *
     * @return array<string, string>
     */
    private function captions(): array
    {
        $labels = [];

        foreach ($this->accounts as $account) {
            if (trim((string) $account->statutory_label) === '') {
                continue;
            }

            $labels[$this->lineOf[$account->id]][trim((string) $account->statutory_label)] = true;
        }

        $out = [];
        foreach (UkStatutoryCatalog::all() as $key => $line) {
            $own = array_keys($labels[$key] ?? []);
            $out[$key] = count($own) === 1 ? $own[0] : __($line['label']);
        }

        return $out;
    }

    /**
     * Every account with the line and caption it presents under — the mapping
     * the report page lets you review and change.
     *
     * @return list<array{id: int, code: string, name: string, type: string, line: string, label: string, explicit_line: bool, explicit_label: bool}>
     */
    public function mapping(Company $company): array
    {
        return Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->orderBy('code')
            ->get()
            ->map(fn (Account $a) => [
                'id' => $a->id,
                'code' => (string) $a->code,
                'name' => $a->displayName(),
                'type' => UkStatutoryCatalog::typeOf($a)->value ?? '',
                'line' => UkStatutoryCatalog::lineFor($a),
                'default_line' => UkStatutoryCatalog::defaultLine(UkStatutoryCatalog::subtypeOf($a), (string) $a->name),
                'label' => UkStatutoryCatalog::labelFor($a),
                'explicit_line' => UkStatutoryCatalog::exists($a->statutory_line),
                'explicit_label' => trim((string) $a->statutory_label) !== '',
                'active' => (bool) $a->is_active,
            ])
            ->all();
    }

    /**
     * Read the company's accounts and mapping, unless this builder already has.
     */
    private function load(Company $company): void
    {
        $this->company = $company;

        if ($this->loadedCompanyId === $company->id) {
            return;
        }

        $this->loadedCompanyId = $company->id;
        $this->ledgerReads = [];
        $this->lineOf = [];
        $this->labelOf = [];
        $this->accounts = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->orderBy('code')
            ->get();

        foreach ($this->accounts as $account) {
            $this->lineOf[$account->id] = UkStatutoryCatalog::lineFor($account);
            $this->labelOf[$account->id] = UkStatutoryCatalog::labelFor($account);
        }

        $first = DB::table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('accounts.company_id', $company->id)
            ->where('journal_lines.is_posted', true)
            ->min('journal_lines.entry_date');

        $this->firstEntryDate = $first === null ? null : CarbonImmutable::parse((string) $first)->startOfDay();
    }

    // ───────────────────────── Income statement ─────────────────────────

    /**
     * The expenses block of the income statement, one row per caption across
     * both years.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>|null  $prior
     * @return list<array{label: string, current: int, prior: int}>
     */
    private function expenseRows(array $current, ?array $prior): array
    {
        $labels = array_unique(array_merge(array_keys($current['expenses']), array_keys($prior['expenses'] ?? [])));
        natcasesort($labels);

        $rows = [];
        foreach ($labels as $label) {
            $rows[] = [
                'label' => $label,
                'current' => $current['expenses'][$label] ?? 0,
                'prior' => $prior['expenses'][$label] ?? 0,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function sciFor(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $raw = $this->rawMovements($start, $end);

        $byLine = [];
        $profitPence = 0;
        $dividendsPence = 0;
        $expensePence = [];
        $expenseByLinePence = ['staff_costs' => 0, 'depreciation' => 0, 'administrative_expenses' => 0];

        foreach ($this->accounts as $account) {
            $line = $this->lineOf[$account->id];
            $catalog = UkStatutoryCatalog::all()[$line];

            if ($catalog['statement'] !== UkStatutoryCatalog::SCI || $line === 'excluded') {
                continue;
            }

            $pence = (int) ($raw[$account->id] ?? 0);
            $amount = in_array($line, self::INCOME_LINES, true) ? -$pence : $pence;

            if ($line === 'dividends') {
                $dividendsPence += $pence;
            } else {
                $profitPence -= $pence;
            }

            if (in_array($line, self::EXPENSE_LINES, true)) {
                $label = $this->labelOf[$account->id];
                $expensePence[$label] = ($expensePence[$label] ?? 0) + $amount;
                $expenseByLinePence[$line] += $amount;

                continue;
            }

            $byLine[$line] = ($byLine[$line] ?? 0) + $amount;
        }

        $r = fn (string $line): int => self::pounds($byLine[$line] ?? 0);

        $expenses = [];
        foreach ($expensePence as $label => $pence) {
            $pounds = self::pounds($pence);
            if ($pounds !== 0) {
                $expenses[$label] = $pounds;
            }
        }

        $turnover = $r('turnover');
        $costOfSales = $r('cost_of_sales');
        $otherIncome = $r('other_operating_income');
        $expenseTotal = array_sum($expenses);
        $grossProfit = $turnover - $costOfSales;
        $operatingProfit = $grossProfit + $otherIncome - $expenseTotal;
        $interestReceivable = $r('interest_receivable');
        $interestPayable = $r('interest_payable');
        $profitBeforeTax = $operatingProfit + $interestReceivable - $interestPayable;
        $taxation = $r('taxation');

        return [
            'turnover' => $turnover,
            'cost_of_sales' => $costOfSales,
            'gross_profit' => $grossProfit,
            'other_operating_income' => $otherIncome,
            'expenses' => $expenses,
            'expense_total' => $expenseTotal,
            // FRS 105 headings: the itemised expenses re-cut by nature.
            'staff_costs' => self::pounds($expenseByLinePence['staff_costs']),
            'depreciation' => self::pounds($expenseByLinePence['depreciation']),
            'other_charges' => $expenseTotal - self::pounds($expenseByLinePence['staff_costs']) - self::pounds($expenseByLinePence['depreciation']) + $interestPayable,
            'operating_profit' => $operatingProfit,
            'interest_receivable' => $interestReceivable,
            'interest_payable' => $interestPayable,
            'profit_before_tax' => $profitBeforeTax,
            'taxation' => $taxation,
            'profit_after_tax' => $profitBeforeTax - $taxation,
            'dividends' => $r('dividends'),
            'profit_after_tax_pence' => $profitPence,
            'dividends_pence' => $dividendsPence,
        ];
    }

    // ───────────────────────── Balance sheet ─────────────────────────

    /**
     * The Statement of Financial Position at a date, in whole pounds.
     *
     * @return array<string, mixed>
     */
    private function sfp(CarbonImmutable $date): array
    {
        $raw = $this->rawBalances($date);

        $single = [];
        $byLabel = ['debtors' => [], 'creditors_within' => [], 'creditors_after' => [], 'cash' => []];
        $classes = [];
        $netAssetsPence = 0;
        $otherEquityPence = 0;

        foreach ($this->accounts as $account) {
            $line = $this->lineOf[$account->id];
            $catalog = UkStatutoryCatalog::all()[$line];

            if ($catalog['statement'] !== UkStatutoryCatalog::SFP) {
                continue;
            }

            $pence = (int) ($raw[$account->id] ?? 0);
            $label = $this->labelOf[$account->id];

            if ($catalog['group'] !== 'equity') {
                $netAssetsPence += $pence;
            } elseif ($line !== 'retained_earnings') {
                $otherEquityPence -= $pence;
            }

            if ($line === 'tangible_cost' || $line === 'tangible_depreciation') {
                $classes[$label] ??= ['cost' => 0, 'depreciation' => 0];
                // Cost is a debit balance; accumulated depreciation a credit one.
                $classes[$label][$line === 'tangible_cost' ? 'cost' : 'depreciation'] += $line === 'tangible_cost' ? $pence : -$pence;

                continue;
            }

            $amount = in_array($line, self::ASSET_LINES, true) ? $pence : -$pence;

            if (isset($byLabel[$line])) {
                $byLabel[$line][$label] = ($byLabel[$line][$label] ?? 0) + $amount;

                continue;
            }

            $single[$line] = ($single[$line] ?? 0) + $amount;
        }

        // Present balances on the side they belong: a creditor in debit (VAT
        // due back, an overpaid supplier) is a debtor, a debtor in credit is a
        // creditor, and an overdrawn bank account is an overdraft. Accounts
        // sharing a caption net first — separate VAT input and output accounts
        // are one balance with HMRC — but nothing else is set off: assets and
        // liabilities may not be netted (SI 2008/409, Sch 1 para 8).
        foreach ($byLabel['creditors_within'] as $label => $pence) {
            if ($pence < 0) {
                $byLabel['debtors'][$label] = ($byLabel['debtors'][$label] ?? 0) - $pence;
                unset($byLabel['creditors_within'][$label]);
            }
        }
        foreach ($byLabel['debtors'] as $label => $pence) {
            if ($pence < 0) {
                $byLabel['creditors_within'][$label] = ($byLabel['creditors_within'][$label] ?? 0) - $pence;
                unset($byLabel['debtors'][$label]);
            }
        }
        // A bank account's caption defaults to its own name, so each overdrawn
        // account is an overdraft unless the accountant captions accounts with a
        // set-off arrangement alike.
        $single['cash'] = 0;
        foreach ($byLabel['cash'] as $pence) {
            if ($pence < 0) {
                $byLabel['creditors_within']['Bank loans and overdrafts'] = ($byLabel['creditors_within']['Bank loans and overdrafts'] ?? 0) - $pence;
            } else {
                $single['cash'] += $pence;
            }
        }

        $tangibleByClass = [];
        foreach ($classes as $class => $c) {
            $tangibleByClass[$class] = ['cost' => self::pounds($c['cost']), 'depreciation' => self::pounds($c['depreciation'])];
        }
        ksort($tangibleByClass, SORT_NATURAL | SORT_FLAG_CASE);

        $rounded = function (array $pence): array {
            $out = [];
            foreach ($pence as $label => $p) {
                $pounds = self::pounds($p);
                if ($pounds !== 0) {
                    $out[$label] = $pounds;
                }
            }
            ksort($out, SORT_NATURAL | SORT_FLAG_CASE);

            return $out;
        };

        $debtorsByLabel = $rounded($byLabel['debtors']);
        $cwByLabel = $rounded($byLabel['creditors_within']);
        $caByLabel = $rounded($byLabel['creditors_after']);

        $s = fn (string $line): int => self::pounds($single[$line] ?? 0);

        $intangible = $s('intangible_assets');
        $tangible = array_sum(array_map(fn ($c) => $c['cost'] - $c['depreciation'], $tangibleByClass));
        $investments = $s('investments');
        $fixedTotal = $intangible + $tangible + $investments;

        $stocks = $s('stocks');
        $debtors = array_sum($debtorsByLabel);
        $cash = $s('cash');
        $currentTotal = $stocks + $debtors + $cash;

        $creditorsWithin = array_sum($cwByLabel);
        $netCurrent = $currentTotal - $creditorsWithin;
        $totalLessCl = $fixedTotal + $netCurrent;
        $creditorsAfter = array_sum($caByLabel);
        $provisions = $s('provisions');
        $netAssets = $totalLessCl - $creditorsAfter - $provisions;

        $shareCapital = $s('share_capital');
        $sharePremium = $s('share_premium');
        $revaluation = $s('revaluation_reserve');
        $otherReserves = $s('other_reserves');
        $retained = $netAssets - $shareCapital - $sharePremium - $revaluation - $otherReserves;

        return [
            'date' => $date,
            'intangible_assets' => $intangible,
            'tangible_assets' => $tangible,
            'investments' => $investments,
            'fixed_total' => $fixedTotal,
            'stocks' => $stocks,
            'debtors' => $debtors,
            'cash' => $cash,
            'current_total' => $currentTotal,
            'creditors_within' => $creditorsWithin,
            'net_current_assets' => $netCurrent,
            'total_assets_less_current_liabilities' => $totalLessCl,
            'creditors_after' => $creditorsAfter,
            'provisions' => $provisions,
            'net_assets' => $netAssets,
            'share_capital' => $shareCapital,
            'share_premium' => $sharePremium,
            'revaluation_reserve' => $revaluation,
            'other_reserves' => $otherReserves,
            'retained_earnings' => $retained,
            'retained_earnings_pence' => $netAssetsPence - $otherEquityPence,
            'equity_total' => $netAssets,
            'debtors_by_label' => $debtorsByLabel,
            'creditors_within_by_label' => $cwByLabel,
            'creditors_after_by_label' => $caByLabel,
            'tangible_by_class' => $tangibleByClass,
        ];
    }

    /**
     * A movement through retained earnings that is not profit or dividends (a
     * prior-year adjustment posted straight to reserves, say), in whole pounds —
     * nil when it only exists in rounding.
     *
     * @param  array<string, mixed>  $open
     * @param  array<string, mixed>  $close
     * @param  array<string, mixed>  $sci
     */
    private function genuineOther(array $open, array $close, array $sci): int
    {
        $pence = $close['retained_earnings_pence'] - $open['retained_earnings_pence'] - $sci['profit_after_tax_pence'] + $sci['dividends_pence'];

        return abs($pence) < 100 ? 0 : self::pounds($pence);
    }

    /**
     * Make a year's rounded profit agree with the change in retained earnings
     * between its two (rounded) balance sheets, taking the rounding-sized
     * difference up on the largest expense line, or failing that turnover, as
     * accountants do. The balance sheets are left exactly as they round.
     *
     * @param  array<string, mixed>  $sci
     * @param  array<string, mixed>  $open
     * @param  array<string, mixed>  $close
     * @return array<string, mixed>
     */
    private function castProfit(array $sci, array $open, array $close): array
    {
        $profit = $close['retained_earnings'] - $open['retained_earnings'] + $sci['dividends'] - $this->genuineOther($open, $close, $sci);
        $diff = $profit - $sci['profit_after_tax'];
        $sci['rounding_adjustment'] = $diff;

        if ($diff === 0) {
            return $sci;
        }

        if ($sci['expenses'] !== []) {
            // More profit is less expense. The FRS 105 re-cut shows it in
            // other charges, so both layouts still add up.
            $label = array_keys($sci['expenses'], max($sci['expenses']), true)[0];
            $sci['expenses'][$label] -= $diff;
            $sci['expense_total'] -= $diff;
            $sci['other_charges'] -= $diff;
        } else {
            $sci['turnover'] += $diff;
            $sci['gross_profit'] += $diff;
        }

        $sci['operating_profit'] += $diff;
        $sci['profit_before_tax'] += $diff;
        $sci['profit_after_tax'] = $profit;

        return $sci;
    }

    // ───────────────────────── Changes in equity ─────────────────────────

    /**
     * @param  array<string, mixed>  $open
     * @param  array<string, mixed>  $close
     * @param  array<string, mixed>  $sci
     * @return array<string, mixed>
     */
    private function soce(array $open, array $close, array $sci, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $otherOpen = $open['share_premium'] + $open['revaluation_reserve'] + $open['other_reserves'];
        $otherClose = $close['share_premium'] + $close['revaluation_reserve'] + $close['other_reserves'];

        $profit = $sci['profit_after_tax'];
        $dividends = $sci['dividends'];
        $sharesIssued = $close['share_capital'] - $open['share_capital'];
        $otherReserveMovement = $otherClose - $otherOpen;
        // The profit already casts to the balance sheets (see castProfit), so
        // this is only ever a genuine movement through reserves.
        $openingRetained = $open['retained_earnings'];
        $otherMovements = $close['retained_earnings'] - $openingRetained - $profit + $dividends;

        return [
            'opening_date' => $start,
            'closing_date' => $end,
            'has_other_reserves' => $otherOpen !== 0 || $otherClose !== 0,
            'opening' => ['share_capital' => $open['share_capital'], 'other_reserves' => $otherOpen, 'retained_earnings' => $openingRetained],
            'profit' => $profit,
            'dividends' => $dividends,
            'shares_issued' => $sharesIssued,
            'other_reserve_movement' => $otherReserveMovement,
            'other_movements' => $otherMovements,
            'closing' => ['share_capital' => $close['share_capital'], 'other_reserves' => $otherClose, 'retained_earnings' => $close['retained_earnings']],
        ];
    }

    // ───────────────────────── Tangible fixed asset note ─────────────────────────

    /**
     * Cost and depreciation movements per asset class over the period. Each
     * journal entry is read as a whole: an entry that also posts to a
     * depreciation expense line is the year's charge (whichever asset account it
     * credits, so a combined "net" account still works); otherwise a net debit to
     * cost is an addition and a net credit a disposal. Entries that only move
     * amounts between accounts of the same class net to nothing.
     *
     * @param  array<string, mixed>  $open
     * @param  array<string, mixed>  $close
     * @return array{classes: array<string, array<string, int>>, total: array<string, int>}
     */
    private function tangibleNote(CarbonImmutable $start, CarbonImmutable $end, array $open, array $close): array
    {
        $kindOf = [];
        $classOf = [];
        $depExpenseIds = [];
        $hasDepAccount = [];

        foreach ($this->accounts as $account) {
            $line = $this->lineOf[$account->id];

            if ($line === 'tangible_cost' || $line === 'tangible_depreciation') {
                $kindOf[$account->id] = $line;
                $classOf[$account->id] = $this->labelOf[$account->id];
                if ($line === 'tangible_depreciation') {
                    $hasDepAccount[$this->labelOf[$account->id]] = true;
                }
            } elseif ($line === 'depreciation') {
                $depExpenseIds[] = $account->id;
            }
        }

        $flows = [];

        if ($kindOf !== []) {
            $rows = DB::table('journal_lines')
                ->where('journal_lines.is_posted', true)
                ->whereIn('journal_lines.account_id', array_keys($kindOf))
                ->whereBetween('journal_lines.entry_date', [$start->toDateString(), $end->toDateString()])
                ->groupBy('journal_lines.journal_entry_id', 'journal_lines.account_id')
                ->selectRaw('journal_lines.journal_entry_id AS entry_id, journal_lines.account_id, SUM(journal_lines.debit_cents - journal_lines.credit_cents) AS raw')
                ->get();

            $depEntries = $depExpenseIds === [] ? [] : DB::table('journal_lines')
                ->where('journal_lines.is_posted', true)
                ->whereIn('journal_lines.account_id', $depExpenseIds)
                ->whereIn('journal_lines.journal_entry_id', $rows->pluck('entry_id')->unique()->all())
                ->pluck('journal_lines.journal_entry_id')
                ->flip()
                ->all();

            $perEntry = [];
            foreach ($rows as $row) {
                $class = $classOf[$row->account_id];
                $kind = $kindOf[$row->account_id];
                $perEntry[$row->entry_id][$class][$kind] = ($perEntry[$row->entry_id][$class][$kind] ?? 0) + (int) $row->raw;
            }

            foreach ($perEntry as $entryId => $byClass) {
                foreach ($byClass as $class => $k) {
                    $flows[$class] ??= ['additions' => 0, 'disposals' => 0, 'charge' => 0, 'eliminated' => 0];
                    $cost = $k['tangible_cost'] ?? 0;
                    $dep = $k['tangible_depreciation'] ?? 0;

                    if (isset($depEntries[$entryId])) {
                        $flows[$class]['charge'] += -($cost + $dep);

                        continue;
                    }

                    $cost >= 0 ? $flows[$class]['additions'] += $cost : $flows[$class]['disposals'] += -$cost;
                    $dep <= 0 ? $flows[$class]['charge'] += -$dep : $flows[$class]['eliminated'] += $dep;
                }
            }
        }

        $classes = [];
        $names = array_unique(array_merge(array_keys($open['tangible_by_class']), array_keys($close['tangible_by_class']), array_keys($flows)));
        natcasesort($names);

        foreach ($names as $class) {
            $bf = $open['tangible_by_class'][$class] ?? ['cost' => 0, 'depreciation' => 0];
            $cf = $close['tangible_by_class'][$class] ?? ['cost' => 0, 'depreciation' => 0];
            $nbvClose = $cf['cost'] - $cf['depreciation'];
            $f = $flows[$class] ?? ['additions' => 0, 'disposals' => 0, 'charge' => 0, 'eliminated' => 0];

            $c = [
                'cost_open' => $bf['cost'],
                'additions' => self::pounds($f['additions']),
                'disposals' => self::pounds($f['disposals']),
                'dep_open' => $bf['depreciation'],
                'charge' => self::pounds($f['charge']),
                'eliminated' => self::pounds($f['eliminated']),
            ];

            // Cast the note to the balance sheet: rounding each movement to the
            // pound can leave a closing figure a pound adrift, so the largest
            // movement absorbs it. A class that keeps cost and depreciation in
            // separate accounts casts each column to its balance; one held net in
            // a single account casts its net book value through the charge.
            if (isset($hasDepAccount[$class])) {
                $costResidual = $cf['cost'] - ($c['cost_open'] + $c['additions'] - $c['disposals']);
                $c['additions'] !== 0 || $c['disposals'] === 0 ? $c['additions'] += $costResidual : $c['disposals'] -= $costResidual;
                $depResidual = $cf['depreciation'] - ($c['dep_open'] + $c['charge'] - $c['eliminated']);
                $c['charge'] !== 0 || $c['eliminated'] === 0 ? $c['charge'] += $depResidual : $c['eliminated'] -= $depResidual;
            } else {
                $nbvFromFlows = ($c['cost_open'] + $c['additions'] - $c['disposals']) - ($c['dep_open'] + $c['charge'] - $c['eliminated']);
                $residual = $nbvClose - $nbvFromFlows;
                if ($residual !== 0) {
                    $c['charge'] !== 0 || $c['additions'] === 0 ? $c['charge'] -= $residual : $c['additions'] += $residual;
                }
            }

            $c['cost_close'] = $c['cost_open'] + $c['additions'] - $c['disposals'];
            $c['dep_close'] = $c['dep_open'] + $c['charge'] - $c['eliminated'];
            $c['nbv_close'] = $c['cost_close'] - $c['dep_close'];
            $c['nbv_open'] = $c['cost_open'] - $c['dep_open'];

            if (collect($c)->every(fn ($v) => $v === 0)) {
                continue;
            }

            $classes[$class] = $c;
        }

        $total = [];
        foreach (['cost_open', 'additions', 'disposals', 'cost_close', 'dep_open', 'charge', 'eliminated', 'dep_close', 'nbv_close', 'nbv_open'] as $k) {
            $total[$k] = array_sum(array_column($classes, $k));
        }

        return ['classes' => $classes, 'total' => $total];
    }

    // ───────────────────────── Helpers ─────────────────────────

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>|null  $prior
     * @return list<array{label: string, current: int, prior: int}>
     */
    private function analysis(string $key, array $current, ?array $prior): array
    {
        $labels = array_unique(array_merge(array_keys($current[$key]), array_keys($prior[$key] ?? [])));
        natcasesort($labels);

        return array_values(array_map(fn ($label) => [
            'label' => $label,
            'current' => $current[$key][$label] ?? 0,
            'prior' => $prior[$key][$label] ?? 0,
        ], $labels));
    }

    /**
     * Mappings that move money across the balance sheet / income statement
     * boundary, which would make the statements misleading.
     *
     * @return list<string>
     */
    private function warnings(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $warnings = [];
        $balances = $this->rawBalances($end);
        $movements = $this->rawMovements($start, $end);

        foreach ($this->accounts as $account) {
            $line = $this->lineOf[$account->id];
            $statement = UkStatutoryCatalog::all()[$line]['statement'];
            $type = UkStatutoryCatalog::typeOf($account);
            $isPl = in_array($type, [AccountType::Income, AccountType::Expense], true);

            // An excluded balance sheet account still holds its balance, which
            // drops out of net assets into retained earnings even in a quiet year.
            $hasAmount = (int) ($movements[$account->id] ?? 0) !== 0 || (! $isPl && (int) ($balances[$account->id] ?? 0) !== 0);

            if ($line === 'excluded' && $hasAmount) {
                $warnings[] = __(':account is excluded from the statutory accounts but has a balance or activity this year; it only shows through retained earnings.', ['account' => $account->displayName()]);
            } elseif (! $isPl && $type !== AccountType::Equity && $statement === UkStatutoryCatalog::SCI && (int) ($balances[$account->id] ?? 0) !== 0) {
                $warnings[] = __(':account is a balance sheet account mapped to an income statement line.', ['account' => $account->displayName()]);
            } elseif ($isPl && $statement === UkStatutoryCatalog::SFP && (int) ($movements[$account->id] ?? 0) !== 0) {
                $warnings[] = __(':account is an income or expense account mapped to a balance sheet line.', ['account' => $account->displayName()]);
            }
        }

        return $warnings;
    }

    /**
     * Raw (debit − credit) balance of every account at the end of a date.
     *
     * @return array<int, int>
     */
    private function rawBalances(CarbonImmutable $date): array
    {
        return $this->ledgerReads['balance:'.$date->toDateString()] ??= $this->rawQuery()
            ->where('journal_lines.entry_date', '<=', $date->toDateString())
            ->pluck('raw', 'account_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Raw (debit − credit) movement on every account over a period.
     *
     * @return array<int, int>
     */
    private function rawMovements(CarbonImmutable $start, CarbonImmutable $end): array
    {
        return $this->ledgerReads['movement:'.$start->toDateString().':'.$end->toDateString()] ??= $this->rawQuery()
            ->whereBetween('journal_lines.entry_date', [$start->toDateString(), $end->toDateString()])
            ->pluck('raw', 'account_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    private function rawQuery(): Builder
    {
        return DB::table('journal_lines')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('accounts.company_id', $this->company->id)
            ->where('journal_lines.is_posted', true)
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id, SUM(journal_lines.debit_cents - journal_lines.credit_cents) AS raw');
    }

    /**
     * Whole pounds, rounded half away from zero.
     */
    public static function pounds(int $pence): int
    {
        return (int) round($pence / 100, 0, PHP_ROUND_HALF_UP);
    }
}
