<?php

namespace App\Support\Gifi;

/**
 * Moves accounts off the GIFI codes the first catalog got wrong.
 *
 * Until the catalog was checked against CRA's RC4088 guide, it put the wrong
 * label on several codes ("8620 Insurance" is really 8620 Employee benefits)
 * and offered CRA total items (2589 Total long-term assets) as ordinary lines.
 * Members chose a code from a select that showed the label, so the label is
 * what they meant: each old code here maps to the code CRA uses for that
 * label. Codes chain (8620 → 8690, and 8690 → 8710), so a correction is always
 * applied to the code as it was stored, once — never to an already corrected
 * one.
 *
 * Backups record {@see self::REVISION} in their manifest. A bundle without it
 * predates the correction, so a restore corrects its accounts the same way the
 * migration corrected the live ones.
 */
final class GifiCodeCorrections
{
    /** The GIFI code revision a backup manifest records as `gifi_codes`. */
    public const REVISION = 2;

    /**
     * Old code => the code CRA uses for the label the old catalog showed. PHP
     * stores the numeric keys as ints.
     *
     * @var array<int, string>
     */
    public const MAP = [
        '1001' => '1000', // "Cash and deposits" is 1000; 1001 is Cash alone
        '1067' => '1061', // "Allowance for doubtful accounts"; 1067 is Interest receivable
        '1743' => '1742', // "Motor vehicles"; 1743 is their accumulated amortization
        '1788' => '1918', // "Leasehold improvements"; 1788 is amortization of furniture
        '2008' => '1741', // "Accumulated amortization of tangible assets"; 2008 is Total tangible capital assets
        '2360' => '2300', // "Long-term investments"; 2360 is Long-term loans
        '2420' => '2012', // "Goodwill"; 2420 is Other long-term assets
        '2589' => '2420', // "Other long-term assets"; 2589 is Total long-term assets
        '3220' => '3240', // "Deferred income taxes"; 3220 is Deferred income
        '3450' => '3320', // "Other long-term liabilities"; 3450 is Total long-term liabilities
        '3849' => '3600', // "Retained earnings / deficit"; 3849 is Retained earnings/deficit – End
        '8210' => '8140', // "Rental revenue"; 8210 is Realized gains/losses on disposal of assets
        '8457' => '8360', // "Subcontracts"; 8457 is Freight-in and duty
        '8620' => '8690', // "Insurance"; 8620 is Employee benefits
        '8690' => '8710', // "Interest and bank charges"; 8690 is Insurance
        '8710' => '8714', // "Interest on long-term debt"; 8710 is Interest and bank charges
        '9367' => '8670', // "Amortization of tangible assets"; 9367 is Total operating expenses
        '9368' => '8570', // "Amortization of intangible assets"; 9368 is Total expenses
    ];

    /** The corrected code for one stored under the old catalog. */
    public static function correct(?string $code): ?string
    {
        return $code === null ? null : (self::MAP[$code] ?? $code);
    }

    /**
     * Whether a backup bundle was written before the correction, so its
     * accounts still carry the old codes.
     *
     * @param  array<string, mixed>  $manifest
     */
    public static function bundleNeedsCorrection(array $manifest): bool
    {
        return (int) ($manifest['gifi_codes'] ?? 1) < self::REVISION;
    }
}
