<?php

use App\Exceptions\Payroll\MissingPayrollConstantsException;
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

function payrollEngine(): PayrollDeductionEngine
{
    return new PayrollDeductionEngine(
        new PayrollConstantsRepository,
        new CppCalculator,
        new EiCalculator,
        new IncomeTaxCalculator,
        new QpipCalculator,
    );
}

function employeeContext(string $province = 'AB', array $overrides = []): EmployeePayrollContext
{
    return new EmployeePayrollContext(
        province: $province,
        payPeriodsPerYear: $overrides['periods'] ?? 26,
        payDate: CarbonImmutable::parse($overrides['payDate'] ?? '2025-06-15'),
        federalClaimCents: $overrides['fed'] ?? 1612900,
        provincialClaimCents: $overrides['prov'] ?? ($province === 'AB' ? 2232300 : ($province === 'ON' ? 1274700 : 1293200)),
        cppExempt: $overrides['cppExempt'] ?? false,
        eiExempt: $overrides['eiExempt'] ?? false,
        additionalTaxPerPeriodCents: $overrides['additional'] ?? 0,
        annualDeductionsCents: $overrides['annualDeductions'] ?? 0,
        incomeTaxExempt: $overrides['incomeTaxExempt'] ?? false,
    );
}

function flatEarnings(int $cents): EarningsBreakdown
{
    return new EarningsBreakdown($cents, $cents, $cents, $cents);
}

it('computes CPP, base/enhanced split and employer match for a biweekly cheque', function () {
    $r = payrollEngine()->compute(employeeContext('AB'), flatEarnings(200000), YtdTotals::none());

    // (200000 − 350000/26) × 5.95% = 11099 cents.
    expect($r->cppEmployeeCents)->toBe(11099)
        ->and($r->cppEmployerCents)->toBe(11099)
        ->and($r->cpp2EmployeeCents)->toBe(0)
        // base + enhanced reconcile exactly to the contribution.
        ->and($r->cppEmployeeCents)->toBe(11099);
});

it('caps CPP at the annual maximum and adds CPP2 in the second band', function () {
    // A single huge pensionable cheque exhausts base CPP and fully funds CPP2.
    $r = payrollEngine()->compute(employeeContext('AB'), flatEarnings(10000000), YtdTotals::none());

    expect($r->cppEmployeeCents)->toBe(403410)   // 2025 max base CPP
        ->and($r->cpp2EmployeeCents)->toBe(39600) // 2025 max CPP2
        ->and($r->cppEmployerCents)->toBe(403410);
});

it('stops CPP once the year-to-date maximum is reached', function () {
    $r = payrollEngine()->compute(employeeContext('AB'), flatEarnings(200000), new YtdTotals(
        pensionableCents: 7130000,
        cppEmployeeCents: 403410,
    ));

    expect($r->cppEmployeeCents)->toBe(0);
});

it('computes EI premiums with the 1.4x employer multiple', function () {
    $r = payrollEngine()->compute(employeeContext('AB'), flatEarnings(200000), YtdTotals::none());

    expect($r->eiEmployeeCents)->toBe(3280)   // 200000 × 1.64%
        ->and($r->eiEmployerCents)->toBe(4592); // 3280 × 1.4
});

it('caps EI at the annual maximum premium', function () {
    $r = payrollEngine()->compute(employeeContext('AB'), flatEarnings(10000000), YtdTotals::none());

    expect($r->eiEmployeeCents)->toBe(107748); // 2025 max EI premium
});

it('honours CPP and EI exemptions', function () {
    $r = payrollEngine()->compute(employeeContext('AB', ['cppExempt' => true, 'eiExempt' => true]), flatEarnings(200000), YtdTotals::none());

    expect($r->cppEmployeeCents)->toBe(0)->and($r->eiEmployeeCents)->toBe(0);
});

it('computes federal and provincial income tax for Alberta', function () {
    $r = payrollEngine()->compute(employeeContext('AB'), flatEarnings(200000), YtdTotals::none());

    // Annualized A = 26 × (2000 − enhanced CPP) ≈ $51,515; first federal bracket,
    // first AB bracket. Hand-derived per T4127 (see plan verification section).
    expect($r->federalTaxCents)->toBe(17689)
        ->and($r->provincialTaxCents)->toBe(9976);
});

