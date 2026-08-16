<?php

namespace App\Services\Reporting;

use App\Enums\AccountSubtype;
use App\Enums\GifiStatement;
use App\Models\Account;
use App\Models\Company;
use App\Support\Gifi\GifiCatalog;
use Carbon\CarbonInterface;

/**
 * Builds the GIFI-coded financial statement (Schedule 100 balance sheet +
 * Schedule 125 income statement) from a company's chart, grouping accounts by
 * their `gifi_code`. Shared by every GIFI-based report: the T2 GIFI Statement,
 * the T5013 partnership return, and the T2125 statement of business activities
 * (which re-presents the same structure into its own Parts).
 *
 * Balancing mirrors the Balance Sheet report: prior-year net income folds into
 * the retained-earnings line and the current year's net income is added to
 * equity, so Total Assets = Total Liabilities + Equity.
 */
class GifiStatementBuilder
{
    public function __construct(private readonly ReportCalculator $calc) {}

    /**
     * @return array{
     *   bs: array{halves: array<string, array{label: string, sections: list<array<string, mixed>>, total: int, net_income: int}>, total_assets: int, total_le: int, balanced: bool},
     *   is: array{halves: array<string, array{label: string, sections: list<array<string, mixed>>, total: int, net_income: int}>, net_income: int},
     *   unassigned: array{lines: list<array{id: int|null, code: string, name: string, amount: int}>, total: int}
     * }
     */
    public function build(Company $company, CarbonInterface $start, CarbonInterface $end): array
    {
        $catalog = GifiCatalog::all();

        $accounts = Account::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->orderBy('code')
            ->get();

        // Prior fiscal years' net income rolls into Retained Earnings (no closing
        // entries are posted) so the balance sheet half still balances.
        $priorEarnings = $this->calc->priorRetainedEarnings($company, $end);
        $reAccountId = $accounts->first(fn (Account $a) => $a->subtype === AccountSubtype::RetainedEarnings)?->id;

        // code => ['amount' => int, 'accounts' => [...]]
        $lines = [];
        $unassigned = [];
        $unassignedTotal = 0;

        foreach ($accounts as $account) {
            $entry = $account->gifi_code !== null ? ($catalog[$account->gifi_code] ?? null) : null;

            if ($entry === null) {
                $amount = $this->calc->reportingBalanceAsOf($company, $account, $end);

                if ($amount !== 0) {
                    $unassigned[] = ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'amount' => $amount];
                    $unassignedTotal += $amount;
                }

                continue;
            }

            $amount = $entry['statement'] === GifiStatement::BalanceSheet
                ? $this->calc->balanceAsOf($account, $end)
                : $this->calc->periodChange($account, $start, $end);

            if ($account->id === $reAccountId && $entry['statement'] === GifiStatement::BalanceSheet) {
                $amount += $priorEarnings;
            }

            $lines[$account->gifi_code] ??= ['amount' => 0, 'accounts' => []];
            $lines[$account->gifi_code]['amount'] += $amount;
            $lines[$account->gifi_code]['accounts'][] = [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'amount' => $amount,
            ];
        }

        // No Retained Earnings account but prior earnings exist — surface them as a
        // synthetic equity line so the statement still balances.
        if ($reAccountId === null && $priorEarnings !== 0) {
            $lines['3849'] ??= ['amount' => 0, 'accounts' => []];
            $lines['3849']['amount'] += $priorEarnings;
            $lines['3849']['accounts'][] = ['id' => null, 'code' => '', 'name' => __('Retained earnings (prior years)'), 'amount' => $priorEarnings];
        }

        $bsHalves = ['assets' => $this->emptyHalf(__('Assets')), 'liabilities' => $this->emptyHalf(__('Liabilities')), 'equity' => $this->emptyHalf(__('Equity'))];
        $isHalves = ['revenue' => $this->emptyHalf(__('Revenue')), 'cogs' => $this->emptyHalf(__('Cost of sales')), 'expense' => $this->emptyHalf(__('Operating expenses'))];

        foreach (GifiCatalog::sections() as $section) {
            $sectionLines = [];
            $subtotal = 0;

            foreach ($catalog as $code => $catalogEntry) {
                if ($catalogEntry['section'] !== $section['key'] || ! isset($lines[$code])) {
                    continue;
                }

                $sectionLines[] = [
                    'code' => $catalogEntry['code'],
                    'label' => $catalogEntry['label'],
                    'amount' => $lines[$code]['amount'],
                    'accounts' => $lines[$code]['accounts'],
                ];
                $subtotal += $lines[$code]['amount'];
            }

            if ($sectionLines === []) {
                continue;
            }

            $block = ['key' => $section['key'], 'label' => $section['label'], 'lines' => $sectionLines, 'subtotal' => $subtotal];

            if ($section['statement'] === GifiStatement::BalanceSheet) {
                $bsHalves[$section['half']]['sections'][] = $block;
                $bsHalves[$section['half']]['total'] += $subtotal;
            } else {
                $isHalves[$section['half']]['sections'][] = $block;
                $isHalves[$section['half']]['total'] += $subtotal;
            }
        }

        // Current-year net income joins equity (mirrors the Balance Sheet report).
        $netIncomeYtd = $this->calc->netIncomeYtd($company, $end);
        $bsHalves['equity']['net_income'] = $netIncomeYtd;
        $bsHalves['equity']['total'] += $netIncomeYtd;

        $totalAssets = $bsHalves['assets']['total'];
        $totalLe = $bsHalves['liabilities']['total'] + $bsHalves['equity']['total'];

        $netIncome = $isHalves['revenue']['total'] - $isHalves['cogs']['total'] - $isHalves['expense']['total'];

        return [
            'bs' => [
                'halves' => $bsHalves,
                'total_assets' => $totalAssets,
                'total_le' => $totalLe,
                'balanced' => $totalAssets === $totalLe,
            ],
            'is' => [
                'halves' => $isHalves,
                'net_income' => $netIncome,
            ],
            'unassigned' => ['lines' => $unassigned, 'total' => $unassignedTotal],
        ];
    }

    /**
     * @return array{label: string, sections: list<array<string, mixed>>, total: int, net_income: int}
     */
    private function emptyHalf(string $label): array
    {
        return ['label' => $label, 'sections' => [], 'total' => 0, 'net_income' => 0];
    }
}
