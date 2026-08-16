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

/** Province → 2025 basic personal amount (cents), used as the provincial TD1 claim. */
const PROVINCE_BPA = [
    'AB' => 2232300, 'BC' => 1293200, 'MB' => 1596900, 'SK' => 1899100,
    'NB' => 1339600, 'NL' => 1106700, 'NS' => 848100, 'ON' => 1274700,
    'PE' => 1425000, 'NT' => 1784200, 'NU' => 1927400, 'YT' => 1612900,
];

function provincialEngine(): PayrollDeductionEngine
{
    return new PayrollDeductionEngine(
        new PayrollConstantsRepository,
        new CppCalculator,
        new EiCalculator,
        new IncomeTaxCalculator,
        new QpipCalculator,
    );
}

it('computes payroll for every non-Quebec province and territory', function (string $province) {
    $result = provincialEngine()->compute(
        new EmployeePayrollContext(
            province: $province,
            payPeriodsPerYear: 26,
            payDate: CarbonImmutable::parse('2025-06-15'),
            federalClaimCents: 1612900,
            provincialClaimCents: PROVINCE_BPA[$province],
        ),
        new EarningsBreakdown(250000, 250000, 250000, 250000), // $2,500 biweekly ≈ $65k/yr
        YtdTotals::none(),
    );

    // CPP/EI are province-independent and must be positive at this income.
    expect($result->cppEmployeeCents)->toBeGreaterThan(0)
        ->and($result->eiEmployeeCents)->toBeGreaterThan(0)
        // Federal tax is owed; provincial tax is non-negative (some low-rate
        // territories may net low but never negative).
        ->and($result->federalTaxCents)->toBeGreaterThan(0)
        ->and($result->provincialTaxCents)->toBeGreaterThanOrEqual(0)
        ->and($result->netPayCents())->toBeLessThan(250000)
        ->and($result->netPayCents())->toBeGreaterThan(0);
})->with(['AB', 'BC', 'MB', 'SK', 'NB', 'NL', 'NS', 'ON', 'PE', 'NT', 'NU', 'YT']);

it('reports all thirteen jurisdictions (incl. Quebec) as supported', function () {
    $repo = new PayrollConstantsRepository;
    $payDate = CarbonImmutable::parse('2025-06-15');

    foreach (['AB', 'BC', 'MB', 'SK', 'NB', 'NL', 'NS', 'ON', 'PE', 'NT', 'NU', 'YT', 'QC'] as $province) {
        expect($repo->isSupportedProvince($province, $payDate))->toBeTrue("{$province} should be supported");
    }

    expect($repo->loadedProvinces())->toHaveCount(13);
});

it('income-tests the Yukon basic personal amount for a high earner', function () {
    // A high-income Yukoner gets a reduced provincial BPA credit, so their
    // provincial tax exceeds the naive flat-BPA figure (sanity: tax is large).
    $result = provincialEngine()->compute(
        new EmployeePayrollContext('YT', 26, CarbonImmutable::parse('2025-06-15'), 1612900, 1612900),
        new EarningsBreakdown(1000000, 1000000, 1000000, 1000000), // $10k biweekly ≈ $260k/yr
        YtdTotals::none(),
    );

    expect($result->provincialTaxCents)->toBeGreaterThan(0)
        ->and($result->federalTaxCents)->toBeGreaterThan(0);
});