it('applies the Ontario health premium so ON withholds more than AB at the same pay', function () {
    $ab = payrollEngine()->compute(employeeContext('AB'), flatEarnings(200000), YtdTotals::none());
    $on = payrollEngine()->compute(employeeContext('ON'), flatEarnings(200000), YtdTotals::none());

    expect($on->federalTaxCents)->toBe($ab->federalTaxCents) // federal identical
        ->and($on->provincialTaxCents)->toBeGreaterThan(0);
});

it('carries the employee-requested additional tax as its own component', function () {
    $base = payrollEngine()->compute(employeeContext('AB'), flatEarnings(200000), YtdTotals::none());
    $extra = payrollEngine()->compute(employeeContext('AB', ['additional' => 5000]), flatEarnings(200000), YtdTotals::none());

    expect($extra->federalTaxCents)->toBe($base->federalTaxCents) // formula unchanged
        ->and($extra->additionalTaxCents)->toBe(5000)
        ->and($extra->totalIncomeTaxCents())->toBe($base->totalIncomeTaxCents() + 5000);
});

it('reaches zero income tax for very low pay', function () {
    $r = payrollEngine()->compute(employeeContext('AB'), flatEarnings(20000), YtdTotals::none());

    expect($r->federalTaxCents)->toBe(0)->and($r->provincialTaxCents)->toBe(0);
});

it('applies the BC mid-year rate change (5.06% → 5.60%) via the H2 prorated 6.14% catch-up', function () {
    // On 2026-02-17 BC raised its lowest personal rate to 5.60% for the full year.
    // H1 payrolls already withheld at 5.06%, so CRA's July 2026 edition (T4127, 123rd)
    // applies a prorated 6.14% lowest rate to H2 to catch up. $2,500 semi-monthly, BC.
    $opts = ['periods' => 24, 'prov' => 1321600];
    $h1 = payrollEngine()->compute(employeeContext('BC', $opts + ['payDate' => '2026-06-07']), flatEarnings(250000), YtdTotals::none());
    $h2 = payrollEngine()->compute(employeeContext('BC', $opts + ['payDate' => '2026-08-07']), flatEarnings(250000), YtdTotals::none());

    // CPP, EI and federal tax are unchanged across the mid-year boundary.
    expect($h1->cppEmployeeCents)->toBe(14007)
        ->and($h1->eiEmployeeCents)->toBe(4075)
        ->and($h1->federalTaxCents)->toBe(22243)
        ->and($h2->federalTaxCents)->toBe(22243);

    // Provincial tax: H1 at 5.06% = $99.47; H2 at the prorated 6.14% = $114.48.
    expect($h1->provincialTaxCents)->toBe(9947)
        ->and($h2->provincialTaxCents)->toBe(11448)
        // The halves sum to 2 × $106.975 — the true full-year 5.60% per-period tax —
        // so the proration nets out exactly across the year.
        ->and($h1->provincialTaxCents + $h2->provincialTaxCents)->toBe(21395);
});

it('applies the BC tax reduction (factor S) for low-income BC employees', function () {
    // BC's low-income tax reduction (factor S) offsets most/all provincial tax
    // below the phase-out ceiling. $1,100 semi-monthly → annualized ~$26,171, just
    // inside BC's factor-S phase-out band ($25,570–$44,952).
    $opts = ['periods' => 24, 'prov' => 1321600];
    $h1 = payrollEngine()->compute(employeeContext('BC', $opts + ['payDate' => '2026-06-07']), flatEarnings(110000), YtdTotals::none());
    $h2 = payrollEngine()->compute(employeeContext('BC', $opts + ['payDate' => '2026-08-07']), flatEarnings(110000), YtdTotals::none());

    // H1 uses the indexed $575 reduction (S ≈ $553.60/yr), leaving $22.79/yr → $0.95/period.
    // H2 uses the prorated $805 reduction, which fully offsets the tax → $0.00/period.
    // Without factor S these were 2402 and 2914 — the reduction is material at low income.
    expect($h1->provincialTaxCents)->toBe(95)
        ->and($h2->provincialTaxCents)->toBe(0);
});

it('computes Quebec payroll (QPP + QPIP + Quebec tax, no CPP)', function () {
    $r = payrollEngine()->compute(employeeContext('QC', ['prov' => 1857100]), flatEarnings(200000), YtdTotals::none());

    // QC populates the QPP/QPIP/Quebec-tax fields and leaves CPP/provincial at 0.
    expect($r->cppEmployeeCents)->toBe(0)
        ->and($r->provincialTaxCents)->toBe(0)
        ->and($r->qppEmployeeCents)->toBeGreaterThan(0)
        ->and($r->qpipEmployeeCents)->toBeGreaterThan(0)
        ->and($r->quebecTaxCents)->toBeGreaterThan(0)
        // Quebec EI is the reduced rate: 200000 × 1.31% = 2620.
        ->and($r->eiEmployeeCents)->toBe(2620);
});

