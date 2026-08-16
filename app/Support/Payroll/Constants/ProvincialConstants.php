<?php

namespace App\Support\Payroll\Constants;

/**
 * Provincial income-tax constants from CRA's T4127 "Payroll Deductions Formulas"
 * (120th–123rd editions: Jan 1 2025, Jul 1 2025, Jan 1 2026, Jul 1 2026), keyed
 * by province then effective date. Quebec ('QC') runs a parallel system, so its
 * block also carries a nested 'quebec' bag with the QPP/QPP2/Quebec-EI/QPIP rates
 * and the federal abatement; {@see PayrollConstantSet} reads that bag for QC and
 * the plain federal/provincial keys for the rest of Canada.
 *
 * CRA revises Jan 1 and, when a budget lands mid-year, Jul 1. The Jul editions use
 * PRORATED first-half-offsetting amounts for that year (e.g. Alberta's new 8%
 * bracket bills at 6% for Jul–Dec 2025; Manitoba's frozen BPAMB bills at $15,591).
 * The resolver picks the latest table whose effective date ≤ the pay date.
 *
 * Brackets: [up_to_cents (null = top), rate]. Optional per-province keys:
 *   'surtax'         => [[threshold_tax_cents, marginal_rate], ...] (Ontario)
 *   'health_premium' => [[upper_income_cents, base_cents, rate, cap_cents], ...] (Ontario)
 *   'tax_reduction'  => ['base_cents', 'threshold_cents', 'rate', 'ceiling_cents']
 *                       — the low-income "tax reduction" (factor S), BC (and, when
 *                       modelled, Ontario). See IncomeTaxCalculator::taxReduction().
 *   'quebec'         => [...] Quebec-only bag (abatement, worker deduction, qpp,
 *                       qpp2, ei, qpip)
 *
 * Deliberately omitted (each OVER-withholds slightly, which the employee
 * reconciles at filing — a safe direction):
 *   - Ontario's factor S tax reduction (needs the after-surtax ordering and the
 *     dependant amount Y; BC's factor S IS applied via 'tax_reduction');
 *   - Alberta's Supplemental Tax Credit (factor K5P, for TD1 claims > ~$60k);
 *   - Manitoba's BPAMB phase-out over $200k–$400k income (the maximum is used).
 *
 * Quebec PROVINCIAL income tax (brackets / BPA / worker deduction) and the QPP /
 * QPP2 / QPIP parameters are published by Revenu Québec, NOT the CRA T4127. The
 * 2026 QC values are verified against TP-1015.G "Guide for Employers" (2026-01,
 * pp.9–11). The Quebec-reduced EI rate and the 16.5% federal abatement come from
 * the federal side (T4127). Quebec income-tax withholding still awaits a WebRAS
 * reference in the verification dataset (only the constants are confirmed here).
 */
