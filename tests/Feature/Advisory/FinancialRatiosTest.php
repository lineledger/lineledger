<?php

use App\Services\Reporting\FinancialRatios;

/** A complete metrics pack used as the baseline for the ratio assertions. */
function ratiosPeriod(array $overrides = []): array
{
    return array_merge([
        'days' => 90,
        'revenue_cents' => 150000,
        'cogs_cents' => 30000,
        'gross_profit_cents' => 120000,
        'operating_expense_cents' => 60000,
        'net_income_cents' => 60000,
        'cash_cents' => 80000,
        'ar_cents' => 50000,
        'ap_cents' => 120000,
        'inventory_cents' => 50000,
        'current_assets_cents' => 180000,
        'current_liabilities_cents' => 120000,
    ], $overrides);
}

it('computes liquidity, margin, and turnover ratios', function () {
    $ratios = app(FinancialRatios::class)->compute(ratiosPeriod(), []);

    expect($ratios['current_ratio']['value'])->toBe(1.5)
        ->and($ratios['current_ratio']['display'])->toBe('1.50×')
        ->and($ratios['quick_ratio']['value'])->toBe(1.08)        // (180000 − 50000) / 120000
        ->and($ratios['gross_margin']['pct'])->toBe(80)
        ->and($ratios['gross_margin']['display'])->toBe('80%')
        ->and($ratios['net_margin']['pct'])->toBe(40)
        ->and($ratios['dso_days']['value'])->toBe(30)             // 50000 × 90 / 150000
        ->and($ratios['dso_days']['display'])->toBe('30 days')
        ->and($ratios['dpo_days']['value'])->toBe(360);           // 120000 × 90 / 30000
});

it('degrades to an em-dash when denominators are zero', function () {
    $ratios = app(FinancialRatios::class)->compute(['days' => 30], []);

    expect($ratios['current_ratio']['value'])->toBeNull()
        ->and($ratios['current_ratio']['display'])->toBe('—')
        ->and($ratios['gross_margin']['value'])->toBeNull()
        ->and($ratios['gross_margin']['display'])->toBe('—')
        ->and($ratios['dso_days']['display'])->toBe('—');
});

it('computes cash runway from the recent monthly burn', function () {
    $series = [
        ['net_cents' => -300000],
        ['net_cents' => -700000],
        ['net_cents' => -500000], // average burn = $5,000/mo
    ];

    $ratios = app(FinancialRatios::class)->compute(ratiosPeriod(['cash_cents' => 1000000]), $series);

    expect($ratios['cash_runway_months']['value'])->toBe(2.0)    // $10,000 / $5,000
        ->and($ratios['cash_runway_months']['display'])->toBe('2 months');
});

it('reports no runway when the business is profitable', function () {
    $series = [['net_cents' => 100000], ['net_cents' => 200000]];

    $ratios = app(FinancialRatios::class)->compute(ratiosPeriod(), $series);

    expect($ratios['cash_runway_months']['value'])->toBeNull()
        ->and($ratios['cash_runway_months']['display'])->toBe('Not burning cash');
});
