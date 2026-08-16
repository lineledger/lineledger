<?php

use App\Services\Payroll\Calculators\CppCalculator;
use App\Services\Payroll\Calculators\EiCalculator;
use App\Services\Payroll\Calculators\IncomeTaxCalculator;
use App\Services\Payroll\Calculators\QpipCalculator;
use App\Services\Payroll\Data\EarningsBreakdown;
use App\Services\Payroll\Data\EmployeePayrollContext;
use App\Services\Payroll\Data\YtdTotals;
use App\Services\Payroll\PayrollDeductionEngine;
use App\Support\Payroll\Constants\FederalConstants;
use App\Support\Payroll\Constants\PayrollConstantsRepository;
use App\Support\Payroll\Constants\ProvincialConstants;
use Carbon\CarbonImmutable;

// ── Federal: the resolver picks the latest table effective on or before the pay date.

it('resolves the federal table effective on the pay date', function (string $payDate, string $lowestRate, int $ympe, string $eiRate) {
    $federal = FederalConstants::for($payDate);

    expect($federal['tax']['lowest_rate'])->toBe($lowestRate)
        ->and($federal['cpp']['max_pensionable_cents'])->toBe($ympe)
        ->and($federal['ei']['rate'])->toBe($eiRate);
})->with([
    'Jan 2025 (15%)' => ['2025-03-15', '0.15', 7130000, '0.0164'],
    'Jun 2025 (still 15%)' => ['2025-06-30', '0.15', 7130000, '0.0164'],
    'Jul 2025 (14% cut)' => ['2025-07-01', '0.14', 7130000, '0.0164'],
    'Jan 2026 (indexed)' => ['2026-01-15', '0.14', 7460000, '0.0163'],
    'Jul 2026 (no fed change)' => ['2026-08-01', '0.14', 7460000, '0.0163'],
]);

it('returns no federal table before the earliest loaded period', function () {
    expect(FederalConstants::for('2024-12-31'))->toBeNull();
});

it('cuts the federal lowest bracket to 14% from July 1 2025', function () {
    expect(FederalConstants::for('2025-06-30')['tax']['brackets'][0])->toBe([5737500, '0.15'])
        ->and(FederalConstants::for('2025-07-01')['tax']['brackets'][0])->toBe([5737500, '0.14']);
});

it('loads all four federal periods', function () {
    expect(FederalConstants::loadedPeriods())
        ->toBe(['2025-01-01', '2025-07-01', '2026-01-01', '2026-07-01']);
});

// ── Provinces: the mid-year (prorated) changes the July editions introduced.

it('applies Alberta\'s prorated 8% bracket across 2025-2026', function () {
    expect(ProvincialConstants::for('AB', '2025-06-30')['brackets'][0])->toBe([15123400, '0.10'])
        ->and(ProvincialConstants::for('AB', '2025-07-01')['brackets'][0])->toBe([6000000, '0.06'])
        ->and(ProvincialConstants::for('AB', '2026-02-01')['brackets'][0])->toBe([6120000, '0.08']);
});

it('freezes the Manitoba BPAMB from July 2025', function () {
    expect(ProvincialConstants::for('MB', '2025-06-30')['bpa_cents'])->toBe(1596900) // $15,969 indexed
        ->and(ProvincialConstants::for('MB', '2025-07-01')['bpa_cents'])->toBe(1559100) // prorated $15,591
        ->and(ProvincialConstants::for('MB', '2026-01-01')['bpa_cents'])->toBe(1578000); // frozen $15,780
});

it('raises Newfoundland\'s BPA to a prorated $15,000 in July 2026', function () {
    expect(ProvincialConstants::for('NL', '2026-01-01')['bpa_cents'])->toBe(1118800) // $11,188
        ->and(ProvincialConstants::for('NL', '2026-07-01')['bpa_cents'])->toBe(1500000); // prorated $15,000
});

it('adds the PEI over-$200k bracket only from July 2026', function () {
    $jan = ProvincialConstants::for('PE', '2026-01-01')['brackets'];
    $jul = ProvincialConstants::for('PE', '2026-07-01')['brackets'];

    expect($jan)->toHaveCount(5)
        ->and($jul)->toHaveCount(6)
        ->and($jul[4])->toBe([20000000, '0.19'])
        ->and($jul[5])->toBe([null, '0.21']);
});

it('raises the BC lowest rate to a prorated 6.14% in July 2026', function () {
    expect(ProvincialConstants::for('BC', '2026-01-01')['brackets'][0][1])->toBe('0.0506')
        ->and(ProvincialConstants::for('BC', '2026-07-01')['brackets'][0][1])->toBe('0.0614');
});

it('carries the BC factor-S tax reduction for 2026 (indexed $575 → prorated $805)', function () {
    expect(ProvincialConstants::for('BC', '2026-01-01')['tax_reduction']['base_cents'])->toBe(57500)    // $575 indexed Jan–Jun
        ->and(ProvincialConstants::for('BC', '2026-07-01')['tax_reduction']['base_cents'])->toBe(80500) // $805 prorated Jul–Dec
        ->and(ProvincialConstants::for('BC', '2026-07-01')['tax_reduction'])->toMatchArray([
            'threshold_cents' => 2557000, // $25,570
            'rate' => '0.0356',
            'ceiling_cents' => 4495200,   // $44,952
        ]);
});

