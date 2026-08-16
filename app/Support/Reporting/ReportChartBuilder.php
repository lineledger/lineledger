<?php

namespace App\Support\Reporting;

use App\Services\Reporting\CashflowForecaster;
use App\Support\Currency;

/**
 * Turns a report's already-computed `report()` array into chart-ready series for
 * the front-end. Pure and DB-free: every method is a deterministic transform of
 * the arrays produced by the Balance Sheet / Income Statement / Cash Flow
 * components, so it can be unit-tested with hand-built fixtures.
 *
 * All monetary values in the report arrays are integer CENTS, signed in the
 * account's natural-balance direction. This class converts them to dollar floats
 * once, here, so the JS layer never sees cents. Each returned config carries the
 * currency symbol/decimals so the chart's money formatter mirrors App\Support\Money.
 *
 * Each public method returns an ORDERED, keyed map of chart configs — the
 * default/representative chart first — ready to json_encode into the Alpine
 * `chart` component. Config shape:
 *
 *   [
 *     'type'     => 'bar'|'grouped-bar'|'stacked-bar'|'doughnut'|'waterfall',
 *     'title'    => string,
 *     'labels'   => string[],
 *     'datasets' => [['label'=>string, 'data'=>float[]|array[], 'kinds'?, 'deltas'?, 'color'?, 'perPointColors'?], ...],
 *     'currency' => string, 'symbol' => string, 'decimals' => int,
 *     'stacked'? => bool,
 *   ]
 *
 * Not named "ChartBuilder" to avoid confusion with the unrelated
 * App\Support\Defaults\ChartTemplateBuilder (chart of accounts).
 */
final class ReportChartBuilder
{
    // ---------------------------------------------------------------- Balance Sheet

    /**
     * @param  array<string, mixed>  $report  Balance Sheet report() output.
     * @return array<string, array<string, mixed>>
     */
    public static function balanceSheet(array $report, ChartContext $ctx): array
    {
        $charts = [];

        // Default: Assets = Liabilities + Equity, as a two-bar stacked identity.
        if ($report['total_assets'] !== 0 || $report['total_le'] !== 0) {
            $equity = $report['total_equity'] + $report['net_income_ytd'];
            $charts['accounting_equation'] = self::withMoney([
                'type' => 'stacked-bar',
                'title' => $ctx->labels?->accountingEquation() ?? __('Assets = Liabilities + Equity'),
                'labels' => [__('Assets'), $ctx->labels?->liabilitiesAndEquity() ?? __('Liabilities & Equity')],
                'datasets' => [
                    ['label' => __('Assets'), 'data' => [self::cents($report['total_assets']), 0.0]],
                    ['label' => __('Liabilities'), 'data' => [0.0, self::cents($report['total_liabilities'])]],
                    ['label' => $ctx->labels?->equityShort() ?? __('Equity'), 'data' => [0.0, self::cents($equity)]],
                ],
                'stacked' => true,
            ], $ctx);
        }

        // Asset composition by subtype.
        if ($d = self::doughnut(__('Asset composition'), self::subtypeTotals($report['assets']), $ctx)) {
            $charts['asset_composition'] = $d;
        }

        // Liabilities & equity composition by subtype (+ net income line).
        $leTotals = array_merge(self::subtypeTotals($report['liabilities']), self::subtypeTotals($report['equity']));
        if ($report['net_income_ytd'] > 0) {
            $leTotals[$ctx->labels?->netIncomeYtd() ?? __('Net income (YTD)')] = $report['net_income_ytd'];
        }
        if ($d = self::doughnut($ctx->labels?->liabilitiesAndEquity() ?? __('Liabilities & equity'), $leTotals, $ctx)) {
            $charts['liability_equity_composition'] = $d;
        }

        // Current vs prior, only when a comparison column is present.
        if ($ctx->comparison) {
            $charts['current_vs_prior'] = self::comparisonBars(
                'grouped-bar',
                __('Current vs prior'),
                [__('Assets'), __('Liabilities'), $ctx->labels?->equityShort() ?? __('Equity')],
                [$report['total_assets'], $report['total_liabilities'], $report['total_equity'] + $report['net_income_ytd']],
                [$report['prior_total_assets'], $report['prior_total_liabilities'], $report['prior_total_equity'] + $report['prior_net_income_ytd']],
                $ctx,
            );
        }

        return $charts;
    }

