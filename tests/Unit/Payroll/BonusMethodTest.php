<?php

use App\Services\Payroll\Calculators\CppCalculator;
use App\Services\Payroll\Calculators\EiCalculator;
use App\Services\Payroll\Calculators\IncomeTaxCalculator;
use App\Services\Payroll\Calculators\QpipCalculator;
use App\Services\Payroll\Data\EarningsBreakdown;
use App\Services\Payroll\Data\EmployeePayrollContext;
use App\Services\Payroll\Data\YtdTotals;
use App\Services\Payroll\PayrollDeductionEngine;
use App\Support\Payroll\Constants\PayrollConstantsRepository;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| T4127 bonus method (bonus / retroactive pay)
|--------------------------------------------------------------------------
|
| A bonus is one-time income: withholding on it is the annual-tax DELTA it
| causes on top of annualized regular income + bonuses already paid this
| year — never the result of annualizing the lump as period income.
*/

function bmEngine(): PayrollDeductionEngine
{
    return new PayrollDeductionEngine(
        new PayrollConstantsRepository,
        new CppCalculator,
        new EiCalculator,
        new IncomeTaxCalculator,
        new QpipCalculator,
    );
}

function bmContext(string $province = 'AB'): EmployeePayrollContext
{
    return new EmployeePayrollContext(
        province: $province,
        payPeriodsPerYear: 26,
        payDate: CarbonImmutable::parse('2025-06-15'),
        federalClaimCents: 1612900,
        provincialClaimCents: $province === 'QC' ? 1857100 : 2232300,
        cppExempt: false,
        eiExempt: false,
        additionalTaxPerPeriodCents: 0,
        annualDeductionsCents: 0,
    );
}

/** $2,000 biweekly regular, with an optional bonus slice on top. */
function bmEarnings(int $bonusCents = 0): EarningsBreakdown
{
    $total = 200000 + $bonusCents;

    return new EarningsBreakdown(
        grossCents: $total,
        pensionableCents: $total,
        insurableCents: $total,
        taxableCents: $total,
        bonusTaxableCents: $bonusCents,
    );
}

it('withholds far less on a bonus than annualizing it as period income would', function () {
    $engine = bmEngine();

    $regular = $engine->compute(bmContext(), bmEarnings(), YtdTotals::none());
    $withBonus = $engine->compute(bmContext(), bmEarnings(500000), YtdTotals::none());

    // Treating the $5,000 as ordinary period income (no bonus flag) annualizes
    // it ×26 — the discredited comparison.
    $asPeriodIncome = $engine->compute(
        bmContext(),
        new EarningsBreakdown(700000, 700000, 700000, 700000),
        YtdTotals::none(),
    );

    $bonusTax = ($withBonus->federalTaxCents + $withBonus->provincialTaxCents)
        - ($regular->federalTaxCents + $regular->provincialTaxCents);
    $periodTax = ($asPeriodIncome->federalTaxCents + $asPeriodIncome->provincialTaxCents)
        - ($regular->federalTaxCents + $regular->provincialTaxCents);

    expect($bonusTax)->toBeGreaterThan(0)
        ->and($bonusTax)->toBeLessThan($periodTax);
});

