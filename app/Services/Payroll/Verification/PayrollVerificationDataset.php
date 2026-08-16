<?php

namespace App\Services\Payroll\Verification;

use App\Enums\PayFrequency;
use App\Services\Payroll\Data\YtdTotals;
use App\Services\Proof\ProofScenario;
use App\Support\Money;

/**
 * The curated reference matrix the payroll engine is verified against — the
 * payroll analog of the accounting {@see ProofScenario}s.
 *
 * Structure:
 *  - A full 12-province × 4-frequency grid at a representative mid income. CPP
 *    and EI are exact (their formulas are simple and unambiguous, so they double
 *    as PDOC references); income tax is left null ('awaiting') — a complete
 *    PDOC to-do list. Paste each CRA Payroll Deductions Online Calculator figure
 *    to lock it.
 *  - A Quebec 4-frequency grid: QPP/Quebec-EI/QPIP exact, Quebec income tax
 *    awaiting Revenu Québec WebRAS (the Quebec PDOC analog).
 *  - Income-tax anchors: a few cases whose income tax is hand-derived from the
 *    T4127 formula (source 'formula').
 *  - Edge cases: the annual CPP/CPP2/EI maximums.
 *
 * Federal basic personal amount (2025) = $16,129. Provincial claims use each
 * province's basic personal amount. All figures are 2025 best-effort pending
 * official T4127 verification.
 */
class PayrollVerificationDataset
{
    private const FED_BPA = 1612900;

    /** Quebec 2025 source-deductions personal amount (TP-1015.3), cents. */
    private const QUEBEC_BPA = 1857100;

    /** Province → 2025 basic personal amount (cents). Quebec uses {@see QUEBEC_BPA}. */
    private const PROVINCE_BPA = [
        'AB' => 2232300, 'BC' => 1293200, 'MB' => 1596900, 'SK' => 1899100,
        'NB' => 1339600, 'NL' => 1106700, 'NS' => 848100, 'ON' => 1274700,
        'PE' => 1425000, 'NT' => 1784200, 'NU' => 1927400, 'YT' => 1612900,
    ];

    private const PROVINCE_NAMES = [
        'AB' => 'Alberta', 'BC' => 'British Columbia', 'MB' => 'Manitoba', 'SK' => 'Saskatchewan',
        'NB' => 'New Brunswick', 'NL' => 'Newfoundland and Labrador', 'NS' => 'Nova Scotia', 'ON' => 'Ontario',
        'PE' => 'Prince Edward Island', 'NT' => 'Northwest Territories', 'NU' => 'Nunavut', 'YT' => 'Yukon',
    ];

    /**
     * Per-frequency grid point: the representative per-period gross plus the
     * exact employee CPP and EI it produces (province-independent; income ≈ $60–65k/yr).
     *
     * @var array<string, array{gross: int, cpp: int, ei: int}>
     */
    private const GRID = [
        'weekly' => ['gross' => 125000, 'cpp' => 7037, 'ei' => 2050],       // (125000 − 350000/52) × 5.95%
        'biweekly' => ['gross' => 250000, 'cpp' => 14074, 'ei' => 4100],    // (250000 − 350000/26) × 5.95%
        'semi_monthly' => ['gross' => 250000, 'cpp' => 14007, 'ei' => 4100], // (250000 − 350000/24) × 5.95%
        'monthly' => ['gross' => 500000, 'cpp' => 28015, 'ei' => 8200],     // (500000 − 350000/12) × 5.95%
    ];