    // ------------------------------------------------------------- Income Statement

    /**
     * @param  array<string, mixed>  $report  Income Statement report() output.
     * @return array<string, array<string, mixed>>
     */
    public static function incomeStatement(array $report, ChartContext $ctx): array
    {
        if (self::allZero($report, ['total_income', 'total_cogs', 'total_expense', 'net_income', 'prior_total_income', 'prior_total_cogs', 'prior_total_expense', 'prior_net_income'])) {
            return [];
        }

        $hasCogs = $report['total_cogs'] !== 0 || $report['prior_total_cogs'] !== 0;

        // Profit bridge: Revenue → −COGS → Gross profit → −Expenses → Net income.
        $grossProfitLabel = $ctx->labels?->grossProfit() ?? __('Gross profit');
        $netIncomeLabel = $ctx->labels?->netIncome() ?? __('Net income');

        $steps = [['label' => __('Revenue'), 'delta' => $report['total_income'], 'kind' => 'increase']];
        if ($hasCogs) {
            $steps[] = ['label' => __('COGS'), 'delta' => -$report['total_cogs'], 'kind' => 'decrease'];
            $steps[] = ['label' => $grossProfitLabel, 'delta' => $report['gross_profit'], 'kind' => 'total'];
        }
        $steps[] = ['label' => __('Expenses'), 'delta' => -$report['total_expense'], 'kind' => 'decrease'];
        $steps[] = ['label' => $netIncomeLabel, 'delta' => $report['net_income'], 'kind' => 'total'];
        $bridge = self::waterfall($ctx->labels?->profitBridge() ?? __('Profit bridge'), $steps, $ctx);

        // Summary bar (the comparison-friendly fallback): Income / COGS / Gross
        // profit / Expenses / Net income, grouped current-vs-prior when comparing.
        $labels = [__('Income')];
        $cur = [$report['total_income']];
        $pri = [$report['prior_total_income']];
        if ($hasCogs) {
            $labels[] = __('COGS');
            $cur[] = $report['total_cogs'];
            $pri[] = $report['prior_total_cogs'];
            $labels[] = $grossProfitLabel;
            $cur[] = $report['gross_profit'];
            $pri[] = $report['prior_gross_profit'];
        }
        $labels[] = __('Expenses');
        $cur[] = $report['total_expense'];
        $pri[] = $report['prior_total_expense'];
        $labels[] = $netIncomeLabel;
        $cur[] = $report['net_income'];
        $pri[] = $report['prior_net_income'];
        $summary = self::comparisonBars($ctx->comparison ? 'grouped-bar' : 'bar', __('Summary'), $labels, $cur, $pri, $ctx);

        // Comparison favours the grouped summary; otherwise the bridge tells the
        // single-period story best, so it leads.
        $charts = [];
        if ($ctx->comparison) {
            $charts['summary'] = $summary;
            if ($bridge) {
                $charts['profit_bridge'] = $bridge;
            }
        } else {
            if ($bridge) {
                $charts['profit_bridge'] = $bridge;
            }
            $charts['summary'] = $summary;
        }

        if ($d = self::doughnut(__('Expense breakdown'), self::flatRows($report['expense'], 'current'), $ctx, $ctx->topN)) {
            $charts['expense_breakdown'] = $d;
        }
        if ($d = self::doughnut(__('Income breakdown'), self::flatRows($report['income'], 'current'), $ctx, $ctx->topN)) {
            $charts['income_breakdown'] = $d;
        }

        return $charts;
    }

    // ------------------------------------------------------------------- Cash Flow