final class ProvincialConstants
{
    /**
     * @var array<string, array<string, array<string, mixed>>>
     */
    private const PERIODS = [
        'AB' => [
            '2025-01-01' => [
                'brackets' => [
                    [15123400, '0.10'],  // up to $151,234
                    [18148100, '0.12'],  // up to $181,481
                    [24197400, '0.13'],  // up to $241,974
                    [36296100, '0.14'],  // up to $362,961
                    [null, '0.15'],
                ],
                'lowest_rate' => '0.10',
                'bpa_cents' => 2232300, // $22,323
            ],
            // Jul 1 2025: new 8% bracket on the first $60,000 (Budget 2025), billed
            // at a prorated 6% for Jul–Dec to offset the 10% used Jan–Jun.
            '2025-07-01' => [
                'brackets' => [
                    [6000000, '0.06'],   // up to $60,000 (prorated 6%)
                    [15123400, '0.10'],  // up to $151,234
                    [18148100, '0.12'],  // up to $181,481
                    [24197400, '0.13'],  // up to $241,974
                    [36296100, '0.14'],  // up to $362,961
                    [null, '0.15'],
                ],
                'lowest_rate' => '0.06',
                'bpa_cents' => 2232300, // $22,323
            ],
            // 2026: 8% bracket at its legislated rate; thresholds indexed.
            '2026-01-01' => [
                'brackets' => [
                    [6120000, '0.08'],   // up to $61,200
                    [15425900, '0.10'],  // up to $154,259
                    [18511100, '0.12'],  // up to $185,111
                    [24681300, '0.13'],  // up to $246,813
                    [37022000, '0.14'],  // up to $370,220
                    [null, '0.15'],
                ],
                'lowest_rate' => '0.08',
                'bpa_cents' => 2276900, // $22,769
            ],
            '2026-07-01' => [ // no change from 2026-01-01
                'brackets' => [
                    [6120000, '0.08'],
                    [15425900, '0.10'],
                    [18511100, '0.12'],
                    [24681300, '0.13'],
                    [37022000, '0.14'],
                    [null, '0.15'],
                ],
                'lowest_rate' => '0.08',
                'bpa_cents' => 2276900,
            ],
        ],
        'BC' => [
            '2025-01-01' => [
                'brackets' => [
                    [4927900, '0.0506'],  // up to $49,279
                    [9856000, '0.077'],   // up to $98,560
                    [11315800, '0.105'],  // up to $113,158
                    [13740700, '0.1229'], // up to $137,407
                    [18630600, '0.147'],  // up to $186,306
                    [25982900, '0.168'],  // up to $259,829
                    [null, '0.205'],
                ],
                'lowest_rate' => '0.0506',
                'bpa_cents' => 1293200, // $12,932
            ],
            '2025-07-01' => [ // no change from 2025-01-01
                'brackets' => [
                    [4927900, '0.0506'],
                    [9856000, '0.077'],
                    [11315800, '0.105'],
                    [13740700, '0.1229'],
                    [18630600, '0.147'],
                    [25982900, '0.168'],
                    [null, '0.205'],
                ],
                'lowest_rate' => '0.0506',
                'bpa_cents' => 1293200,
            ],
            '2026-01-01' => [
                'brackets' => [
                    [5036300, '0.0506'],  // up to $50,363
                    [10072800, '0.077'],  // up to $100,728
                    [11564800, '0.105'],  // up to $115,648
                    [14043000, '0.1229'], // up to $140,430
                    [19040500, '0.147'],  // up to $190,405
                    [26554500, '0.168'],  // up to $265,545
                    [null, '0.205'],
                ],
                'lowest_rate' => '0.0506',
                'bpa_cents' => 1321600, // $13,216
                // BC tax reduction (factor S): indexed to $575 for Jan–Jun 2026
                // before the Feb 2026 budget raised it (see 2026-07-01).
                'tax_reduction' => [
                    'base_cents' => 57500,        // S2 $575 (indexed Jan 1 2026)
                    'threshold_cents' => 2557000, // $25,570
                    'rate' => '0.0356',
                    'ceiling_cents' => 4495200,   // $44,952
                ],
            ],
            // Jul 1 2026: lowest rate raised 5.06% → 5.60% (Feb 2026 budget), billed
            // at a prorated 6.14% for Jul–Dec to offset the 5.06% used Jan–Jun.
            '2026-07-01' => [
                'brackets' => [
                    [5036300, '0.0614'],  // up to $50,363 (prorated 6.14%)
                    [10072800, '0.077'],
                    [11564800, '0.105'],
                    [14043000, '0.1229'],
                    [19040500, '0.147'],
                    [26554500, '0.168'],
                    [null, '0.205'],
                ],
                'lowest_rate' => '0.0614',
                'bpa_cents' => 1321600,
                // BC tax reduction (factor S): the annual amount rose $575 → $690;
                // since $575 was used Jan–Jun, a prorated $805 applies Jul–Dec so
                // the year averages to $690. Threshold/ceiling are 2026-annual.
                'tax_reduction' => [
                    'base_cents' => 80500,        // S2 $805 (prorated Jul–Dec 2026)
                    'threshold_cents' => 2557000, // $25,570
                    'rate' => '0.0356',
                    'ceiling_cents' => 4495200,   // $44,952
                ],
            ],
        ],
        'MB' => [
            '2025-01-01' => [
                'brackets' => [
                    [4756400, '0.108'],   // up to $47,564
                    [10120000, '0.1275'], // up to $101,200
                    [null, '0.174'],
                ],
                'lowest_rate' => '0.108',
                'bpa_cents' => 1596900, // max BPAMB $15,969 (indexed)
            ],
            // Jul 1 2025: BPAMB + thresholds frozen at 2024 levels (Mar 2025).
            // Prorated to offset the indexed amounts used Jan–Jun.
            '2025-07-01' => [
                'brackets' => [
                    [4651300, '0.108'],  // up to $46,513 (prorated)
                    [9879600, '0.1275'], // up to $98,796 (prorated)
                    [null, '0.174'],
                ],
                'lowest_rate' => '0.108',
                'bpa_cents' => 1559100, // prorated BPAMB $15,591
            ],
            '2026-01-01' => [
                'brackets' => [
                    [4700000, '0.108'],  // up to $47,000 (frozen)
                    [10000000, '0.1275'], // up to $100,000 (frozen)
                    [null, '0.174'],
                ],
                'lowest_rate' => '0.108',
                'bpa_cents' => 1578000, // max BPAMB $15,780 (frozen)
            ],
            '2026-07-01' => [ // no change from 2026-01-01
                'brackets' => [
                    [4700000, '0.108'],
                    [10000000, '0.1275'],
                    [null, '0.174'],
                ],
                'lowest_rate' => '0.108',
                'bpa_cents' => 1578000,
            ],
        ],
        'NB' => [
            '2025-01-01' => [
                'brackets' => [
                    [5130600, '0.094'],  // up to $51,306
                    [10261400, '0.14'],  // up to $102,614
                    [19006000, '0.16'],  // up to $190,060
                    [null, '0.195'],
                ],
                'lowest_rate' => '0.094',
                'bpa_cents' => 1339600, // $13,396
            ],
            '2025-07-01' => [ // no change from 2025-01-01
                'brackets' => [
                    [5130600, '0.094'],
                    [10261400, '0.14'],
                    [19006000, '0.16'],
                    [null, '0.195'],
                ],
                'lowest_rate' => '0.094',
                'bpa_cents' => 1339600,
            ],
            '2026-01-01' => [
                'brackets' => [
                    [5233300, '0.094'],  // up to $52,333
                    [10466600, '0.14'],  // up to $104,666
                    [19386100, '0.16'],  // up to $193,861
                    [null, '0.195'],
                ],
                'lowest_rate' => '0.094',
                'bpa_cents' => 1366400, // $13,664
            ],
            '2026-07-01' => [ // no change from 2026-01-01
                'brackets' => [
                    [5233300, '0.094'],
                    [10466600, '0.14'],
                    [19386100, '0.16'],
                    [null, '0.195'],
                ],
                'lowest_rate' => '0.094',
                'bpa_cents' => 1366400,
            ],
        ],
        'NL' => [
            '2025-01-01' => [
                'brackets' => [
                    [4419200, '0.087'],   // up to $44,192
                    [8838200, '0.145'],   // up to $88,382
                    [15779200, '0.158'],  // up to $157,792
                    [22091000, '0.178'],  // up to $220,910
                    [28221400, '0.198'],  // up to $282,214
                    [56442900, '0.208'],  // up to $564,429
                    [112885800, '0.213'], // up to $1,128,858
                    [null, '0.218'],
                ],
                'lowest_rate' => '0.087',
                'bpa_cents' => 1106700, // $11,067
            ],
            '2025-07-01' => [ // no change from 2025-01-01
                'brackets' => [
                    [4419200, '0.087'],
                    [8838200, '0.145'],
                    [15779200, '0.158'],
                    [22091000, '0.178'],
                    [28221400, '0.198'],
                    [56442900, '0.208'],
                    [112885800, '0.213'],
                    [null, '0.218'],
                ],
                'lowest_rate' => '0.087',
                'bpa_cents' => 1106700,
            ],
            '2026-01-01' => [
                'brackets' => [
                    [4467800, '0.087'],   // up to $44,678
                    [8935400, '0.145'],   // up to $89,354
                    [15952800, '0.158'],  // up to $159,528
                    [22334000, '0.178'],  // up to $223,340
                    [28531900, '0.198'],  // up to $285,319
                    [57063800, '0.208'],  // up to $570,638
                    [114127500, '0.213'], // up to $1,141,275
                    [null, '0.218'],
                ],
                'lowest_rate' => '0.087',
                'bpa_cents' => 1118800, // $11,188 (indexed)
            ],
            // Jul 1 2026: BPA raised to $13,094 annual (Apr 2026), billed at a
            // prorated $15,000 for Jul–Dec to offset the $11,188 used Jan–Jun.
            '2026-07-01' => [
                'brackets' => [
                    [4467800, '0.087'],
                    [8935400, '0.145'],
                    [15952800, '0.158'],
                    [22334000, '0.178'],
                    [28531900, '0.198'],
                    [57063800, '0.208'],
                    [114127500, '0.213'],
                    [null, '0.218'],
                ],
                'lowest_rate' => '0.087',
                'bpa_cents' => 1500000, // prorated $15,000
            ],
        ],
        'NS' => [
            // Nova Scotia set the BPANS to a flat amount for all incomes (Feb 2025),
            // replacing the old income-tested supplement.
            '2025-01-01' => [
                'brackets' => [
                    [3050700, '0.0879'], // up to $30,507
                    [6101500, '0.1495'], // up to $61,015
                    [9588300, '0.1667'], // up to $95,883
                    [15465000, '0.175'], // up to $154,650
                    [null, '0.21'],
                ],
                'lowest_rate' => '0.0879',
                'bpa_cents' => 1174400, // $11,744 (flat)
            ],
            '2025-07-01' => [ // no change from 2025-01-01 (BPANS flat $11,744)
                'brackets' => [
                    [3050700, '0.0879'],
                    [6101500, '0.1495'],
                    [9588300, '0.1667'],
                    [15465000, '0.175'],
                    [null, '0.21'],
                ],
                'lowest_rate' => '0.0879',
                'bpa_cents' => 1174400,
            ],
            '2026-01-01' => [
                'brackets' => [
                    [3099500, '0.0879'], // up to $30,995
                    [6199100, '0.1495'], // up to $61,991
                    [9741700, '0.1667'], // up to $97,417
                    [15712400, '0.175'], // up to $157,124
                    [null, '0.21'],
                ],
                'lowest_rate' => '0.0879',
                'bpa_cents' => 1193200, // $11,932 (flat)
            ],
            '2026-07-01' => [ // no change from 2026-01-01
                'brackets' => [
                    [3099500, '0.0879'],
                    [6199100, '0.1495'],
                    [9741700, '0.1667'],
                    [15712400, '0.175'],
                    [null, '0.21'],
                ],
                'lowest_rate' => '0.0879',
                'bpa_cents' => 1193200,
            ],
        ],
        'NT' => [
            '2025-01-01' => [
                'brackets' => [
                    [5196400, '0.059'],  // up to $51,964
                    [10393000, '0.086'], // up to $103,930
                    [16896700, '0.122'], // up to $168,967
                    [null, '0.1405'],
                ],
                'lowest_rate' => '0.059',
                'bpa_cents' => 1784200, // $17,842
            ],
            '2025-07-01' => [ // no change from 2025-01-01
                'brackets' => [
                    [5196400, '0.059'],
                    [10393000, '0.086'],
                    [16896700, '0.122'],
                    [null, '0.1405'],
                ],
                'lowest_rate' => '0.059',
                'bpa_cents' => 1784200,
            ],
            '2026-01-01' => [
                'brackets' => [
                    [5300300, '0.059'],  // up to $53,003
                    [10600900, '0.086'], // up to $106,009
                    [17234600, '0.122'], // up to $172,346
                    [null, '0.1405'],
                ],
                'lowest_rate' => '0.059',
                'bpa_cents' => 1819800, // $18,198
            ],
            '2026-07-01' => [ // no change from 2026-01-01
                'brackets' => [
                    [5300300, '0.059'],
                    [10600900, '0.086'],
                    [17234600, '0.122'],
                    [null, '0.1405'],
                ],
                'lowest_rate' => '0.059',
                'bpa_cents' => 1819800,
            ],
        ],
        'NU' => [
            '2025-01-01' => [
                'brackets' => [
                    [5470700, '0.04'],   // up to $54,707
                    [10941300, '0.07'],  // up to $109,413
                    [17788100, '0.09'],  // up to $177,881
                    [null, '0.115'],
                ],
                'lowest_rate' => '0.04',
                'bpa_cents' => 1927400, // $19,274
            ],
            '2025-07-01' => [ // no change from 2025-01-01
                'brackets' => [
                    [5470700, '0.04'],
                    [10941300, '0.07'],
                    [17788100, '0.09'],
                    [null, '0.115'],
                ],
                'lowest_rate' => '0.04',
                'bpa_cents' => 1927400,
            ],
            '2026-01-01' => [
                'brackets' => [
                    [5580100, '0.04'],   // up to $55,801
                    [11160200, '0.07'],  // up to $111,602
                    [18143900, '0.09'],  // up to $181,439
                    [null, '0.115'],
                ],
                'lowest_rate' => '0.04',
                'bpa_cents' => 1965900, // $19,659
            ],
            '2026-07-01' => [ // no change from 2026-01-01
                'brackets' => [
                    [5580100, '0.04'],
                    [11160200, '0.07'],
                    [18143900, '0.09'],
                    [null, '0.115'],
                ],
                'lowest_rate' => '0.04',
                'bpa_cents' => 1965900,
            ],
        ],
        'ON' => [
            '2025-01-01' => [
                'brackets' => [
                    [5288600, '0.0505'],  // up to $52,886
                    [10577500, '0.0915'], // up to $105,775
                    [15000000, '0.1116'], // up to $150,000
                    [22000000, '0.1216'], // up to $220,000
                    [null, '0.1316'],
                ],
                'lowest_rate' => '0.0505',
                'bpa_cents' => 1274700, // $12,747
                // Ontario surtax (factor V1): marginal +20% over $5,710 basic tax,
                // +16% more over $7,307 (36% total above $7,307).
                'surtax' => [
                    [571000, '0.20'],
                    [730700, '0.16'],
                ],
                // Ontario Health Premium (factor V2): [income_upper, base, rate, cap].
                'health_premium' => [
                    [2000000, 0, '0', 0],            // ≤ $20,000 → $0
                    [3600000, 0, '0.06', 30000],     // $20,000–$36,000 → up to $300
                    [4800000, 30000, '0.06', 45000], // $36,000–$48,000 → up to $450
                    [7200000, 45000, '0.25', 60000], // $48,000–$72,000 → up to $600
                    [20000000, 60000, '0.25', 75000], // $72,000–$200,000 → up to $750
                    [null, 75000, '0.25', 90000],    // > $200,000 → up to $900
                ],
            ],
            '2025-07-01' => [ // no change from 2025-01-01
                'brackets' => [
                    [5288600, '0.0505'],
                    [10577500, '0.0915'],
                    [15000000, '0.1116'],
                    [22000000, '0.1216'],
                    [null, '0.1316'],
                ],
                'lowest_rate' => '0.0505',
                'bpa_cents' => 1274700,
                'surtax' => [
                    [571000, '0.20'],
                    [730700, '0.16'],
                ],
                'health_premium' => [
                    [2000000, 0, '0', 0],
                    [3600000, 0, '0.06', 30000],
                    [4800000, 30000, '0.06', 45000],
                    [7200000, 45000, '0.25', 60000],
                    [20000000, 60000, '0.25', 75000],
                    [null, 75000, '0.25', 90000],
                ],
            ],
            '2026-01-01' => [
                'brackets' => [
                    [5389100, '0.0505'],  // up to $53,891
                    [10778500, '0.0915'], // up to $107,785
                    [15000000, '0.1116'], // up to $150,000
                    [22000000, '0.1216'], // up to $220,000
                    [null, '0.1316'],
                ],
                'lowest_rate' => '0.0505',
                'bpa_cents' => 1298900, // $12,989
                'surtax' => [
                    [581800, '0.20'], // +20% over $5,818
                    [744600, '0.16'], // +16% more over $7,446
                ],
                'health_premium' => [
                    [2000000, 0, '0', 0],
                    [3600000, 0, '0.06', 30000],
                    [4800000, 30000, '0.06', 45000],
                    [7200000, 45000, '0.25', 60000],
                    [20000000, 60000, '0.25', 75000],
                    [null, 75000, '0.25', 90000],
                ],
            ],
            '2026-07-01' => [ // no change from 2026-01-01
                'brackets' => [
                    [5389100, '0.0505'],
                    [10778500, '0.0915'],
                    [15000000, '0.1116'],
                    [22000000, '0.1216'],
                    [null, '0.1316'],
                ],
                'lowest_rate' => '0.0505',
                'bpa_cents' => 1298900,
                'surtax' => [
                    [581800, '0.20'],
                    [744600, '0.16'],
                ],
                'health_premium' => [
                    [2000000, 0, '0', 0],
                    [3600000, 0, '0.06', 30000],
                    [4800000, 30000, '0.06', 45000],
                    [7200000, 45000, '0.25', 60000],
                    [20000000, 60000, '0.25', 75000],
                    [null, 75000, '0.25', 90000],
                ],
            ],
        ],
        'PE' => [
            '2025-01-01' => [
                'brackets' => [
                    [3332800, '0.095'],   // up to $33,328
                    [6465600, '0.1347'],  // up to $64,656
                    [10500000, '0.166'],  // up to $105,000
                    [14000000, '0.1762'], // up to $140,000
                    [null, '0.19'],
                ],
                'lowest_rate' => '0.095',
                'bpa_cents' => 1425000, // $14,250
            ],
            // Jul 1 2025: BPA raised to $14,650 annual, prorated to $15,050 for
            // Jul–Dec to offset the $14,250 used Jan–Jun.
            '2025-07-01' => [
                'brackets' => [
                    [3332800, '0.095'],
                    [6465600, '0.1347'],
                    [10500000, '0.166'],
                    [14000000, '0.1762'],
                    [null, '0.19'],
                ],
                'lowest_rate' => '0.095',
                'bpa_cents' => 1505000, // prorated $15,050
            ],
            '2026-01-01' => [
                'brackets' => [
                    [3392800, '0.095'],   // up to $33,928
                    [6582000, '0.1347'],  // up to $65,820
                    [10689000, '0.166'],  // up to $106,890
                    [14252000, '0.1762'], // up to $142,520
                    [null, '0.19'],
                ],
                'lowest_rate' => '0.095',
                'bpa_cents' => 1500000, // $15,000
            ],
            // Jul 1 2026: new bracket on income over $200,000 at 20% annual,
            // billed at a prorated 21% for Jul–Dec.
            '2026-07-01' => [
                'brackets' => [
                    [3392800, '0.095'],   // up to $33,928
                    [6582000, '0.1347'],  // up to $65,820
                    [10689000, '0.166'],  // up to $106,890
                    [14252000, '0.1762'], // up to $142,520
                    [20000000, '0.19'],   // up to $200,000
                    [null, '0.21'],       // over $200,000 (prorated 21%)
                ],
                'lowest_rate' => '0.095',
                'bpa_cents' => 1500000,
            ],
        ],
        'SK' => [
            '2025-01-01' => [
                'brackets' => [
                    [5346300, '0.105'],  // up to $53,463
                    [15275000, '0.125'], // up to $152,750
                    [null, '0.145'],
                ],
                'lowest_rate' => '0.105',
                'bpa_cents' => 1899100, // $18,991
            ],
            // Jul 1 2025: BPA raised to $19,491 annual, prorated to $19,991 for
            // Jul–Dec to offset the $18,991 used Jan–Jun.
            '2025-07-01' => [
                'brackets' => [
                    [5346300, '0.105'],
                    [15275000, '0.125'],
                    [null, '0.145'],
                ],
                'lowest_rate' => '0.105',
                'bpa_cents' => 1999100, // prorated $19,991
            ],
            '2026-01-01' => [
                'brackets' => [
                    [5453200, '0.105'],  // up to $54,532
                    [15580500, '0.125'], // up to $155,805
                    [null, '0.145'],
                ],
                'lowest_rate' => '0.105',
                'bpa_cents' => 2038100, // $20,381
            ],
            '2026-07-01' => [ // no change from 2026-01-01
                'brackets' => [
                    [5453200, '0.105'],
                    [15580500, '0.125'],
                    [null, '0.145'],
                ],
                'lowest_rate' => '0.105',
                'bpa_cents' => 2038100,
            ],
        ],
        'YT' => [
            // Yukon mirrors the federal (income-tested) basic personal amount.
            '2025-01-01' => [
                'brackets' => [
                    [5737500, '0.064'],   // up to $57,375
                    [11475000, '0.09'],   // up to $114,750
                    [17788200, '0.109'],  // up to $177,882
                    [50000000, '0.128'],  // up to $500,000
                    [null, '0.15'],
                ],
                'lowest_rate' => '0.064',
                'bpa_cents' => 1612900, // fallback when income testing is unavailable
                'bpa_max_cents' => 1612900, // $16,129
                'bpa_min_cents' => 1453800, // $14,538
                'bpa_phaseout_low_cents' => 17788200,  // $177,882
                'bpa_phaseout_high_cents' => 25341400, // $253,414
            ],
            '2025-07-01' => [ // no change from 2025-01-01
                'brackets' => [
                    [5737500, '0.064'],
                    [11475000, '0.09'],
                    [17788200, '0.109'],
                    [50000000, '0.128'],
                    [null, '0.15'],
                ],
                'lowest_rate' => '0.064',
                'bpa_cents' => 1612900,
                'bpa_max_cents' => 1612900,
                'bpa_min_cents' => 1453800,
                'bpa_phaseout_low_cents' => 17788200,
                'bpa_phaseout_high_cents' => 25341400,
            ],
            '2026-01-01' => [
                'brackets' => [
                    [5852300, '0.064'],   // up to $58,523
                    [11704500, '0.09'],   // up to $117,045
                    [18144000, '0.109'],  // up to $181,440
                    [50000000, '0.128'],  // up to $500,000
                    [null, '0.15'],
                ],
                'lowest_rate' => '0.064',
                'bpa_cents' => 1645200,
                'bpa_max_cents' => 1645200, // $16,452
                'bpa_min_cents' => 1482900, // $14,829
                'bpa_phaseout_low_cents' => 18144000,  // $181,440
                'bpa_phaseout_high_cents' => 25848200, // $258,482
            ],
            '2026-07-01' => [ // no change from 2026-01-01
                'brackets' => [
                    [5852300, '0.064'],
                    [11704500, '0.09'],
                    [18144000, '0.109'],
                    [50000000, '0.128'],
                    [null, '0.15'],
                ],
                'lowest_rate' => '0.064',
                'bpa_cents' => 1645200,
                'bpa_max_cents' => 1645200,
                'bpa_min_cents' => 1482900,
                'bpa_phaseout_low_cents' => 18144000,
                'bpa_phaseout_high_cents' => 25848200,
            ],
        ],
        'QC' => [
            // Quebec provincial income tax (brackets / bpa_cents / worker deduction)
            // is from Revenu Québec TP-1015.G; the 'quebec' bag (QPP/QPP2/EI-QC/QPIP/
            // abatement) is from the CRA T4127. Both 2025 and 2026 verified vs source.
            '2025-01-01' => [
                'brackets' => [
                    [5325500, '0.14'],   // up to $53,255
                    [10649500, '0.19'],  // up to $106,495
                    [12959000, '0.24'],  // up to $129,590
                    [null, '0.2575'],
                ],
                'lowest_rate' => '0.14',
                'bpa_cents' => 1857100, // $18,571 (credited at 14%)
                'quebec' => [
                    'abatement_rate' => '0.165',          // federal tax × (1 − 0.165)
                    'worker_deduction_rate' => '0.06',    // "deduction for workers"
                    'worker_deduction_max_cents' => 142000, // $1,420
                    'qpp' => [
                        'rate' => '0.0640',               // base 5.40% + first-additional 1.00%
                        'base_rate' => '0.0540',
                        'first_additional_rate' => '0.0100',
                        'basic_exemption_cents' => 350000,
                        'max_pensionable_cents' => 7130000,  // YMPE $71,300
                        'max_contribution_cents' => 433920,  // (71,300 − 3,500) × 6.40% = $4,339.20
                    ],
                    'qpp2' => [
                        'rate' => '0.04',
                        'lower_cents' => 7130000,
                        'upper_cents' => 8120000,
                        'max_contribution_cents' => 39600,
                    ],
                    'ei' => [
                        'rate' => '0.0131',               // Quebec reduced EI rate
                        'max_premium_cents' => 86067,     // $65,700 × 1.31% = $860.67
                    ],
                    'qpip' => [
                        'employee_rate' => '0.00494',
                        'employer_rate' => '0.00692',
                        'max_insurable_cents' => 9800000,           // $98,000
                        'max_employee_premium_cents' => 48412,      // $98,000 × 0.494% = $484.12
                    ],
                ],
            ],
            '2025-07-01' => [ // no change from 2025-01-01
                'brackets' => [
                    [5325500, '0.14'],
                    [10649500, '0.19'],
                    [12959000, '0.24'],
                    [null, '0.2575'],
                ],
                'lowest_rate' => '0.14',
                'bpa_cents' => 1857100,
                'quebec' => [
                    'abatement_rate' => '0.165',
                    'worker_deduction_rate' => '0.06',
                    'worker_deduction_max_cents' => 142000,
                    'qpp' => [
                        'rate' => '0.0640',
                        'base_rate' => '0.0540',
                        'first_additional_rate' => '0.0100',
                        'basic_exemption_cents' => 350000,
                        'max_pensionable_cents' => 7130000,
                        'max_contribution_cents' => 433920,
                    ],
                    'qpp2' => [
                        'rate' => '0.04',
                        'lower_cents' => 7130000,
                        'upper_cents' => 8120000,
                        'max_contribution_cents' => 39600,
                    ],
                    'ei' => [
                        'rate' => '0.0131',
                        'max_premium_cents' => 86067,
                    ],
                    'qpip' => [
                        'employee_rate' => '0.00494',
                        'employer_rate' => '0.00692',
                        'max_insurable_cents' => 9800000,
                        'max_employee_premium_cents' => 48412,
                    ],
                ],
            ],
            // 2026: QPP base rate cut 5.40% → 5.30% (total 6.30%); QPIP rates cut and
            // ceiling raised to $103,000; EI-Quebec 1.30%. Provincial tax brackets,
            // BPA $18,952, worker deduction $1,450 from Revenu Québec TP-1015.G (2026).
            '2026-01-01' => [
                'brackets' => [
                    [5434500, '0.14'],   // up to $54,345 (Revenu Québec TP-1015.G 2026)
                    [10868000, '0.19'],  // up to $108,680
                    [13224500, '0.24'],  // up to $132,245
                    [null, '0.2575'],
                ],
                'lowest_rate' => '0.14',
                'bpa_cents' => 1895200, // $18,952 (Revenu Québec TP-1015.G 2026)
                'quebec' => [
                    'abatement_rate' => '0.165',
                    'worker_deduction_rate' => '0.06',
                    'worker_deduction_max_cents' => 145000, // $1,450 (Revenu Québec TP-1015.G 2026)
                    'qpp' => [
                        'rate' => '0.0630',               // base 5.30% + first-additional 1.00%
                        'base_rate' => '0.0530',
                        'first_additional_rate' => '0.0100',
                        'basic_exemption_cents' => 350000,
                        'max_pensionable_cents' => 7460000,  // YMPE $74,600
                        'max_contribution_cents' => 447930,  // (74,600 − 3,500) × 6.30% = $4,479.30
                    ],
                    'qpp2' => [
                        'rate' => '0.04',
                        'lower_cents' => 7460000,
                        'upper_cents' => 8500000,
                        'max_contribution_cents' => 41600,
                    ],
                    'ei' => [
                        'rate' => '0.0130',               // Quebec reduced EI rate
                        'max_premium_cents' => 89570,     // $68,900 × 1.30% = $895.70
                    ],
                    'qpip' => [
                        'employee_rate' => '0.00430',
                        'employer_rate' => '0.00602',
                        'max_insurable_cents' => 10300000,          // $103,000
                        'max_employee_premium_cents' => 44290,      // $103,000 × 0.430% = $442.90
                    ],
                ],
            ],
            '2026-07-01' => [ // no Quebec change mid-2026 (duplicate of 2026-01-01)
                'brackets' => [
                    [5434500, '0.14'],   // up to $54,345 (Revenu Québec TP-1015.G 2026)
                    [10868000, '0.19'],  // up to $108,680
                    [13224500, '0.24'],  // up to $132,245
                    [null, '0.2575'],
                ],
                'lowest_rate' => '0.14',
                'bpa_cents' => 1895200, // $18,952 (Revenu Québec TP-1015.G 2026)
                'quebec' => [
                    'abatement_rate' => '0.165',
                    'worker_deduction_rate' => '0.06',
                    'worker_deduction_max_cents' => 145000, // $1,450 (Revenu Québec TP-1015.G 2026)
                    'qpp' => [
                        'rate' => '0.0630',
                        'base_rate' => '0.0530',
                        'first_additional_rate' => '0.0100',
                        'basic_exemption_cents' => 350000,
                        'max_pensionable_cents' => 7460000,
                        'max_contribution_cents' => 447930,
                    ],
                    'qpp2' => [
                        'rate' => '0.04',
                        'lower_cents' => 7460000,
                        'upper_cents' => 8500000,
                        'max_contribution_cents' => 41600,
                    ],
                    'ei' => [
                        'rate' => '0.0130',
                        'max_premium_cents' => 89570,
                    ],
                    'qpip' => [
                        'employee_rate' => '0.00430',
                        'employer_rate' => '0.00602',
                        'max_insurable_cents' => 10300000,
                        'max_employee_premium_cents' => 44290,
                    ],
                ],
            ],
        ],
    ];

    /**
     * The provincial constants for the province effective on the given date, or
     * null if the province/date is not loaded.
     *
     * @return array<string, mixed>|null
     */
    public static function for(string $province, string $payDate): ?array
    {
        $tables = self::PERIODS[$province] ?? null;

        if ($tables === null) {
            return null;
        }

        $match = null;

        foreach ($tables as $effective => $values) {
            if ($effective <= $payDate) {
                $match = $values;
            }
        }

        return $match;
    }

    /**
     * @return array<int, string> Provinces with at least one loaded table.
     */
    public static function loadedProvinces(): array
    {
        return array_keys(self::PERIODS);
    }
}