    /**
     * Quebec per-frequency grid point: the representative per-period gross plus the
     * exact employee QPP (6.40%), Quebec EI (1.31%) and QPIP (0.494%). Quebec
     * income tax is left awaiting (Revenu Québec WebRAS) — see {@see quebecGrid()}.
     *
     * @var array<string, array{gross: int, qpp: int, ei: int, qpip: int}>
     */
    private const QUEBEC_GRID = [
        'weekly' => ['gross' => 125000, 'qpp' => 7569, 'ei' => 1638, 'qpip' => 618],        // (125000 − 350000/52) × 6.40%
        'biweekly' => ['gross' => 250000, 'qpp' => 15138, 'ei' => 3275, 'qpip' => 1235],    // (250000 − 350000/26) × 6.40%
        'semi_monthly' => ['gross' => 250000, 'qpp' => 15067, 'ei' => 3275, 'qpip' => 1235], // (250000 − 350000/24) × 6.40%
        'monthly' => ['gross' => 500000, 'qpp' => 30133, 'ei' => 6550, 'qpip' => 2470],     // (500000 − 350000/12) × 6.40%
    ];

    // ── 2026 (T4127 122nd/123rd editions) ────────────────────────────────────
    // The 2026 mirror of the constants above, so the 2026-07-01 tables are
    // exercised (every 2026 case sets payDate '2026-07-15'). CPP is unchanged
    // (5.95% on YMPE $74,600, $3,500 exemption); EI drops 1.64% → 1.63%; QPP is
    // 6.30%, Quebec EI 1.30%, QPIP employee 0.430%. Provincial BPAs are the 2026
    // amounts (NL and PE are the prorated Jul–Dec figures).

    /** Federal basic personal amount (2026), cents — max BPAF $16,452. */
    private const FED_BPA_2026 = 1645200;

    /** Quebec 2026 source-deductions personal amount (TP-1015.3), cents. */
    private const QUEBEC_BPA_2026 = 1895200;

    /** Province → 2026 basic personal amount (cents); NL/PE are prorated Jul–Dec. */
    private const PROVINCE_BPA_2026 = [
        'AB' => 2276900, 'BC' => 1321600, 'MB' => 1578000, 'SK' => 2038100,
        'NB' => 1366400, 'NL' => 1500000, 'NS' => 1193200, 'ON' => 1298900,
        'PE' => 1500000, 'NT' => 1819800, 'NU' => 1965900, 'YT' => 1645200,
    ];

    /**
     * 2026 per-frequency grid point: gross plus exact employee CPP (5.95%) and EI
     * (1.63%). Province-independent (CPP/EI don't depend on the TD1 claim).
     *
     * @var array<string, array{gross: int, cpp: int, ei: int}>
     */
    private const GRID_2026 = [
        'weekly' => ['gross' => 125000, 'cpp' => 7037, 'ei' => 2038],       // (125000 − 350000/52) × 5.95%; 125000 × 1.63%
        'biweekly' => ['gross' => 250000, 'cpp' => 14074, 'ei' => 4075],    // (250000 − 350000/26) × 5.95%
        'semi_monthly' => ['gross' => 250000, 'cpp' => 14007, 'ei' => 4075], // (250000 − 350000/24) × 5.95%
        'monthly' => ['gross' => 500000, 'cpp' => 28015, 'ei' => 8150],     // (500000 − 350000/12) × 5.95%
    ];

    /**
     * 2026 Quebec per-frequency grid point: gross plus exact QPP (6.30%), Quebec EI
     * (1.30%) and QPIP employee (0.430%).
     *
     * @var array<string, array{gross: int, qpp: int, ei: int, qpip: int}>
     */
    private const QUEBEC_GRID_2026 = [
        'weekly' => ['gross' => 125000, 'qpp' => 7451, 'ei' => 1625, 'qpip' => 538],        // (125000 − 350000/52) × 6.30%
        'biweekly' => ['gross' => 250000, 'qpp' => 14902, 'ei' => 3250, 'qpip' => 1075],    // (250000 − 350000/26) × 6.30%
        'semi_monthly' => ['gross' => 250000, 'qpp' => 14831, 'ei' => 3250, 'qpip' => 1075], // (250000 − 350000/24) × 6.30%
        'monthly' => ['gross' => 500000, 'qpp' => 29663, 'ei' => 6500, 'qpip' => 2150],     // (500000 − 350000/12) × 6.30%
    ];

