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

function ageEngine(): PayrollDeductionEngine
{
    return new PayrollDeductionEngine(
        new PayrollConstantsRepository,
        new CppCalculator,
        new EiCalculator,
        new IncomeTaxCalculator,
        new QpipCalculator,
    );
}

/**
 * @param  array<string, mixed>  $overrides
 */
function ageContext(string $province, string $payDate, array $overrides = []): EmployeePayrollContext
{
    return new EmployeePayrollContext(
        province: $province,
        payPeriodsPerYear: 26,
        payDate: CarbonImmutable::parse($payDate),
        federalClaimCents: 1612900,
        provincialClaimCents: $province === 'QC' ? 1857100 : 2232300,
        dateOfBirth: isset($overrides['dob']) ? CarbonImmutable::parse($overrides['dob']) : null,
        cpt30ElectionDate: isset($overrides['cpt30']) ? CarbonImmutable::parse($overrides['cpt30']) : null,
    );
}

function ageEarnings(int $cents): EarningsBreakdown
{
    return new EarningsBreakdown($cents, $cents, $cents, $cents);
}

it('does not deduct CPP before the month after the employee turns 18', function () {
    // Born 2007-03-10 → turns 18 on 2025-03-10 → CPP begins 2025-04-01.
    $before = ageEngine()->compute(ageContext('AB', '2025-03-15', ['dob' => '2007-03-10']), ageEarnings(200000), YtdTotals::none());
    $after = ageEngine()->compute(ageContext('AB', '2025-04-15', ['dob' => '2007-03-10']), ageEarnings(200000), YtdTotals::none());

    expect($before->cppEmployeeCents)->toBe(0)
        ->and($after->cppEmployeeCents)->toBeGreaterThan(0)
        // EI is never affected by age.
        ->and($before->eiEmployeeCents)->toBeGreaterThan(0);
});

it('stops CPP the month after the employee turns 70', function () {
    // Born 1955-03-10 → turns 70 on 2025-03-10 → CPP stops 2025-04-01.
    $before = ageEngine()->compute(ageContext('AB', '2025-03-15', ['dob' => '1955-03-10']), ageEarnings(200000), YtdTotals::none());
    $after = ageEngine()->compute(ageContext('AB', '2025-04-15', ['dob' => '1955-03-10']), ageEarnings(200000), YtdTotals::none());

    expect($before->cppEmployeeCents)->toBeGreaterThan(0)
        ->and($after->cppEmployeeCents)->toBe(0)
        ->and($after->eiEmployeeCents)->toBeGreaterThan(0);
});

it('stops CPP from the month after a CPT30 election is filed', function () {
    // 68-year-old; election filed 2025-02-10 → effective 2025-03-01.
    $emp = ['dob' => '1957-01-01', 'cpt30' => '2025-02-10'];
    $before = ageEngine()->compute(ageContext('AB', '2025-02-15', $emp), ageEarnings(200000), YtdTotals::none());
    $after = ageEngine()->compute(ageContext('AB', '2025-03-15', $emp), ageEarnings(200000), YtdTotals::none());

    expect($before->cppEmployeeCents)->toBeGreaterThan(0)
        ->and($after->cppEmployeeCents)->toBe(0);
});

it('keeps QPP running past 70 and ignores a CPT30 election for Quebec', function () {
    // QPP has no upper-age stop and no CPT30 equivalent.
    $over70 = ageEngine()->compute(ageContext('QC', '2025-04-15', ['dob' => '1955-03-10']), ageEarnings(200000), YtdTotals::none());
    $withCpt30 = ageEngine()->compute(ageContext('QC', '2025-03-15', ['dob' => '1957-01-01', 'cpt30' => '2025-02-10']), ageEarnings(200000), YtdTotals::none());

    expect($over70->qppEmployeeCents)->toBeGreaterThan(0)
        ->and($withCpt30->qppEmployeeCents)->toBeGreaterThan(0);
});

it('does not deduct QPP before the month after a Quebec employee turns 18', function () {
    $before = ageEngine()->compute(ageContext('QC', '2025-03-15', ['dob' => '2007-03-10']), ageEarnings(200000), YtdTotals::none());
    $after = ageEngine()->compute(ageContext('QC', '2025-04-15', ['dob' => '2007-03-10']), ageEarnings(200000), YtdTotals::none());

    expect($before->qppEmployeeCents)->toBe(0)
        ->and($after->qppEmployeeCents)->toBeGreaterThan(0);
});