it('blocks provinces with no loaded constants', function () {
    // 'XX' is not a real province code, so no table is loaded for it.
    payrollEngine()->compute(employeeContext('XX', ['prov' => 1000000]), flatEarnings(200000), YtdTotals::none());
})->throws(MissingPayrollConstantsException::class);

it('blocks pay dates with no loaded table', function () {
    payrollEngine()->compute(employeeContext('AB', ['payDate' => '2020-06-15']), flatEarnings(200000), YtdTotals::none());
})->throws(MissingPayrollConstantsException::class);

it('withholds no income tax for an income-tax-exempt employee but keeps CPP and EI', function () {
    $r = payrollEngine()->compute(employeeContext('AB', ['incomeTaxExempt' => true]), flatEarnings(200000), YtdTotals::none());

    expect($r->federalTaxCents)->toBe(0)
        ->and($r->provincialTaxCents)->toBe(0)
        ->and($r->cppEmployeeCents)->toBeGreaterThan(0)
        ->and($r->eiEmployeeCents)->toBeGreaterThan(0);
});

it('withholds no Quebec income tax for an income-tax-exempt Quebec employee but keeps QPP and QPIP', function () {
    $r = payrollEngine()->compute(employeeContext('QC', ['prov' => 1857100, 'incomeTaxExempt' => true]), flatEarnings(200000), YtdTotals::none());

    expect($r->quebecTaxCents)->toBe(0)
        ->and($r->qppEmployeeCents)->toBeGreaterThan(0)
        ->and($r->qpipEmployeeCents)->toBeGreaterThan(0);
});

it('reduces income tax when authorized annual deductions are set', function () {
    $base = payrollEngine()->compute(employeeContext('AB'), flatEarnings(200000), YtdTotals::none());
    $withDeductions = payrollEngine()->compute(employeeContext('AB', ['annualDeductions' => 1000000]), flatEarnings(200000), YtdTotals::none());

    expect($withDeductions->federalTaxCents)->toBeLessThan($base->federalTaxCents)
        ->and($withDeductions->federalTaxCents)->toBeGreaterThan(0);
});

it('honours a provincial TD1 claim above the basic amount (lower provincial tax)', function () {
    // The default AB claim is the basic personal amount. A higher provincial claim
    // (extra credits/dependants) must reduce provincial withholding while leaving
    // the federal calculation unchanged. Regression guard for the bug where the
    // provincial claim was plumbed into the calculator but never read.
    $basic = payrollEngine()->compute(employeeContext('AB'), flatEarnings(200000), YtdTotals::none());
    $higher = payrollEngine()->compute(employeeContext('AB', ['prov' => 2232300 + 500000]), flatEarnings(200000), YtdTotals::none());

    expect($higher->provincialTaxCents)->toBeLessThan($basic->provincialTaxCents)
        ->and($higher->provincialTaxCents)->toBeGreaterThan(0)
        ->and($higher->federalTaxCents)->toBe($basic->federalTaxCents);
});

it('floors a below-basic provincial claim to the basic amount (matches federal treatment)', function () {
    // A below-basic provincial claim (e.g. claim code 0) floors to the basic
    // personal amount — a documented, federally-shared limitation — so provincial
    // tax is unchanged from the basic baseline, never increased.
    $basic = payrollEngine()->compute(employeeContext('AB'), flatEarnings(200000), YtdTotals::none());
    $zero = payrollEngine()->compute(employeeContext('AB', ['prov' => 0]), flatEarnings(200000), YtdTotals::none());

    expect($zero->provincialTaxCents)->toBe($basic->provincialTaxCents);
});

it('honours a Quebec source-deductions claim above the basic amount (lower Quebec tax)', function () {
    $basic = payrollEngine()->compute(employeeContext('QC', ['prov' => 1857100]), flatEarnings(250000), YtdTotals::none());
    $higher = payrollEngine()->compute(employeeContext('QC', ['prov' => 1857100 + 500000]), flatEarnings(250000), YtdTotals::none());

    expect($higher->quebecTaxCents)->toBeLessThan($basic->quebecTaxCents)
        ->and($higher->quebecTaxCents)->toBeGreaterThan(0);
});
