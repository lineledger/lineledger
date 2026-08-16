<?php

namespace App\Support\Tax;

use App\Enums\Country;
use App\Enums\LegalStructure;
use App\Enums\OrganizationType;
use App\Enums\TaxForm;
use App\Models\Company;

/**
 * The single source of truth for which CRA return(s) a company files and the
 * financial-statement capabilities that follow. Which form applies is a function
 * of the company's jurisdiction, organization type, and legal tier:
 *
 *   Corporation (for-profit) ........... T2 (GIFI S100/S125)
 *   Non-profit corporation ............. T2 (GIFI S100/S125) + T1044 info return
 *   Unincorporated NPO / club .......... T1044 info return only (no GIFI)
 *   Registered charity ................. T3010 (no GIFI)
 *   Partnership ........................ T5013 (GIFI S100/S125)
 *   Sole proprietorship ................ T2125 (GIFI-based lines + CCA)
 *   Other / non-Canadian ............... none
 *
 * Company helpers (usesGifi/filesT5013/filesT2125/mapsGifiCodes) delegate here so
 * the matrix lives in exactly one place. Report gating, mount guards, the account
 * form, and the Tax & filing settings page all read from this.
 */
final class FilingProfile
{
    private function __construct(
        public readonly bool $isCanadian,
        public readonly OrganizationType $orgType,
        public readonly ?LegalStructure $legalStructure,
    ) {}

    public static function for(Company $company): self
    {
        return new self(
            $company->jurisdiction === Country::Canada,
            $company->organization_type ?? OrganizationType::Other,
            $company->resolvedLegalStructure(),
        );
    }

    /** Files the GIFI Statement (T2 — corporations and non-profit corporations). */
    public function filesGifiStatement(): bool
    {
        if (! $this->isCanadian) {
            return false;
        }

        return $this->orgType === OrganizationType::Corporation
            || ($this->orgType === OrganizationType::NonProfit && $this->legalStructure === LegalStructure::NonProfitCorporation);
    }

    /** Files the T5013 partnership return (financials are GIFI S100/S125). */
    public function filesT5013(): bool
    {
        return $this->isCanadian && $this->orgType === OrganizationType::Partnership;
    }

    /** Files the T2125 statement of business activities (sole proprietors). */
    public function filesT2125(): bool
    {
        return $this->isCanadian && $this->orgType === OrganizationType::SoleProprietorship;
    }

    /** Files the T3010 registered-charity return. */
    public function filesT3010(): bool
    {
        return $this->isCanadian && $this->legalStructure === LegalStructure::RegisteredCharity;
    }

    /**
     * Whether a non-profit/club must also file the T1044 information return. We
     * surface it as guidance for every NPO tier (the thresholds are the user's to
     * assess); registered charities file the T3010 instead, not the T1044.
     */
    public function filesT1044(): bool
    {
        return $this->isCanadian
            && $this->orgType->isNonProfit()
            && $this->legalStructure !== LegalStructure::RegisteredCharity;
    }

    /**
     * Whether per-account GIFI line mapping is useful — i.e. the company files a
     * return whose financial statement is built on GIFI line codes (T2, T5013,
     * or T2125).
     */
    public function mapsGifiCodes(): bool
    {
        return $this->filesGifiStatement() || $this->filesT5013() || $this->filesT2125();
    }

    /** The headline return this company files, for display. */
    public function primaryForm(): ?TaxForm
    {
        return match (true) {
            $this->filesGifiStatement() => TaxForm::T2,
            $this->filesT5013() => TaxForm::T5013,
            $this->filesT2125() => TaxForm::T2125,
            $this->filesT3010() => TaxForm::T3010,
            default => null,
        };
    }

    /**
     * Every applicable form with a short note. The primary return comes first,
     * followed by any additional information returns (e.g. the T1044).
     *
     * @return list<array{form: TaxForm, primary: bool, note: string}>
     */
    public function forms(): array
    {
        $forms = [];

        $primary = $this->primaryForm();

        if ($primary !== null) {
            $forms[] = ['form' => $primary, 'primary' => true, 'note' => __('Your primary annual return.')];
        }

        if ($this->filesT1044()) {
            $forms[] = ['form' => TaxForm::T1044, 'primary' => false, 'note' => __('May be required if you meet CRA income or asset thresholds.')];
        }

        return $forms;
    }
}
