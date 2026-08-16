<?php

use App\Support\Reporting\ChartContext;
use App\Support\Reporting\ReportChartBuilder;
use Tests\TestCase;

// ReportChartBuilder labels go through __(), so boot the app (no DB needed).
uses(TestCase::class);

/** A Balance-Sheet subtype group (label + a single unassigned block). */
function cb_bsGroup(string $label, int $balance, int $prior = 0): array
{
    return ['label' => $label, 'blocks' => [[
        'type' => 'unassigned',
        'rows' => [['id' => 1, 'name' => $label, 'code' => '', 'balance' => $balance, 'prior' => $prior, 'section_id' => null]],
        'subtotal' => $balance,
        'prior_subtotal' => $prior,
    ]]];
}

/** A flat Income-Statement / Cash-Flow block from [name => currentCents]. */
function cb_flatBlock(array $rows): array
{
    $out = [];
    $subtotal = 0;
    foreach ($rows as $name => $current) {
        $out[] = ['id' => 1, 'code' => '', 'name' => $name, 'current' => $current, 'prior' => 0, 'section_id' => null];
        $subtotal += $current;
    }

    return ['type' => 'unassigned', 'rows' => $out, 'subtotal' => $subtotal, 'prior_subtotal' => 0];
}

function cb_balanceSheetReport(): array
{
    return [
        'assets' => [
            'bank' => cb_bsGroup('Bank', 100000),
            'accounts_receivable' => cb_bsGroup('Accounts Receivable', 50000),
        ],
        'liabilities' => ['accounts_payable' => cb_bsGroup('Accounts Payable', 30000)],
        'equity' => ['common_stock' => cb_bsGroup('Common Stock', 100000)],
        'total_assets' => 150000,
        'total_liabilities' => 30000,
        'total_equity' => 100000,
        'net_income_ytd' => 20000,
        'total_le' => 150000,
        'prior_total_assets' => 120000,
        'prior_total_liabilities' => 20000,
        'prior_total_equity' => 90000,
        'prior_net_income_ytd' => 10000,
        'prior_total_le' => 120000,
    ];
}

function cb_incomeStatementReport(): array
{
    return [
        'income' => [cb_flatBlock(['Sales' => 100000, 'Services' => 50000])],
        'cogs' => [cb_flatBlock(['Materials' => 40000])],
        'expense' => [cb_flatBlock(['Salaries' => 30000, 'Rent' => 20000])],
        'total_income' => 150000,
        'total_cogs' => 40000,
        'total_expense' => 50000,
        'gross_profit' => 110000,
        'net_income' => 60000,
        'prior_total_income' => 120000,
        'prior_total_cogs' => 30000,
        'prior_total_expense' => 45000,
        'prior_gross_profit' => 90000,
        'prior_net_income' => 45000,
    ];
}

function cb_cashFlowReport(): array
{
    return [
        'operating' => [cb_flatBlock(['Accounts Receivable' => -5000, 'Accounts Payable' => 3000])],
        'investing' => [cb_flatBlock(['Equipment' => -10000])],
        'financing' => [cb_flatBlock(['Loan' => 20000])],
        'net_income' => 50000,
        'total_operating' => 48000,
        'total_investing' => -10000,
        'total_financing' => 20000,
        'net_change' => 58000,
        'cash_beginning' => 100000,
        'cash_ending' => 158000,
        'reconciles' => true,
        'prior_net_income' => 0,
        'prior_total_operating' => 0,
        'prior_total_investing' => 0,
        'prior_total_financing' => 0,
        'prior_net_change' => 0,
        'prior_cash_beginning' => 0,
        'prior_cash_ending' => 0,
    ];
}

// ---------------------------------------------------------------- Balance Sheet