    /**
     * @return list<PayrollCheck>
     */
    public static function checks(): array
    {
        return [
            ...self::grid(),
            ...self::quebecGrid(),
            ...self::taxAnchors(),
            ...self::edgeCases(),
            ...self::grid2026(),
            ...self::quebecGrid2026(),
            ...self::taxAnchors2026(),
        ];
    }

    /**
     * The Quebec frequency grid: QPP/Quebec-EI/QPIP verified to the cent (their
     * formulas are unambiguous), Quebec income tax awaiting WebRAS confirmation.
     *
     * @return list<PayrollCheck>
     */
    private static function quebecGrid(): array
    {
        $checks = [];

        foreach (self::QUEBEC_GRID as $frequencyValue => $point) {
            $frequency = PayFrequency::from($frequencyValue);
            $gross = Money::fromCents($point['gross'])->format();

            $checks[] = new PayrollCheck(
                id: 'qc-'.$frequencyValue,
                label: 'Quebec · '.$frequency->label().' · '.$gross.' gross',
                province: 'QC',
                frequency: $frequency,
                grossPerPeriodCents: $point['gross'],
                federalClaimCents: self::FED_BPA,
                provincialClaimCents: self::QUEBEC_BPA,
                ytd: YtdTotals::none(),
                expectedCppEmployeeCents: 0,        // Quebec has no CPP
                expectedCpp2EmployeeCents: 0,
                expectedEiEmployeeCents: $point['ei'],
                expectedFederalTaxCents: null,      // abated federal — awaiting WebRAS
                expectedProvincialTaxCents: 0,      // Quebec tax is its own component
                source: 'formula',
                expectedQppEmployeeCents: $point['qpp'],
                expectedQpipEmployeeCents: $point['qpip'],
                expectedQuebecTaxCents: null,       // awaiting Revenu Québec WebRAS
            );
        }

        return $checks;
    }

    /**
     * The full province × frequency grid: CPP/EI verified, income tax awaiting.
     *
     * @return list<PayrollCheck>
     */
    private static function grid(): array
    {
        $checks = [];

        foreach (self::PROVINCE_BPA as $province => $bpa) {
            foreach (self::GRID as $frequencyValue => $point) {
                $frequency = PayFrequency::from($frequencyValue);
                $gross = Money::fromCents($point['gross'])->format();

                $checks[] = new PayrollCheck(
                    id: mb_strtolower($province).'-'.$frequencyValue,
                    label: self::PROVINCE_NAMES[$province].' · '.$frequency->label().' · '.$gross.' gross',
                    province: $province,
                    frequency: $frequency,
                    grossPerPeriodCents: $point['gross'],
                    federalClaimCents: self::FED_BPA,
                    provincialClaimCents: $bpa,
                    ytd: YtdTotals::none(),
                    expectedCppEmployeeCents: $point['cpp'],
                    expectedCpp2EmployeeCents: 0,
                    expectedEiEmployeeCents: $point['ei'],
                    expectedFederalTaxCents: null,   // awaiting CRA PDOC reference
                    expectedProvincialTaxCents: null,
                    source: 'awaiting',
                );
            }
        }

        return $checks;
    }