// ── Quebec: QPP base-rate cut and QPIP changes for 2026 (from the T4127).

it('applies the 2026 Quebec QPP/QPIP/EI changes', function () {
    $q25 = ProvincialConstants::for('QC', '2025-06-15')['quebec'];
    $q26 = ProvincialConstants::for('QC', '2026-01-15')['quebec'];

    expect($q25['qpp']['rate'])->toBe('0.0640')
        ->and($q26['qpp']['rate'])->toBe('0.0630')      // base cut 5.40% → 5.30%
        ->and($q26['qpp']['base_rate'])->toBe('0.0530')
        ->and($q26['ei']['rate'])->toBe('0.0130')        // Quebec EI 1.31% → 1.30%
        ->and($q26['qpip']['employee_rate'])->toBe('0.00430') // QPIP cut
        ->and($q26['qpip']['max_insurable_cents'])->toBe(10300000); // ceiling $98k → $103k
});

it('applies the 2026 Quebec provincial income-tax figures (Revenu Québec TP-1015.G)', function () {
    $qc26 = ProvincialConstants::for('QC', '2026-01-15');

    expect($qc26['brackets'])->toBe([
        [5434500, '0.14'],   // up to $54,345
        [10868000, '0.19'],  // up to $108,680
        [13224500, '0.24'],  // up to $132,245
        [null, '0.2575'],
    ])
        ->and($qc26['bpa_cents'])->toBe(1895200)                            // $18,952
        ->and($qc26['quebec']['worker_deduction_max_cents'])->toBe(145000); // $1,450
});

it('matches the TP-1015.G 2026 QPP, QPP2 and QPIP maximums', function () {
    // Independently verified against Revenu Québec TP-1015.G "Guide for Employers"
    // (2026-01), pp.9–11.
    $q = ProvincialConstants::for('QC', '2026-07-15')['quebec'];

    expect($q['qpp']['max_pensionable_cents'])->toBe(7460000)     // YMPE $74,600
        ->and($q['qpp']['basic_exemption_cents'])->toBe(350000)   // $3,500
        ->and($q['qpp']['rate'])->toBe('0.0630')                  // 5.30% base + 1%
        ->and($q['qpp']['base_rate'])->toBe('0.0530')
        ->and($q['qpp']['max_contribution_cents'])->toBe(447930)  // $4,479.30
        ->and($q['qpp2']['upper_cents'])->toBe(8500000)           // YAMPE $85,000
        ->and($q['qpp2']['rate'])->toBe('0.04')
        ->and($q['qpp2']['max_contribution_cents'])->toBe(41600)  // $416.00
        ->and($q['qpip']['max_insurable_cents'])->toBe(10300000)  // MIE $103,000
        ->and($q['qpip']['employee_rate'])->toBe('0.00430')       // max $442.90
        ->and($q['qpip']['employer_rate'])->toBe('0.00602')
        ->and($q['qpip']['max_employee_premium_cents'])->toBe(44290)
        ->and($q['worker_deduction_rate'])->toBe('0.06')          // 6% of eligible work income
        ->and($q['abatement_rate'])->toBe('0.165');               // federal Quebec abatement
});

// ── Behaviour: the July 2025 rate cut lowers withholding end to end.

it('withholds less federal tax after the July 1 2025 rate cut', function () {
    $engine = new PayrollDeductionEngine(
        new PayrollConstantsRepository,
        new CppCalculator,
        new EiCalculator,
        new IncomeTaxCalculator,
        new QpipCalculator,
    );

    $earnings = new EarningsBreakdown(300000, 300000, 300000, 300000); // $3,000 biweekly

    $before = $engine->compute(
        new EmployeePayrollContext('ON', 26, CarbonImmutable::parse('2025-06-15'), 1612900, 1274700),
        $earnings,
        YtdTotals::none(),
    );
    $after = $engine->compute(
        new EmployeePayrollContext('ON', 26, CarbonImmutable::parse('2025-07-15'), 1612900, 1274700),
        $earnings,
        YtdTotals::none(),
    );

    expect($after->federalTaxCents)->toBeLessThan($before->federalTaxCents);
});

// ── Upcoming (T4127 124th edition, Jan 1 2027) — announced but not yet loadable.

it('will cut the base CPP rate to 4.75% on Jan 1 2027', function () {
    // The 123rd edition announces base CPP 4.95% → 4.75% (combined 9.90% → 9.50%)
    // effective Jan 1 2027. Deferred until the 124th edition publishes the 2027
    // YMPE/YAMPE needed to derive max_contribution_cents (see FederalConstants
    // TODO(2027)). Un-skip once the '2027-01-01' period is added.
    expect(FederalConstants::for('2027-01-15')['cpp']['base_rate'])->toBe('0.0475');
})->skip('Awaiting T4127 124th edition (2027 CPP ceilings) — see FederalConstants TODO(2027).');
