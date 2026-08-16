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

function qcEngine(): PayrollDeductionEngine
{
    return new PayrollDeductionEngine(
        new PayrollConstantsRepository,
        new CppCalculator,
        new EiCalculator,
        new IncomeTaxCalculator,
        new QpipCalculator,
    );
}

function qcContext(array $overrides = []): EmployeePayrollContext
{
    return new EmployeePayrollContext(
        province: 'QC',
        payPeriodsPerYear: $overrides['periods'] ?? 26,
        payDate: CarbonImmutable::parse('2025-06-15'),
        federalClaimCents: $overrides['fed'] ?? 1612900,
        provincialClaimCents: $overrides['prov'] ?? 1857100, // Quebec BPA
        cppExempt: $overrides['cppExempt'] ?? false,
        eiExempt: $overrides['eiExempt'] ?? false,
        qpipExempt: $overrides['qpipExempt'] ?? false,
    );
}

function qcEarnings(int $cents): EarningsBreakdown
{
    return new EarningsBreakdown($cents, $cents, $cents, $cents);
}

it('computes QPP at the Quebec 6.40% rate into the QPP fields, not CPP', function () {
    $r = qcEngine()->compute(qcContext(), qcEarnings(200000), YtdTotals::none());

    // (200000 − 350000/26) × 6.40% = 11938 cents.
    expect($r->qppEmployeeCents)->toBe(11938)
        ->and($r->qppEmployerCents)->toBe(11938)
        ->and($r->cppEmployeeCents)->toBe(0)
        ->and($r->cpp2EmployeeCents)->toBe(0);
});

it('caps QPP at the Quebec annual maximum and adds QPP2', function () {
    $r = qcEngine()->compute(qcContext(), qcEarnings(10000000), YtdTotals::none());

    expect($r->qppEmployeeCents)->toBe(433920)   // 2025 max QPP base
        ->and($r->qpp2EmployeeCents)->toBe(39600); // 2025 max QPP2
});

it('uses the reduced Quebec EI rate (1.31%)', function () {
    $r = qcEngine()->compute(qcContext(), qcEarnings(200000), YtdTotals::none());

    expect($r->eiEmployeeCents)->toBe(2620)   // 200000 × 1.31%
        ->and($r->eiEmployerCents)->toBe(3668); // 2620 × 1.4
});

it('computes QPIP with distinct employee and employer rates', function () {
    $r = qcEngine()->compute(qcContext(), qcEarnings(200000), YtdTotals::none());

    expect($r->qpipEmployeeCents)->toBe(988)   // 200000 × 0.494%
        ->and($r->qpipEmployerCents)->toBe(1384) // 200000 × 0.692%
        ->and($r->qpipInsurableUsedCents)->toBe(200000);
});

it('caps QPIP at its own $98,000 insurable ceiling (not the EI MIE)', function () {
    // YTD insurable already at the $98,000 cap → no further QPIP.
    $r = qcEngine()->compute(qcContext(), qcEarnings(200000), new YtdTotals(qpipInsurableCents: 9800000, qpipEmployeeCents: 48412));

    expect($r->qpipEmployeeCents)->toBe(0);
});

it('abates federal tax by 16.5% and routes Quebec tax to quebecTaxCents', function () {
    $r = qcEngine()->compute(qcContext(), qcEarnings(200000), YtdTotals::none());

    // Federal: T4127 formula × 0.835 abatement. Quebec: TP-1015.TI-V formula —
    // credits ONLY the personal amount at 14% (NOT QPP/QPIP/EI). The harness
    // catches any arithmetic drift to the cent.
    expect($r->federalTaxCents)->toBe(14624)
        ->and($r->provincialTaxCents)->toBe(0)
        ->and($r->quebecTaxCents)->toBe(16975);
});

it('honours QPIP exemption', function () {
    $r = qcEngine()->compute(qcContext(['qpipExempt' => true]), qcEarnings(200000), YtdTotals::none());

    expect($r->qpipEmployeeCents)->toBe(0)->and($r->qpipEmployerCents)->toBe(0);
});

it('net pay nets QPP + QPIP + EI + federal + Quebec tax', function () {
    $r = qcEngine()->compute(qcContext(), qcEarnings(200000), YtdTotals::none());

    $expectedNet = 200000
        - $r->qppEmployeeCents - $r->qpipEmployeeCents - $r->eiEmployeeCents
        - $r->federalTaxCents - $r->quebecTaxCents;

    expect($r->netPayCents())->toBe($expectedNet);
});