    /**
     * @param  array<string, mixed>  $report  Cash Flow report() output.
     * @return array<string, array<string, mixed>>
     */
    public static function cashFlow(array $report, ChartContext $ctx): array
    {
        if (self::allZero($report, ['total_operating', 'total_investing', 'total_financing', 'net_change', 'cash_beginning', 'cash_ending', 'prior_total_operating', 'prior_total_investing', 'prior_total_financing', 'prior_net_change', 'prior_cash_beginning', 'prior_cash_ending'])) {
            return [];
        }

        // Cash bridge: Beginning → ±Operating → ±Investing → ±Financing → Ending.
        $steps = [
            ['label' => __('Beginning cash'), 'delta' => $report['cash_beginning'], 'kind' => 'total'],
            ['label' => __('Operating'), 'delta' => $report['total_operating'], 'kind' => $report['total_operating'] >= 0 ? 'increase' : 'decrease'],
            ['label' => __('Investing'), 'delta' => $report['total_investing'], 'kind' => $report['total_investing'] >= 0 ? 'increase' : 'decrease'],
            ['label' => __('Financing'), 'delta' => $report['total_financing'], 'kind' => $report['total_financing'] >= 0 ? 'increase' : 'decrease'],
            ['label' => __('Ending cash'), 'delta' => $report['cash_ending'], 'kind' => 'total'],
        ];
        $bridge = self::waterfall(__('Cash bridge'), $steps, $ctx);

        $activities = self::comparisonBars(
            $ctx->comparison ? 'grouped-bar' : 'bar',
            __('Activities'),
            [__('Operating'), __('Investing'), __('Financing')],
            [$report['total_operating'], $report['total_investing'], $report['total_financing']],
            [$report['prior_total_operating'], $report['prior_total_investing'], $report['prior_total_financing']],
            $ctx,
        );

        $charts = [];
        if ($ctx->comparison) {
            $charts['activities'] = $activities;
            if ($bridge) {
                $charts['cash_bridge'] = $bridge;
            }
        } else {
            if ($bridge) {
                $charts['cash_bridge'] = $bridge;
            }
            $charts['activities'] = $activities;
        }

        // Operating working-capital drivers (magnitude), excluding the net-income
        // line which the report keeps separate from the operating rows.
        if ($d = self::doughnut(__('Operating drivers'), self::flatRows($report['operating'], 'current'), $ctx, $ctx->topN, useAbs: true)) {
            if (count($d['labels']) >= 2) {
                $charts['operating_composition'] = $d;
            }
        }

        return $charts;
    }

    // ------------------------------------------------------------------ Dashboard

    /**
     * Grouped inflow/outflow bar from the dashboard's hand-rolled 6-month series.
     *
     * @param  array<int, array{label: string, inflow: int, outflow: int}>  $rows
     * @return array<string, array<string, mixed>>
     */
    public static function dashboardCashFlow(array $rows, ChartContext $ctx): array
    {
        if ($rows === []) {
            return [];
        }

        return ['cash_flow' => self::withMoney([
            'type' => 'grouped-bar',
            'title' => __('Cash flow'),
            'labels' => array_map(fn ($r) => $r['label'], $rows),
            'datasets' => [
                ['label' => __('Inflow'), 'data' => array_map(fn ($r) => self::cents($r['inflow']), $rows), 'color' => '#1D9E75'],
                ['label' => __('Outflow'), 'data' => array_map(fn ($r) => self::cents($r['outflow']), $rows), 'color' => '#A1A1AA'],
            ],
        ], $ctx)];
    }