it('leads the balance sheet with the accounting-equation chart and converts cents to dollars', function () {
    $charts = ReportChartBuilder::balanceSheet(cb_balanceSheetReport(), new ChartContext(currency: 'USD'));

    expect(array_key_first($charts))->toBe('accounting_equation');

    $eq = $charts['accounting_equation'];
    expect($eq['type'])->toBe('stacked-bar')
        ->and($eq['stacked'])->toBeTrue()
        ->and($eq['labels'])->toBe(['Assets', 'Liabilities & Equity'])
        ->and($eq['symbol'])->toBe('$')
        ->and($eq['decimals'])->toBe(2);

    // Assets 150000c → 1500.00; Equity = total_equity + net income = 120000c → 1200.00.
    expect($eq['datasets'][0]['data'])->toBe([1500.0, 0.0])
        ->and($eq['datasets'][1]['data'])->toBe([0.0, 300.0])
        ->and($eq['datasets'][2]['data'])->toBe([0.0, 1200.0]);
});

it('builds asset and liability/equity composition doughnuts sorted by magnitude', function () {
    $charts = ReportChartBuilder::balanceSheet(cb_balanceSheetReport(), new ChartContext(currency: 'USD'));

    expect($charts)->toHaveKey('asset_composition');
    $assets = $charts['asset_composition'];
    expect($assets['type'])->toBe('doughnut')
        ->and($assets['labels'])->toBe(['Bank', 'Accounts Receivable'])
        ->and($assets['datasets'][0]['data'])->toBe([1000.0, 500.0]);

    // The YTD net income line is folded into the L&E composition.
    expect($charts['liability_equity_composition']['labels'])->toContain('Net income (YTD)');
});

it('omits current-vs-prior on the balance sheet unless comparison is on', function () {
    expect(ReportChartBuilder::balanceSheet(cb_balanceSheetReport(), new ChartContext(comparison: false)))
        ->not->toHaveKey('current_vs_prior');

    $charts = ReportChartBuilder::balanceSheet(cb_balanceSheetReport(), new ChartContext(comparison: true, priorLabel: 'prior year'));
    expect($charts)->toHaveKey('current_vs_prior')
        ->and($charts['current_vs_prior']['type'])->toBe('grouped-bar')
        ->and($charts['current_vs_prior']['datasets'])->toHaveCount(2);
});

// ------------------------------------------------------------- Income Statement

it('leads the income statement with the profit-bridge waterfall when not comparing', function () {
    $charts = ReportChartBuilder::incomeStatement(cb_incomeStatementReport(), new ChartContext(comparison: false));

    expect(array_key_first($charts))->toBe('profit_bridge');

    $w = $charts['profit_bridge'];
    expect($w['type'])->toBe('waterfall')
        ->and($w['labels'])->toBe(['Revenue', 'COGS', 'Gross profit', 'Expenses', 'Net income'])
        ->and($w['datasets'][0]['deltas'])->toBe([1500.0, -400.0, 1100.0, -500.0, 600.0])
        ->and($w['datasets'][0]['kinds'])->toBe(['increase', 'decrease', 'total', 'decrease', 'total'])
        // Floating [start,end] pairs in dollars chain Revenue→COGS→…→Net income.
        ->and($w['datasets'][0]['data'])->toBe([[0.0, 1500.0], [1100.0, 1500.0], [0.0, 1100.0], [600.0, 1100.0], [0.0, 600.0]]);
});

it('leads the income statement with the grouped summary when comparing', function () {
    $charts = ReportChartBuilder::incomeStatement(cb_incomeStatementReport(), new ChartContext(comparison: true, priorLabel: 'prior year'));

    expect(array_key_first($charts))->toBe('summary')
        ->and($charts['summary']['type'])->toBe('grouped-bar')
        ->and($charts['summary']['datasets'])->toHaveCount(2)
        ->and($charts)->toHaveKey('profit_bridge'); // still available, just not first
});

