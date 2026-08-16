<?php

use App\Services\Payroll\EarningsAggregator;
use App\Support\Payroll\RoundingPolicy;

it('rounds bcmath cent strings half-up', function () {
    expect(RoundingPolicy::roundBcToCents('110.4'))->toBe(110)
        ->and(RoundingPolicy::roundBcToCents('110.5'))->toBe(111)
        ->and(RoundingPolicy::roundBcToCents('110.49999'))->toBe(110)
        ->and(RoundingPolicy::roundBcToCents('-110.5'))->toBe(-111)
        ->and(RoundingPolicy::roundBcToCents('0.5'))->toBe(1);
});

it('multiplies cents by a rate and rounds to the nearest cent', function () {
    // 200000 × 1.64% = 3280.00
    expect(RoundingPolicy::centsTimesRate(200000, '0.0164'))->toBe(3280)
        // 3280 × 1.4 = 4592
        ->and(RoundingPolicy::centsTimesRate(3280, '1.4'))->toBe(4592)
        // 186538 × 5.95% = 11099.0 (11098.96... → 11099 via half-up on .0)
        ->and(RoundingPolicy::centsTimesRate(186539, '0.0595'))->toBe(11099);
});

it('rounds cents to the nearest dollar', function () {
    expect(RoundingPolicy::roundCentsToDollar(12345))->toBe(12300)
        ->and(RoundingPolicy::roundCentsToDollar(12350))->toBe(12400)
        ->and(RoundingPolicy::roundCentsToDollar(12349))->toBe(12300);
});

it('aggregates earnings and pre-tax deductions by their flags', function () {
    $breakdown = (new EarningsAggregator)->aggregate(
        earnings: [
            ['amount_cents' => 200000, 'is_pensionable' => true, 'is_insurable' => true, 'is_taxable' => true],
            ['amount_cents' => 50000, 'is_pensionable' => true, 'is_insurable' => false, 'is_taxable' => true], // non-insurable allowance
        ],
        preTaxDeductions: [
            ['amount_cents' => 10000, 'reduces_taxable' => true], // RRSP
            ['amount_cents' => 2000, 'reduces_taxable' => false], // garnishment (no tax effect)
        ],
    );

    expect($breakdown->grossCents)->toBe(250000)
        ->and($breakdown->pensionableCents)->toBe(250000)
        ->and($breakdown->insurableCents)->toBe(200000)
        ->and($breakdown->taxableCents)->toBe(250000) // GROSS taxable; the calculator applies F once
        ->and($breakdown->deductionsPerPeriodCents)->toBe(10000);
});