    /**
     * Year-to-date Income / Expenses / Net income snapshot for the dashboard.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function plSnapshot(int $income, int $expense, int $net, ChartContext $ctx): array
    {
        if ($income === 0 && $expense === 0 && $net === 0) {
            return [];
        }

        return ['pl' => self::withMoney([
            'type' => 'bar',
            'title' => __('Income & expenses'),
            'labels' => [__('Income'), __('Expenses'), $ctx->labels?->netIncome() ?? __('Net income')],
            'datasets' => [[
                'label' => __('Year to date'),
                'data' => [self::cents($income), self::cents($expense), self::cents($net)],
                'perPointColors' => ['#1D9E75', '#EF4444', $net < 0 ? '#EF4444' : '#2563EB'],
            ]],
        ], $ctx)];
    }

    /**
     * Cash / Receivable / Payable position snapshot for the dashboard.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function positionSnapshot(int $cash, int $receivable, int $payable, ChartContext $ctx): array
    {
        if ($cash === 0 && $receivable === 0 && $payable === 0) {
            return [];
        }

        return ['position' => self::withMoney([
            'type' => 'bar',
            'title' => __('Cash, receivables & payables'),
            'labels' => [__('Cash'), __('Receivable'), __('Payable')],
            'datasets' => [[
                'label' => __('As of today'),
                'data' => [self::cents($cash), self::cents($receivable), self::cents($payable)],
                'perPointColors' => ['#1D9E75', '#2563EB', '#F59E0B'],
            ]],
        ], $ctx)];
    }

    /**
     * Cashflow forecast charts from {@see CashflowForecaster::forecast()}.
     * The default chart is the projected committed closing balance per period
     * (red where it dips below the floor); a second breaks out money expected in
     * vs out.
     *
     * @param  array<string, mixed>  $forecast
     * @return array<string, array<string, mixed>>
     */
    public static function cashflowForecast(array $forecast, ChartContext $ctx): array
    {
        /** @var list<array<string, mixed>> $periods */
        $periods = $forecast['periods'] ?? [];

        if ($periods === []) {
            return [];
        }

        $labels = array_map(fn (array $period): string => (string) $period['label'], $periods);

        $charts = ['balance' => self::withMoney([
            'type' => 'bar',
            'title' => __('Projected cash balance'),
            'labels' => $labels,
            'datasets' => [[
                'label' => __('Committed closing'),
                'data' => array_map(fn (array $period): float => self::cents((int) $period['committed_closing_cents']), $periods),
                'perPointColors' => array_map(
                    fn (array $period): string => $period['below_floor'] ? '#EF4444' : '#2563EB',
                    $periods,
                ),
            ]],
        ], $ctx)];

        $hasFlows = false;
        foreach ($periods as $period) {
            if ($period['scheduled_in_cents'] !== 0 || $period['scheduled_out_cents'] !== 0) {
                $hasFlows = true;
                break;
            }
        }

        if ($hasFlows) {
            $charts['flows'] = self::withMoney([
                'type' => 'grouped-bar',
                'title' => __('Money in vs out'),
                'labels' => $labels,
                'datasets' => [
                    ['label' => __('Expected in'), 'data' => array_map(fn (array $period): float => self::cents((int) $period['scheduled_in_cents']), $periods), 'color' => '#1D9E75'],
                    ['label' => __('Expected out'), 'data' => array_map(fn (array $period): float => self::cents((int) $period['scheduled_out_cents']), $periods), 'color' => '#A1A1AA'],
                ],
            ], $ctx);
        }

        return $charts;
    }

    // -------------------------------------------------------------------- Helpers

    /** Integer cents → dollar float, two decimals. */
    private static function cents(int $cents): float
    {
        return round($cents / 100, 2);
    }

    /** Attach currency metadata so the JS formatter mirrors App\Support\Money. */
    private static function withMoney(array $config, ChartContext $ctx): array
    {
        return $config + [
            'currency' => $ctx->currency,
            'symbol' => Currency::symbol($ctx->currency),
            'decimals' => Currency::decimals($ctx->currency),
        ];
    }

    /**
     * Single (or grouped, when comparing) bar config. The prior dataset is
     * appended only when the report has a comparison column.
     *
     * @param  array<int, int>  $currentCents
     * @param  array<int, int>  $priorCents
     */
    private static function comparisonBars(string $type, string $title, array $labels, array $currentCents, array $priorCents, ChartContext $ctx): array
    {
        $datasets = [[
            'label' => $ctx->comparison ? ($ctx->periodLabel ?: __('Current')) : __('Amount'),
            'data' => array_map(fn ($c) => self::cents($c), $currentCents),
        ]];

        if ($ctx->comparison) {
            $datasets[] = [
                'label' => $ctx->priorLabel ?: __('Prior'),
                'data' => array_map(fn ($c) => self::cents($c), $priorCents),
            ];
        }

        return self::withMoney([
            'type' => $type,
            'title' => $title,
            'labels' => $labels,
            'datasets' => $datasets,
        ], $ctx);
    }