    /**
     * Cases whose income tax is hand-derived from the T4127 formula.
     *
     * @return list<PayrollCheck>
     */
    private static function taxAnchors(): array
    {
        return [
            new PayrollCheck(
                id: 'ab-biweekly-2000-tax',
                label: 'Alberta · biweekly · $2,000 gross (income tax anchor)',
                province: 'AB',
                frequency: PayFrequency::Biweekly,
                grossPerPeriodCents: 200000,
                federalClaimCents: self::FED_BPA,
                provincialClaimCents: 2232300,
                ytd: YtdTotals::none(),
                expectedCppEmployeeCents: 11099,      // (200000 − 350000/26) × 5.95%
                expectedCpp2EmployeeCents: 0,
                expectedEiEmployeeCents: 3280,        // 200000 × 1.64%
                expectedFederalTaxCents: 17689,       // formula (T4127 Option 1)
                expectedProvincialTaxCents: 9976,     // formula
                source: 'formula',
            ),
            new PayrollCheck(
                id: 'on-biweekly-2000-tax',
                label: 'Ontario · biweekly · $2,000 gross (income tax anchor, incl. health premium)',
                province: 'ON',
                frequency: PayFrequency::Biweekly,
                grossPerPeriodCents: 200000,
                federalClaimCents: self::FED_BPA,
                provincialClaimCents: 1274700,
                ytd: YtdTotals::none(),
                expectedCppEmployeeCents: 11099,
                expectedCpp2EmployeeCents: 0,
                expectedEiEmployeeCents: 3280,
                expectedFederalTaxCents: 17689,
                expectedProvincialTaxCents: 9206,     // formula (Ontario tax + OHP)
                source: 'formula',
            ),
            new PayrollCheck(
                id: 'ab-lowincome-zero-tax',
                label: 'Alberta · weekly · $200 gross (below both BPAs → no tax)',
                province: 'AB',
                frequency: PayFrequency::Weekly,
                grossPerPeriodCents: 20000,
                federalClaimCents: self::FED_BPA,
                provincialClaimCents: 2232300,
                ytd: YtdTotals::none(),
                expectedCppEmployeeCents: 790,        // (20000 − 350000/52) × 5.95%
                expectedCpp2EmployeeCents: 0,
                expectedEiEmployeeCents: 328,         // 20000 × 1.64%
                expectedFederalTaxCents: 0,           // annual ≈ $10.4k < $16,129 BPA
                expectedProvincialTaxCents: 0,        // annual ≈ $10.4k < $22,323 BPA
                source: 'formula',
            ),
        ];
    }

    /**
     * The annual CPP / CPP2 / EI maximum edge cases.
     *
     * @return list<PayrollCheck>
     */
    private static function edgeCases(): array
    {
        return [
            new PayrollCheck(
                id: 'cpp-cpp2-annual-maximums',
                label: 'CPP and CPP2 both stop once their annual maximums are reached',
                province: 'AB',
                frequency: PayFrequency::Biweekly,
                grossPerPeriodCents: 200000,
                federalClaimCents: self::FED_BPA,
                provincialClaimCents: 2232300,
                // YTD pensionable at YAMPE with both base CPP and CPP2 already maxed.
                ytd: new YtdTotals(pensionableCents: 8120000, cppEmployeeCents: 403410, cpp2EmployeeCents: 39600),
                expectedCppEmployeeCents: 0,          // 2025 max $4,034.10
                expectedCpp2EmployeeCents: 0,         // 2025 CPP2 max $396.00
                expectedEiEmployeeCents: 3280,
                expectedFederalTaxCents: null,
                expectedProvincialTaxCents: null,
                source: 'pdoc',
            ),
            new PayrollCheck(
                id: 'cpp2-second-band',
                label: 'CPP2 applies in the (YMPE, YAMPE] band',
                province: 'AB',
                frequency: PayFrequency::Biweekly,
                grossPerPeriodCents: 200000,
                federalClaimCents: self::FED_BPA,
                provincialClaimCents: 2232300,
                ytd: new YtdTotals(pensionableCents: 7130000, cppEmployeeCents: 403410),
                expectedCppEmployeeCents: 0,
                expectedCpp2EmployeeCents: 8000,      // 200000 × 4%
                expectedEiEmployeeCents: 3280,
                expectedFederalTaxCents: null,
                expectedProvincialTaxCents: null,
                source: 'pdoc',
            ),
            new PayrollCheck(
                id: 'ei-annual-maximum',
                label: 'EI stops once the annual maximum premium is reached',
                province: 'AB',
                frequency: PayFrequency::Biweekly,
                grossPerPeriodCents: 200000,
                federalClaimCents: self::FED_BPA,
                provincialClaimCents: 2232300,
                ytd: new YtdTotals(eiEmployeeCents: 107748),
                expectedCppEmployeeCents: 11099,
                expectedCpp2EmployeeCents: 0,
                expectedEiEmployeeCents: 0,            // 2025 max $1,077.48
                expectedFederalTaxCents: null,
                expectedProvincialTaxCents: null,
                source: 'pdoc',
            ),
        ];
    }