it('rolls a long expense list into a Top-N + Other doughnut', function () {
    $report = cb_incomeStatementReport();
    $report['expense'] = [cb_flatBlock([
        'E1' => 80000, 'E2' => 70000, 'E3' => 60000, 'E4' => 50000,
        'E5' => 40000, 'E6' => 30000, 'E7' => 20000, 'E8' => 10000,
    ])];
    $report['total_expense'] = 360000;

    $charts = ReportChartBuilder::incomeStatement($report, new ChartContext(topN: 6));
    $breakdown = $charts['expense_breakdown'];

    expect($breakdown['labels'])->toHaveCount(7)            // 6 + Other
        ->and($breakdown['labels'][6])->toBe('Other')
        ->and($breakdown['datasets'][0]['data'][6])->toBe(300.0); // (20000 + 10000)c
});

// ------------------------------------------------------------------- Cash Flow

it('leads the cash flow with a reconciling cash-bridge waterfall', function () {
    $charts = ReportChartBuilder::cashFlow(cb_cashFlowReport(), new ChartContext(comparison: false));

    expect(array_key_first($charts))->toBe('cash_bridge');

    $w = $charts['cash_bridge'];
    expect($w['labels'])->toBe(['Beginning cash', 'Operating', 'Investing', 'Financing', 'Ending cash'])
        ->and($w['datasets'][0]['deltas'])->toBe([1000.0, 480.0, -100.0, 200.0, 1580.0])
        ->and($w['datasets'][0]['kinds'])->toBe(['total', 'increase', 'decrease', 'increase', 'total']);

    // The running total lands exactly on Ending cash (the statement reconciles).
    $data = $w['datasets'][0]['data'];
    expect($data[0])->toBe([0.0, 1000.0])     // Beginning
        ->and($data[4])->toBe([0.0, 1580.0]); // Ending = beginning + net change
});

it('leads the cash flow with grouped activities when comparing', function () {
    $charts = ReportChartBuilder::cashFlow(cb_cashFlowReport(), new ChartContext(comparison: true, priorLabel: 'prior period'));

    expect(array_key_first($charts))->toBe('activities')
        ->and($charts['activities']['datasets'])->toHaveCount(2);
});

// ---------------------------------------------------------------- Empty company

it('returns no charts for an all-zero company', function () {
    $bs = ReportChartBuilder::balanceSheet([
        'assets' => [], 'liabilities' => [], 'equity' => [],
        'total_assets' => 0, 'total_liabilities' => 0, 'total_equity' => 0,
        'net_income_ytd' => 0, 'total_le' => 0,
        'prior_total_assets' => 0, 'prior_total_liabilities' => 0, 'prior_total_equity' => 0,
        'prior_net_income_ytd' => 0, 'prior_total_le' => 0,
    ], new ChartContext);

    $is = ReportChartBuilder::incomeStatement([
        'income' => [], 'cogs' => [], 'expense' => [],
        'total_income' => 0, 'total_cogs' => 0, 'total_expense' => 0, 'gross_profit' => 0, 'net_income' => 0,
        'prior_total_income' => 0, 'prior_total_cogs' => 0, 'prior_total_expense' => 0, 'prior_gross_profit' => 0, 'prior_net_income' => 0,
    ], new ChartContext);

    expect($bs)->toBe([])->and($is)->toBe([]);
});

// ----------------------------------------------------------------- Dashboard

it('builds dashboard snapshots and skips empty ones', function () {
    $ctx = new ChartContext(currency: 'CAD');

    $cf = ReportChartBuilder::dashboardCashFlow(
        [['label' => 'Jan', 'inflow' => 100000, 'outflow' => 40000]],
        $ctx,
    );
    expect($cf['cash_flow']['type'])->toBe('grouped-bar')
        ->and($cf['cash_flow']['datasets'][0]['data'])->toBe([1000.0])
        ->and($cf['cash_flow']['datasets'][1]['data'])->toBe([400.0]);

    expect(ReportChartBuilder::plSnapshot(150000, 90000, 60000, $ctx)['pl']['datasets'][0]['data'])->toBe([1500.0, 900.0, 600.0]);
    expect(ReportChartBuilder::plSnapshot(0, 0, 0, $ctx))->toBe([]);
    expect(ReportChartBuilder::positionSnapshot(0, 0, 0, $ctx))->toBe([]);
});