    /**
     * Composition doughnut from a label => cents map. Keeps positive contributors
     * (or magnitudes when $useAbs), rolls a long tail into "Other", and returns
     * null when there is nothing to plot.
     *
     * @param  array<string, int>  $labelToCents
     */
    private static function doughnut(string $title, array $labelToCents, ChartContext $ctx, ?int $topN = null, bool $useAbs = false): ?array
    {
        $values = [];
        foreach ($labelToCents as $label => $cents) {
            $v = $useAbs ? abs($cents) : $cents;
            if ($v > 0) {
                $values[$label] = $v;
            }
        }

        if ($values === []) {
            return null;
        }

        arsort($values);

        if ($topN !== null && count($values) > $topN) {
            $top = array_slice($values, 0, $topN, true);
            $other = array_sum(array_slice($values, $topN, null, true));
            if ($other > 0) {
                $top[__('Other')] = $other;
            }
            $values = $top;
        }

        return self::withMoney([
            'type' => 'doughnut',
            'title' => $title,
            'labels' => array_keys($values),
            'datasets' => [[
                'label' => $title,
                'data' => array_map(fn ($c) => self::cents($c), array_values($values)),
            ]],
        ], $ctx);
    }

    /**
     * Floating-bar "waterfall". Each step is a signed delta (or, for 'total'
     * steps, the absolute running value at that point). Returns null when every
     * step is zero. The JS adapter colours bars by `kinds` and shows `deltas` in
     * the tooltip.
     *
     * @param  array<int, array{label: string, delta: int, kind: string}>  $steps
     */
    private static function waterfall(string $title, array $steps, ChartContext $ctx): ?array
    {
        if ($steps === [] || ! self::anyNonZero($steps)) {
            return null;
        }

        $labels = [];
        $data = [];
        $kinds = [];
        $deltas = [];
        $running = 0;

        foreach ($steps as $step) {
            $labels[] = $step['label'];
            $kinds[] = $step['kind'];

            if ($step['kind'] === 'total') {
                $value = $step['delta'];
                $data[] = [self::cents(min(0, $value)), self::cents(max(0, $value))];
                $deltas[] = self::cents($value);
                $running = $value;
            } else {
                $start = $running;
                $end = $running + $step['delta'];
                $data[] = [self::cents(min($start, $end)), self::cents(max($start, $end))];
                $deltas[] = self::cents($step['delta']);
                $running = $end;
            }
        }

        return self::withMoney([
            'type' => 'waterfall',
            'title' => $title,
            'labels' => $labels,
            'datasets' => [[
                'label' => $title,
                'data' => $data,
                'kinds' => $kinds,
                'deltas' => $deltas,
            ]],
        ], $ctx);
    }

    /**
     * Whether every named key in the report is zero — used to skip charting an
     * empty company entirely (so the panel shows its "no data" state).
     *
     * @param  array<string, mixed>  $report
     * @param  array<int, string>  $keys
     */
    private static function allZero(array $report, array $keys): bool
    {
        foreach ($keys as $key) {
            if (($report[$key] ?? 0) !== 0) {
                return false;
            }
        }

        return true;
    }

    /** @param array<int, array{delta: int}> $steps */
    private static function anyNonZero(array $steps): bool
    {
        foreach ($steps as $step) {
            if (($step['delta'] ?? 0) !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sum a Balance-Sheet subtype-keyed bucket into label => cents, dropping
     * zero subtypes. Bucket shape: [subtypeKey => ['label'=>.., 'blocks'=>[...]]].
     *
     * @param  array<string, array{label: string, blocks: array<int, mixed>}>  $bucket
     * @return array<string, int>
     */
    private static function subtypeTotals(array $bucket): array
    {
        $out = [];

        foreach ($bucket as $group) {
            $sum = 0;
            foreach ($group['blocks'] as $block) {
                foreach ($block['rows'] as $row) {
                    $sum += $row['balance'] ?? 0;
                }
            }
            if ($sum !== 0) {
                $out[$group['label']] = ($out[$group['label']] ?? 0) + $sum;
            }
        }

        return $out;
    }

    /**
     * Flatten an Income-Statement / Cash-Flow bucket (a flat block list) into
     * name => cents, summing rows that share a name.
     *
     * @param  array<int, array{rows: array<int, mixed>}>  $blocks
     * @return array<string, int>
     */
    private static function flatRows(array $blocks, string $valueKey): array
    {
        $out = [];

        foreach ($blocks as $block) {
            foreach ($block['rows'] as $row) {
                $name = $row['name'] ?? '';
                $out[$name] = ($out[$name] ?? 0) + ($row[$valueKey] ?? 0);
            }
        }

        return $out;
    }
}
