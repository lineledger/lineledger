<?php

namespace App\Support\Payroll\Constants;

/**
 * Federal CPP / CPP2 / EI and income-tax constants from CRA's T4127 "Payroll
 * Deductions Formulas", keyed by the effective date of the table (CRA revises
 * Jan 1 and, when needed, Jul 1). All money is integer cents; rates are decimal
 * strings applied with bcmath.
 *
 * IMPORTANT — these figures must be verified against the official T4127 edition
 * for the effective date before production payroll. Adding a new period is a
 * data-only change: append one entry keyed by its effective date.
 *
 * Tax brackets are stored as [up_to_cents (null = top bracket), rate]. The CRA
 * constant "K" (bracket adjustment) is derived in code from the bracket edges,
 * so it can never drift from the rates.
 */
final class FederalConstants
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private const PERIODS = [
        // T4127 120th edition, effective Jan 1 2025.
        '2025-01-01' => [
            'cpp' => [
                'rate' => '0.0595',          // employee = employer (base 4.95% + first-additional 1.00%)
                'base_rate' => '0.0495',     // base portion that yields the income-tax credit
                'first_additional_rate' => '0.0100', // enhanced portion, deductible from income
                'basic_exemption_cents' => 350000,   // $3,500
                'max_pensionable_cents' => 7130000,  // YMPE $71,300
                'max_contribution_cents' => 403410,  // (71,300 − 3,500) × 5.95% = $4,034.10
            ],
            'cpp2' => [
                'rate' => '0.04',
                'lower_cents' => 7130000,    // YMPE $71,300
                'upper_cents' => 8120000,    // YAMPE $81,200
                'max_contribution_cents' => 39600, // (81,200 − 71,300) × 4% = $396.00
            ],
            'ei' => [
                'rate' => '0.0164',
                'max_insurable_cents' => 6570000, // $65,700
                'max_premium_cents' => 107748,    // $1,077.48
                'employer_factor' => '1.4',
            ],
            'tax' => [
                'brackets' => [
                    [5737500, '0.15'],   // up to $57,375
                    [11475000, '0.205'], // up to $114,750
                    [17788200, '0.26'],  // up to $177,882
                    [25341400, '0.29'],  // up to $253,414
                    [null, '0.33'],      // remainder
                ],
                'lowest_rate' => '0.15',
                'bpa_max_cents' => 1612900, // $16,129
                'bpa_min_cents' => 1453800, // $14,538
                'bpa_phaseout_low_cents' => 17788200,  // $177,882 (bottom of 29% bracket)
                'bpa_phaseout_high_cents' => 25341400, // $253,414 (bottom of 33% bracket)
                'canada_employment_max_cents' => 147100, // CEA $1,471
            ],
        ],
        // T4127 121st edition, effective Jul 1 2025: lowest federal rate cut
        // 15% → 14% (full-year 2025 blends to 14.5% on the T1 return; source
        // deductions use 14% from the first July payroll). CPP/CPP2/EI and the
        // BPA dollar amounts are unchanged mid-year — only the rate moves.
        '2025-07-01' => [
            'cpp' => [
                'rate' => '0.0595',
                'base_rate' => '0.0495',
                'first_additional_rate' => '0.0100',
                'basic_exemption_cents' => 350000,
                'max_pensionable_cents' => 7130000,
                'max_contribution_cents' => 403410,
            ],
            'cpp2' => [
                'rate' => '0.04',
                'lower_cents' => 7130000,
                'upper_cents' => 8120000,
                'max_contribution_cents' => 39600,
            ],
            'ei' => [
                'rate' => '0.0164',
                'max_insurable_cents' => 6570000,
                'max_premium_cents' => 107748,
                'employer_factor' => '1.4',
            ],
            'tax' => [
                'brackets' => [
                    [5737500, '0.14'],   // up to $57,375 — rate cut to 14%
                    [11475000, '0.205'], // up to $114,750
                    [17788200, '0.26'],  // up to $177,882
                    [25341400, '0.29'],  // up to $253,414
                    [null, '0.33'],      // remainder
                ],
                'lowest_rate' => '0.14', // credits (K1/K2/K4) follow the lowest rate
                'bpa_max_cents' => 1612900, // $16,129
                'bpa_min_cents' => 1453800, // $14,538
                'bpa_phaseout_low_cents' => 17788200,  // $177,882
                'bpa_phaseout_high_cents' => 25341400, // $253,414
                'canada_employment_max_cents' => 147100, // CEA $1,471
            ],
        ],
        // T4127 122nd edition, effective Jan 1 2026: brackets/BPA/CEA indexed by
        // 2.0%; lowest rate 14% (full year). CPP YMPE → $74,600, YAMPE → $85,000;
        // EI MIE → $68,900 at 1.63%.
        '2026-01-01' => [
            'cpp' => [
                'rate' => '0.0595',
                'base_rate' => '0.0495',
                'first_additional_rate' => '0.0100',
                'basic_exemption_cents' => 350000,   // $3,500 (unchanged)
                'max_pensionable_cents' => 7460000,  // YMPE $74,600
                'max_contribution_cents' => 423045,  // (74,600 − 3,500) × 5.95% = $4,230.45
            ],
            'cpp2' => [
                'rate' => '0.04',
                'lower_cents' => 7460000,    // YMPE $74,600
                'upper_cents' => 8500000,    // YAMPE $85,000
                'max_contribution_cents' => 41600, // (85,000 − 74,600) × 4% = $416.00
            ],
            'ei' => [
                'rate' => '0.0163',
                'max_insurable_cents' => 6890000, // $68,900
                'max_premium_cents' => 112307,    // $1,123.07
                'employer_factor' => '1.4',
            ],
            'tax' => [
                'brackets' => [
                    [5852300, '0.14'],   // up to $58,523
                    [11704500, '0.205'], // up to $117,045
                    [18144000, '0.26'],  // up to $181,440
                    [25848200, '0.29'],  // up to $258,482
                    [null, '0.33'],      // remainder
                ],
                'lowest_rate' => '0.14',
                'bpa_max_cents' => 1645200, // $16,452
                'bpa_min_cents' => 1482900, // $14,829
                'bpa_phaseout_low_cents' => 18144000,  // $181,440 (bottom of 29% bracket)
                'bpa_phaseout_high_cents' => 25848200, // $258,482 (bottom of 33% bracket)
                'canada_employment_max_cents' => 150100, // CEA $1,501
            ],
        ],
        // T4127 123rd edition, effective Jul 1 2026: no federal income-tax, CPP or
        // EI change (the mid-year changes were provincial — BC/NL/PE). Duplicated
        // from 2026-01-01 for explicit per-period coverage.
        '2026-07-01' => [
            'cpp' => [
                'rate' => '0.0595',
                'base_rate' => '0.0495',
                'first_additional_rate' => '0.0100',
                'basic_exemption_cents' => 350000,
                'max_pensionable_cents' => 7460000,
                'max_contribution_cents' => 423045,
            ],
            'cpp2' => [
                'rate' => '0.04',
                'lower_cents' => 7460000,
                'upper_cents' => 8500000,
                'max_contribution_cents' => 41600,
            ],
            'ei' => [
                'rate' => '0.0163',
                'max_insurable_cents' => 6890000,
                'max_premium_cents' => 112307,
                'employer_factor' => '1.4',
            ],
            'tax' => [
                'brackets' => [
                    [5852300, '0.14'],   // up to $58,523
                    [11704500, '0.205'], // up to $117,045
                    [18144000, '0.26'],  // up to $181,440
                    [25848200, '0.29'],  // up to $258,482
                    [null, '0.33'],      // remainder
                ],
                'lowest_rate' => '0.14',
                'bpa_max_cents' => 1645200, // $16,452
                'bpa_min_cents' => 1482900, // $14,829
                'bpa_phaseout_low_cents' => 18144000,  // $181,440
                'bpa_phaseout_high_cents' => 25848200, // $258,482
                'canada_employment_max_cents' => 150100, // CEA $1,501
            ],
        ],
        // TODO(2027): the T4127 123rd edition (p.5) announces, effective Jan 1 2027,
        // a base-CPP rate cut — combined 9.90% → 9.50%, i.e. employee/employer
        // 4.95% → 4.75% ('cpp.rate' 0.0595 → 0.0575, 'cpp.base_rate' 0.0495 →
        // 0.0475; first/second additional rates unchanged). NOT loaded here on
        // purpose: 'max_contribution_cents' = (YMPE_2027 − 3500) × rate, and the
        // 2027 YMPE/YAMPE and indexed brackets/BPA/EI are not published until the
        // 124th edition (~Nov 2026). Loading a partial 2027 set would let
        // payroll:verify-constants pass while being factually wrong, so the engine
        // instead (correctly) refuses to compute for an unloaded 2027 pay date.
        // When the 124th edition lands: append a '2027-01-01' period with the new
        // CPP rates + the published 2027 ceilings, and add 2027 provincial tables
        // (incl. BC's 2027–2030 indexation pause) + 2027 verification cases.
    ];

    /**
     * The federal constants effective on the given date, or null if no table
     * covers it.
     *
     * @return array<string, mixed>|null
     */
    public static function for(string $payDate): ?array
    {
        $match = null;

        foreach (self::PERIODS as $effective => $values) {
            if ($effective <= $payDate) {
                $match = $values;
            }
        }

        return $match;
    }

    /**
     * @return array<int, string> Effective dates of every loaded table.
     */
    public static function loadedPeriods(): array
    {
        return array_keys(self::PERIODS);
    }
}
