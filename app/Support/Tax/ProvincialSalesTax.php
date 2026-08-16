<?php

namespace App\Support\Tax;

/**
 * The provinces that levy a separate provincial sales tax on top of the federal
 * GST, keyed by the province code stored in company.address_region.
 *
 * These are distinct from the HST provinces (where the provincial portion is
 * harmonized into one federal-administered rate, so there is nothing extra to
 * seed) and the GST-only jurisdictions (AB and the territories).
 *
 * Quebec's QST (9.975%, administered by Revenu Québec) is included now that the
 * rate column holds fractional basis points. Unlike PST/RST — true retail sales
 * taxes with no input credit — QST is a value-added tax: registrants claim input
 * tax refunds, so it is recoverable (see {@see self::isRecoverable()}).
 */
enum ProvincialSalesTax: string
{
    case BritishColumbia = 'BC';
    case Saskatchewan = 'SK';
    case Manitoba = 'MB';
    case Quebec = 'QC';

    /**
     * Resolve a province code (company.address_region) to its provincial sales
     * tax, or null when the province has none (HST/GST-only provinces).
     */
    public static function forRegion(?string $region): ?self
    {
        return $region === null || $region === '' ? null : self::tryFrom($region);
    }

    /**
     * The short label businesses know the tax by — "PST" in BC/SK, "RST" in
     * Manitoba (Retail Sales Tax), "QST" in Quebec.
     */
    public function taxLabel(): string
    {
        return match ($this) {
            self::BritishColumbia, self::Saskatchewan => 'PST',
            self::Manitoba => 'RST',
            self::Quebec => 'QST',
        };
    }

    /**
     * The rate in basis points (1% = 100bp). The column holds up to three decimals,
     * so QST's 9.975% (997.5bp) round-trips exactly.
     */
    public function rateBasisPoints(): float
    {
        return match ($this) {
            self::BritishColumbia => 700.0,   // 7%
            self::Saskatchewan => 600.0,      // 6%
            self::Manitoba => 700.0,          // 7%
            self::Quebec => 997.5,            // 9.975%
        };
    }

    /**
     * Whether the tax is recoverable as an input credit. PST/RST are true retail
     * sales taxes with no input credit; QST is a value-added tax (input tax refund).
     */
    public function isRecoverable(): bool
    {
        return $this === self::Quebec;
    }

    /**
     * The tax-code identifier seeded for the province, e.g. PST-BC, RST-MB, QST-QC.
     */
    public function taxCode(): string
    {
        return $this->taxLabel().'-'.$this->value;
    }

    /**
     * Human-readable name for the seeded tax code, e.g. "PST (7%)" or "QST (9.975%)".
     */
    public function taxCodeName(): string
    {
        return match ($this) {
            self::BritishColumbia => 'PST (7%)',
            self::Saskatchewan => 'PST (6%)',
            self::Manitoba => 'RST (7%)',
            self::Quebec => 'QST (9.975%)',
        };
    }

    /**
     * The provincial authority that administers the tax.
     */
    public function agencyName(): string
    {
        return match ($this) {
            self::BritishColumbia => 'BC Ministry of Finance',
            self::Saskatchewan => 'Saskatchewan Ministry of Finance',
            self::Manitoba => 'Manitoba Finance',
            self::Quebec => 'Revenu Québec',
        };
    }

    /**
     * Name of the liability account that holds tax collected, e.g. "PST Payable".
     */
    public function payableAccountName(): string
    {
        return $this->taxLabel().' Payable';
    }
}