it('matches the annual-tax delta computed directly from the income tax calculator', function () {
    $engine = bmEngine();
    $constants = (new PayrollConstantsRepository)->resolve('AB', CarbonImmutable::parse('2025-06-15'));

    $withBonus = $engine->compute(bmContext(), bmEarnings(500000), YtdTotals::none());

    // Recompute the engine's whole withholding straight from the calculator:
    // pension/EI on the FULL period (CPP/EI never annualize), enhanced CPP
    // apportioned F5A/F5B, regular period tax on the lump-free income, plus
    // the annual-tax delta the lump causes.
    $pension = (new CppCalculator)->compute($constants, 700000, 26, 0, 0, 0, false);
    $ei = (new EiCalculator)->compute($constants, 700000, 0, 0, false);
    $enhancedBonus = (int) round($pension->enhancedDeductibleCents * 500000 / 700000);
    $enhancedRegular = $pension->enhancedDeductibleCents - $enhancedBonus;

    $calc = new IncomeTaxCalculator;
    $args = fn (int $lump) => $calc->compute(
        $constants, 200000, 26, 0, $enhancedRegular, 0, 1612900, 2232300,
        $pension->baseCppEmployeeCents, $ei->eiEmployeeCents, 0, false, $lump,
    );

    $base = $args(0);
    $with = $args(500000 - $enhancedBonus);

    $expectedFederal = $base->federalTaxCents + ($with->federalAnnualTaxCents - $base->federalAnnualTaxCents);
    $expectedProvincial = $base->provincialTaxCents + ($with->provincialAnnualTaxCents - $base->provincialAnnualTaxCents);

    expect($withBonus->federalTaxCents)->toBe($expectedFederal)
        ->and($withBonus->provincialTaxCents)->toBe($expectedProvincial);
});

it('positions a second bonus higher in the brackets via the YTD bonus accumulator', function () {
    $engine = bmEngine();

    // First $20,000 bonus of the year vs the same bonus after $60,000 of
    // bonuses already paid — the later one must cross into higher brackets.
    $first = $engine->compute(bmContext(), bmEarnings(2000000), YtdTotals::none());
    $later = $engine->compute(bmContext(), bmEarnings(2000000), new YtdTotals(bonusTaxableCents: 6000000));

    $firstTax = $first->federalTaxCents + $first->provincialTaxCents;
    $laterTax = $later->federalTaxCents + $later->provincialTaxCents;

    expect($laterTax)->toBeGreaterThan($firstTax);
});

it('routes the bonus delta into Quebec tax for a QC employee', function () {
    $engine = bmEngine();

    $regular = $engine->compute(bmContext('QC'), bmEarnings(), YtdTotals::none());
    $withBonus = $engine->compute(bmContext('QC'), bmEarnings(500000), YtdTotals::none());

    expect($withBonus->quebecTaxCents)->toBeGreaterThan($regular->quebecTaxCents)
        ->and($withBonus->federalTaxCents)->toBeGreaterThan($regular->federalTaxCents)
        ->and($withBonus->provincialTaxCents)->toBe(0);
});

it('keeps CPP and EI period-based on the full amount including the bonus', function () {
    $engine = bmEngine();

    $withBonus = $engine->compute(bmContext(), bmEarnings(500000), YtdTotals::none());
    $flat = $engine->compute(bmContext(), new EarningsBreakdown(700000, 700000, 700000, 700000), YtdTotals::none());

    expect($withBonus->cppEmployeeCents)->toBe($flat->cppEmployeeCents)
        ->and($withBonus->eiEmployeeCents)->toBe($flat->eiEmployeeCents);
});

it('separates the QPIP insurable base from the EI base when the flags diverge', function () {
    $engine = bmEngine();
    $context = new EmployeePayrollContext(
        province: 'QC',
        payPeriodsPerYear: 26,
        payDate: CarbonImmutable::parse('2025-06-15'),
        federalClaimCents: 1612900,
        provincialClaimCents: 1857100,
        cppExempt: false,
        eiExempt: false,
        additionalTaxPerPeriodCents: 0,
        annualDeductionsCents: 0,
    );

    $mirrored = $engine->compute($context, new EarningsBreakdown(200000, 200000, 200000, 200000), YtdTotals::none());
    $split = $engine->compute($context, new EarningsBreakdown(200000, 200000, 200000, 200000, qpipInsurableCents: 150000), YtdTotals::none());

    expect($split->qpipInsurableUsedCents)->toBe(150000)
        ->and($split->qpipEmployeeCents)->toBeLessThan($mirrored->qpipEmployeeCents)
        ->and($split->eiEmployeeCents)->toBe($mirrored->eiEmployeeCents);
});