    /**
     * The 2026 province × frequency grid: CPP/EI verified at 2026 rates, income
     * tax awaiting a CRA PDOC reference. payDate '2026-07-15' selects the
     * 2026-07-01 tables.
     *
     * @return list<PayrollCheck>
     */
    private static function grid2026(): array
    {
        $checks = [];

        foreach (self::PROVINCE_BPA_2026 as $province => $bpa) {
            foreach (self::GRID_2026 as $frequencyValue => $point) {
                $frequency = PayFrequency::from($frequencyValue);
                $gross = Money::fromCents($point['gross'])->format();

                $checks[] = new PayrollCheck(
                    id: mb_strtolower($province).'-'.$frequencyValue.'-2026',
                    label: self::PROVINCE_NAMES[$province].' · '.$frequency->label().' · '.$gross.' gross (2026)',
                    province: $province,
                    frequency: $frequency,
                    grossPerPeriodCents: $point['gross'],
                    federalClaimCents: self::FED_BPA_2026,
                    provincialClaimCents: $bpa,
                    ytd: YtdTotals::none(),
                    expectedCppEmployeeCents: $point['cpp'],
                    expectedCpp2EmployeeCents: 0,
                    expectedEiEmployeeCents: $point['ei'],
                    expectedFederalTaxCents: null,   // awaiting CRA PDOC reference
                    expectedProvincialTaxCents: null,
                    source: 'awaiting',
                    payDate: '2026-07-15',
                );
            }
        }

        return $checks;
    }

    /**
     * The 2026 Quebec frequency grid: QPP/Quebec-EI/QPIP verified to the cent at
     * 2026 rates, Quebec income tax awaiting Revenu Québec WebRAS.
     *
     * @return list<PayrollCheck>
     */
    private static function quebecGrid2026(): array
    {
        $checks = [];

        foreach (self::QUEBEC_GRID_2026 as $frequencyValue => $point) {
            $frequency = PayFrequency::from($frequencyValue);
            $gross = Money::fromCents($point['gross'])->format();

            $checks[] = new PayrollCheck(
                id: 'qc-'.$frequencyValue.'-2026',
                label: 'Quebec · '.$frequency->label().' · '.$gross.' gross (2026)',
                province: 'QC',
                frequency: $frequency,
                grossPerPeriodCents: $point['gross'],
                federalClaimCents: self::FED_BPA_2026,
                provincialClaimCents: self::QUEBEC_BPA_2026,
                ytd: YtdTotals::none(),
                expectedCppEmployeeCents: 0,        // Quebec has no CPP
                expectedCpp2EmployeeCents: 0,
                expectedEiEmployeeCents: $point['ei'],
                expectedFederalTaxCents: null,      // abated federal — awaiting WebRAS
                expectedProvincialTaxCents: 0,
                source: 'formula',
                payDate: '2026-07-15',
                expectedQppEmployeeCents: $point['qpp'],
                expectedQpipEmployeeCents: $point['qpip'],
                expectedQuebecTaxCents: null,       // awaiting Revenu Québec WebRAS
            );
        }

        return $checks;
    }

