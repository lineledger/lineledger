<?php

namespace App\Support\Jurisdiction;

use App\Enums\Country;
use App\Enums\JurisdictionCapability;
use App\Models\Company;
use App\Support\Tax\FilingProfile;

/**
 * The single source of truth for which jurisdiction-specific features a company
 * may use. Every jurisdiction guard in the app routes through supports().
 *
 * It composes several kinds of rule and DELEGATES rather than duplicates:
 *   - country × feature-flag (payroll) — the one leaf predicate kept inline here;
 *   - country × organization type × legal structure (CRA returns) — delegated to
 *     {@see FilingProfile};
 *   - country × entity × flag (charity receipts) — delegated to
 *     {@see Company::isRegisteredCharity()};
 *   - pure country (1099, vendor tracking, CRA filing page, CCA selector).
 *
 * Note the asymmetry: Company::isRegisteredCharity() and the inline payroll
 * predicate are LEAF implementations the resolver delegates *to*; the other
 * Company helpers (usesPayroll/usesGifi/filesT5013/filesT2125/mapsGifiCodes) are
 * thin wrappers that delegate *here*. Wrapping the leaves would be circular.
 */
final class JurisdictionProfile
{
    private function __construct(
        private readonly Company $company,
        private readonly FilingProfile $filing,
    ) {}

    public static function for(Company $company): self
    {
        return new self($company, FilingProfile::for($company));
    }

    public function supports(JurisdictionCapability $capability): bool
    {
        return match ($capability) {
            // country × feature-flag (leaf predicate, kept inline to avoid circular delegation)
            // The site admin's payroll_admin_enabled_at override implicitly satisfies the
            // features_payroll opt-in — see the admin company detail page.
            JurisdictionCapability::Payroll,
            JurisdictionCapability::T4Slips,
            JurisdictionCapability::T4ASlips,
            JurisdictionCapability::Pd7aRemittance,
            JurisdictionCapability::RecordOfEmployment,
            JurisdictionCapability::WorkersComp => ($this->company->features_payroll || $this->company->payroll_admin_enabled_at !== null)
                && $this->company->jurisdiction === Country::Canada,

            // country × organization type × legal structure (delegated to FilingProfile)
            JurisdictionCapability::GifiStatement => $this->filing->filesGifiStatement(),
            JurisdictionCapability::T5013 => $this->filing->filesT5013(),
            JurisdictionCapability::T2125 => $this->filing->filesT2125(),
            JurisdictionCapability::T3010 => $this->filing->filesT3010(),
            JurisdictionCapability::T1044 => $this->filing->filesT1044(),
            JurisdictionCapability::GifiCodeMapping => $this->filing->mapsGifiCodes(),

            // country × entity × flag (delegated to the leaf charity predicate)
            JurisdictionCapability::CharityDonationReceipts => $this->company->isRegisteredCharity(),

            // pure country — United States
            JurisdictionCapability::Form1099,
            JurisdictionCapability::Vendor1099Tracking => $this->company->jurisdiction === Country::UnitedStates,

            // pure country — Canada
            JurisdictionCapability::VendorT4ATracking,
            JurisdictionCapability::CraTaxFiling,
            JurisdictionCapability::CanadianCapitalCostAllowance => $this->company->jurisdiction === Country::Canada,
        };
    }
}
