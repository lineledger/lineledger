<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Company;
use App\Models\ReportGroup;
use App\Models\ReportGroupAccountMap;
use App\Models\ReportGroupLine;
use App\Services\Reporting\CombinedReportCalculator;
use Carbon\CarbonImmutable;

// acctOfType(), postEntry() and combinedScenario() live in tests/Pest.php: the
// other Combined*/ReportGroup* tests share them.

it('combined balance sheet sums companies and balances', function () {
    $s = combinedScenario();
    $asOf = CarbonImmutable::create(2026, 4, 1);

    $report = app(CombinedReportCalculator::class)->balanceSheet($s['group'], $asOf);

    expect($report['total_assets'])->toBe(100000)          // $700 + $300 cash
        ->and($report['net_income_ytd'])->toBe(100000)     // $700 + $300
        ->and($report['total_le'])->toBe($report['total_assets']); // balances

    // Per-company breakdown on the Cash line: Alpha $700, Bravo $300.
    $cash = collect($report['assets'])
        ->flatMap(fn ($group) => $group['blocks'])
        ->flatMap(fn ($block) => $block['rows'])
        ->firstWhere('name', 'Cash');
    expect($cash['by_company'][$s['a']->id])->toBe(70000)
        ->and($cash['by_company'][$s['b']->id])->toBe(30000);
});

it('combined balance sheet rolls prior fiscal years net income into equity', function () {
    $s = combinedScenario();

    // Alpha also earned $500 in the PRIOR fiscal year (FY starts January, so
    // June 2025 is prior-year activity as of April 2026). With no closing
    // entries, that profit is in cash but on no equity line and outside net
    // income YTD — the report must add it as prior retained earnings.
    $bankA = acctOfType($s['a'], AccountType::Asset);
    postEntry($s['a'], '2025-06-01', [['account' => $bankA, 'debit' => 50000], ['account' => $s['incomeA'], 'credit' => 50000]]);

    $report = app(CombinedReportCalculator::class)->balanceSheet($s['group'], CarbonImmutable::create(2026, 4, 1));

    expect($report['total_assets'])->toBe(150000)                    // $700 + $300 + prior-year $500
        ->and($report['net_income_ytd'])->toBe(100000)               // current FY only
        ->and($report['retained_earnings_prior'])->toBe(50000)
        ->and($report['retained_earnings_prior_by_company'][$s['a']->id])->toBe(50000)
        ->and($report['total_le'])->toBe($report['total_assets']);   // balances
});

it('combined income statement nets revenue and expenses across companies', function () {
    $s = combinedScenario();

    $report = app(CombinedReportCalculator::class)->incomeStatement(
        $s['group'],
        CarbonImmutable::create(2026, 1, 1),
        CarbonImmutable::create(2026, 12, 31),
    );

    expect($report['total_income'])->toBe(150000)   // $1000 + $500
        ->and($report['total_expense'])->toBe(50000) // $300 + $200
        ->and($report['net_income'])->toBe(100000);
});

it('a net line combines an income and an expense account into one figure', function () {
    $s = combinedScenario();
    $bankA = acctOfType($s['a'], AccountType::Asset);

    // Dedicated, otherwise-unmapped accounts so the math is isolated from the
    // Cash/Revenue/Expense lines built by the scenario.
    $rentIncome = Account::withoutGlobalScopes()->where('company_id', $s['a']->id)->where('type', 'income')->orderBy('code')->skip(1)->firstOrFail();
    $rentExpense = Account::withoutGlobalScopes()->where('company_id', $s['a']->id)->where('type', 'expense')->orderBy('code')->skip(1)->firstOrFail();

    // Alpha: rental income $400, rental expense $150 (two balanced entries via bank).
    postEntry($s['a'], '2026-07-01', [['account' => $bankA, 'debit' => 40000], ['account' => $rentIncome, 'credit' => 40000]]);
    postEntry($s['a'], '2026-07-02', [['account' => $rentExpense, 'debit' => 15000], ['account' => $bankA, 'credit' => 15000]]);

    // One Income-type line collecting BOTH the income and the expense account.
    $netLine = ReportGroupLine::create([
        'report_group_id' => $s['group']->id,
        'name' => 'Net Rental',
        'type' => AccountType::Income,
        'sort_order' => 5,
    ]);
    ReportGroupAccountMap::create(['report_group_id' => $s['group']->id, 'report_group_line_id' => $netLine->id, 'company_id' => $s['a']->id, 'account_id' => $rentIncome->id]);
    ReportGroupAccountMap::create(['report_group_id' => $s['group']->id, 'report_group_line_id' => $netLine->id, 'company_id' => $s['a']->id, 'account_id' => $rentExpense->id]);

    $report = app(CombinedReportCalculator::class)->incomeStatement(
        $s['group'],
        CarbonImmutable::create(2026, 1, 1),
        CarbonImmutable::create(2026, 12, 31),
    );

    // $400 income net of $150 expense → $250 shown on the single line.
    $net = collect($report['income'])
        ->flatMap(fn ($block) => $block['rows'])
        ->firstWhere('name', 'Net Rental');
    expect($net['current'])->toBe(25000);
});

it('combined net income YTD respects each company own fiscal year', function () {
    $s = combinedScenario();
    $s['b']->update(['fiscal_year_start_month' => 7]); // Bravo's FY starts in July

    $calc = app(CombinedReportCalculator::class);
    $asOf = CarbonImmutable::create(2026, 4, 1);

    [$total] = $calc->combinedNetIncomeYtd($s['group'], $asOf);

    // Alpha FY (Jan): includes its $700. Bravo FY (Jul 2025–): the March activity
    // falls in the prior fiscal year window that ends before Jul 2026, so as of Apr
    // 2026 Bravo's YTD (since Jul 2025) still includes March 2026 → $300.
    expect($total)->toBe(100000)
        ->and($calc->hasMixedFiscalYears($s['group']))->toBeTrue();
});

it('combined trial balance balances and ignores line mappings', function () {
    $s = combinedScenario();

    $tb = app(CombinedReportCalculator::class)->trialBalance($s['group'], CarbonImmutable::create(2026, 4, 1));

    expect($tb['total_debit'])->toBe($tb['total_credit'])
        ->and($tb['total_debit'])->toBeGreaterThan(0)
        ->and($tb['companies'])->toHaveCount(2);

    foreach ($tb['companies'] as $section) {
        expect($section['total_debit'])->toBe($section['total_credit']);
    }
});

it('detects currency mismatches among members', function () {
    $s = combinedScenario();

    expect(app(CombinedReportCalculator::class)->currencyMismatches($s['group']))->toHaveCount(0);

    $s['b']->update(['currency_code' => 'USD']);

    expect(app(CombinedReportCalculator::class)->currencyMismatches($s['group']))->toHaveCount(1);
});