    /**
     * 2026 income-tax anchors. The BC cases are hand-derived from the T4127 123rd
     * edition — the prorated 6.14% lowest rate and the factor-S tax reduction. The
     * PE (>$200k) and NL (prorated $15,000 BPA) cases exercise the July-2026
     * changes but leave income tax awaiting a PDOC reference.
     *
     * @return list<PayrollCheck>
     */
    private static function taxAnchors2026(): array
    {
        return [
            new PayrollCheck(
                id: 'bc-semimonthly-2500-2026',
                label: 'British Columbia · semi-monthly · $2,500 gross (2026 prorated 6.14%)',
                province: 'BC',
                frequency: PayFrequency::SemiMonthly,
                grossPerPeriodCents: 250000,
                federalClaimCents: self::FED_BPA_2026,
                provincialClaimCents: 1321600,
                ytd: YtdTotals::none(),
                expectedCppEmployeeCents: 14007,
                expectedCpp2EmployeeCents: 0,
                expectedEiEmployeeCents: 4075,
                expectedFederalTaxCents: 22243,     // formula (T4127 Option 1)
                expectedProvincialTaxCents: 11448,  // 6.14% prorated; income above the factor-S ceiling
                source: 'formula',
                payDate: '2026-07-15',
            ),
            new PayrollCheck(
                id: 'bc-semimonthly-1100-factor-s-2026',
                label: 'British Columbia · semi-monthly · $1,100 gross (2026 factor-S reduction → $0 provincial)',
                province: 'BC',
                frequency: PayFrequency::SemiMonthly,
                grossPerPeriodCents: 110000,
                federalClaimCents: self::FED_BPA_2026,
                provincialClaimCents: 1321600,
                ytd: YtdTotals::none(),
                expectedCppEmployeeCents: 5677,
                expectedCpp2EmployeeCents: 0,
                expectedEiEmployeeCents: 1793,
                expectedFederalTaxCents: 3882,      // formula
                expectedProvincialTaxCents: 0,      // factor S ($805 prorated) fully offsets the BC tax
                source: 'formula',
                payDate: '2026-07-15',
            ),
            new PayrollCheck(
                id: 'pe-monthly-20000-2026',
                label: 'Prince Edward Island · monthly · $20,000 gross (2026 new >$200k 21% bracket)',
                province: 'PE',
                frequency: PayFrequency::Monthly,
                grossPerPeriodCents: 2000000,
                federalClaimCents: self::FED_BPA_2026,
                provincialClaimCents: 1500000,
                ytd: YtdTotals::none(),
                expectedCppEmployeeCents: 117265,   // (2000000 − 350000/12) × 5.95%, uncapped this period
                expectedCpp2EmployeeCents: 0,
                expectedEiEmployeeCents: 32600,     // 2000000 × 1.63%
                expectedFederalTaxCents: null,
                expectedProvincialTaxCents: null,   // PDOC: exercises the new PE >$200k prorated 21% bracket
                source: 'awaiting',
                payDate: '2026-07-15',
                notes: 'PDOC to-do: annualized income ~$237k lands in PE\'s new over-$200k bracket (prorated 21%).',
            ),
            new PayrollCheck(
                id: 'nl-biweekly-1500-2026',
                label: 'Newfoundland and Labrador · biweekly · $1,500 gross (2026 prorated $15,000 BPA)',
                province: 'NL',
                frequency: PayFrequency::Biweekly,
                grossPerPeriodCents: 150000,
                federalClaimCents: self::FED_BPA_2026,
                provincialClaimCents: 1500000,
                ytd: YtdTotals::none(),
                expectedCppEmployeeCents: 8124,     // (150000 − 350000/26) × 5.95%
                expectedCpp2EmployeeCents: 0,
                expectedEiEmployeeCents: 2445,      // 150000 × 1.63%
                expectedFederalTaxCents: null,
                expectedProvincialTaxCents: null,   // PDOC: exercises the prorated $15,000 NL BPA
                source: 'awaiting',
                payDate: '2026-07-15',
                notes: 'PDOC to-do: the prorated $15,000 NL basic personal amount raises the tax-free floor.',
            ),
        ];
    }
}
