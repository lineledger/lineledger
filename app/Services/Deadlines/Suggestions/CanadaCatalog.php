<?php

namespace App\Services\Deadlines\Suggestions;

use App\Enums\DeadlineCategory;
use App\Enums\DeadlineKind;
use App\Enums\DueDayRule;
use App\Enums\OrganizationType;
use App\Enums\RemittanceFrequency;
use App\Models\Company;
use App\Models\WorkersCompSetting;
use App\Support\Tax\FilingProfile;
use App\Support\Tax\ProvincialSalesTax;
use Carbon\CarbonImmutable;

/**
 * Canadian filing and payment deadlines, from CRA, Revenu Québec and the
 * provinces. Every rule cites the official page it was checked against; a
 * rule that couldn't be confirmed there isn't here.
 */
final class CanadaCatalog
{
    use BuildsSchedules;

    private const string CRA = 'Canada Revenue Agency';

    private const string CRA_BUSINESS_ACCOUNT = 'https://www.canada.ca/en/revenue-agency/services/e-services/digital-services-businesses/business-account.html';

    private const string BC_ETAX = 'https://www.etax.gov.bc.ca/btp/eservices/_/';

    private const string WORKSAFEBC_DATES = 'https://www.worksafebc.com/en/insurance/manage-account/report-payroll-pay-premiums/date-deadlines';

    /**
     * @return list<SuggestedDeadline>
     */
    public static function for(Company $company, SuggestionChoices $choices, CarbonImmutable $today): array
    {
        $fyMonth = max(1, min(12, (int) ($company->fiscal_year_start_month ?: 1)));
        $fyAnchor = self::previousFiscalYearStart($today, $fyMonth);
        $calendarAnchor = self::previousCalendarYearStart($today);
        $region = strtoupper((string) $company->address_region);
        $profile = FilingProfile::for($company);
        $orgType = $company->organization_type ?? OrganizationType::Other;

        return [
            ...self::salesTax($company, $choices, $fyAnchor, $fyMonth, $calendarAnchor, $orgType),
            ...self::payroll($company, $calendarAnchor, $region),
            ...self::incomeTax($profile, $choices, $fyAnchor, $calendarAnchor, $orgType),
        ];
    }

    /**
     * @return list<SuggestedDeadline>
     */
    private static function salesTax(
        Company $company,
        SuggestionChoices $choices,
        CarbonImmutable $fyAnchor,
        int $fyMonth,
        CarbonImmutable $calendarAnchor,
        OrganizationType $orgType,
    ): array {
        $out = [];
        $pst = $company->provincialSalesTax();
        $hasGst = filled($company->tax_number) || $company->taxAgencies()->where('name', self::CRA)->exists();
        $soleProprietorDecemberYearEnd = $orgType === OrganizationType::SoleProprietorship && $fyMonth === 1;
        $source = 'https://www.canada.ca/en/revenue-agency/services/tax/businesses/topics/gst-hst-businesses/file-gst-hst-return/reporting-requirements-deadlines.html';

        if ($hasGst) {
            $why = __('Your organization has a GST/HST number.');

            if ($choices->gstFrequency === 'annual' && $soleProprietorDecemberYearEnd) {
                $out[] = new SuggestedDeadline('ca.gst_hst.return', __('GST/HST return'), DeadlineCategory::SalesTax, DeadlineKind::File,
                    self::CRA, $why, $source,
                    self::periods('annual', $calendarAnchor, 6, DueDayRule::DayOfMonth, 15),
                    note: __('Individuals with business income and a December 31 year end file by June 15.'),
                    portalUrl: self::CRA_BUSINESS_ACCOUNT);
                $out[] = new SuggestedDeadline('ca.gst_hst.payment', __('GST/HST payment'), DeadlineCategory::SalesTax, DeadlineKind::Pay,
                    self::CRA, $why, $source,
                    self::periods('annual', $calendarAnchor, 4, DueDayRule::DayOfMonth, 30),
                    note: __('The payment is due April 30, before the return.'),
                    portalUrl: self::CRA_BUSINESS_ACCOUNT);
            } else {
                $out[] = new SuggestedDeadline('ca.gst_hst.return', __('GST/HST return'), DeadlineCategory::SalesTax, DeadlineKind::FileAndPay,
                    self::CRA, $why, $source,
                    match ($choices->gstFrequency) {
                        'monthly' => self::periods('monthly', $fyAnchor, 1, DueDayRule::LastDay),
                        'annual' => self::periods('annual', $fyAnchor, 3, DueDayRule::LastDay),
                        default => self::periods('quarterly', $fyAnchor, 1, DueDayRule::LastDay),
                    },
                    portalUrl: self::CRA_BUSINESS_ACCOUNT);
            }

            if ($choices->gstFrequency === 'annual') {
                $out[] = new SuggestedDeadline('ca.gst_hst.instalments', __('GST/HST instalments'), DeadlineCategory::SalesTax, DeadlineKind::Pay,
                    self::CRA, __('Annual filers whose net tax was $3,000 or more pay quarterly instalments.'),
                    'https://www.canada.ca/en/revenue-agency/services/tax/businesses/topics/gst-hst-businesses/pay-instalment/when-to-pay.html',
                    self::periods('quarterly', $fyAnchor, 1, DueDayRule::LastDay),
                    defaultOn: false,
                    portalUrl: self::CRA_BUSINESS_ACCOUNT);
            }
        }

        if ($pst === ProvincialSalesTax::BritishColumbia) {
            $out[] = new SuggestedDeadline('ca.bc.pst', __('BC PST return'), DeadlineCategory::SalesTax, DeadlineKind::FileAndPay,
                $pst->agencyName(), __('Your organization is in British Columbia.'),
                'https://www2.gov.bc.ca/gov/content/taxes/sales-taxes/pst/report-pay',
                self::periods($choices->pstFrequency, $calendarAnchor, 1, DueDayRule::LastDay),
                portalUrl: self::BC_ETAX);
        }

        if ($pst === ProvincialSalesTax::Saskatchewan) {
            $out[] = new SuggestedDeadline('ca.sk.pst', __('Saskatchewan PST return'), DeadlineCategory::SalesTax, DeadlineKind::FileAndPay,
                $pst->agencyName(), __('Your organization is in Saskatchewan.'),
                'https://www.saskatchewan.ca/business/taxes-licensing-and-reporting/provincial-taxes-policies-and-bulletins/provincial-sales-tax/file-your-pst-return',
                // Filed and paid electronically: the last day of the following month.
                self::periods($choices->pstFrequency, $calendarAnchor, 1, DueDayRule::LastDay),
                note: __('For returns filed and paid electronically. Paper returns and payments are due the 20th; change the due day if you file that way.'));
        }

        if ($pst === ProvincialSalesTax::Manitoba) {
            $out[] = new SuggestedDeadline('ca.mb.rst', __('Manitoba RST return'), DeadlineCategory::SalesTax, DeadlineKind::FileAndPay,
                $pst->agencyName(), __('Your organization is in Manitoba.'),
                'https://www.gov.mb.ca/finance/taxation/pubs/bulletins/004.pdf',
                self::periods($choices->pstFrequency, $calendarAnchor, 1, DueDayRule::DayOfMonth, 20));
        }

        if ($pst === ProvincialSalesTax::Quebec) {
            $out[] = new SuggestedDeadline('ca.qc.qst', __('QST return'), DeadlineCategory::SalesTax, DeadlineKind::FileAndPay,
                $pst->agencyName(), __('Your organization is in Quebec; the QST return follows your GST/HST reporting period.'),
                'https://www.revenuquebec.ca/en/businesses/consumption-taxes/gsthst-and-qst/reporting-gsthst-and-qst/filing-frequency/',
                match ($choices->gstFrequency) {
                    'monthly' => self::periods('monthly', $fyAnchor, 1, DueDayRule::LastDay),
                    'annual' => self::periods('annual', $fyAnchor, 3, DueDayRule::LastDay),
                    default => self::periods('quarterly', $fyAnchor, 1, DueDayRule::LastDay),
                });
        }

        return $out;
    }

    /**
     * @return list<SuggestedDeadline>
     */
    private static function payroll(Company $company, CarbonImmutable $calendarAnchor, string $region): array
    {
        $out = [];
        $wcbProvinces = WorkersCompSetting::query()->where('is_active', true)->pluck('province')->map(fn ($p) => strtoupper((string) $p));

        if ($company->usesPayroll()) {
            $stored = $company->getAttribute('payroll_remittance_frequency');
            $frequency = $stored instanceof RemittanceFrequency
                ? $stored
                : (RemittanceFrequency::tryFrom((string) $stored) ?? RemittanceFrequency::Monthly);
            $why = __('Payroll is on, with :frequency remittances.', ['frequency' => mb_strtolower($frequency->label())]);
            $source = 'https://www.canada.ca/en/revenue-agency/services/tax/businesses/topics/payroll/remitting-source-deductions/how-when-remit-due-dates.html';

            $remittances = match ($frequency) {
                RemittanceFrequency::Quarterly => [
                    ['ca.payroll.source_deductions', __('Payroll source deductions'), self::periods('quarterly', $calendarAnchor, 1, DueDayRule::DayOfMonth, 15)],
                ],
                RemittanceFrequency::Monthly => [
                    ['ca.payroll.source_deductions', __('Payroll source deductions'), self::periods('monthly', $calendarAnchor, 1, DueDayRule::DayOfMonth, 15)],
                ],
                RemittanceFrequency::Accelerated1 => [
                    ['ca.payroll.source_deductions_first_half', __('Source deductions (paid 1st–15th)'), self::periods('monthly', $calendarAnchor, 0, DueDayRule::DayOfMonth, 25)],
                    ['ca.payroll.source_deductions_second_half', __('Source deductions (paid 16th–month end)'), self::periods('monthly', $calendarAnchor, 1, DueDayRule::DayOfMonth, 10)],
                ],
                RemittanceFrequency::Accelerated2 => [],
            };

            foreach ($remittances as [$key, $title, $schedule]) {
                $out[] = new SuggestedDeadline($key, $title, DeadlineCategory::PayrollRemittance, DeadlineKind::Pay,
                    self::CRA, $why, $source, $schedule, portalUrl: self::CRA_BUSINESS_ACCOUNT);
            }

            $out[] = new SuggestedDeadline('ca.payroll.t4', __('T4 and T4A slips and summaries'), DeadlineCategory::InformationReturn, DeadlineKind::File,
                self::CRA, __('Payroll is on.'),
                'https://www.canada.ca/en/revenue-agency/services/tax/businesses/topics/payroll/file-information-returns-slip-summaries/when-to-file.html',
                self::periods('annual', $calendarAnchor, 2, DueDayRule::LastDay),
                portalUrl: self::CRA_BUSINESS_ACCOUNT);

            if ($region === 'QC') {
                $out[] = new SuggestedDeadline('ca.qc.source_deductions', __('Revenu Québec source deductions'), DeadlineCategory::PayrollRemittance, DeadlineKind::Pay,
                    'Revenu Québec', __('Payroll is on and your organization is in Quebec.'),
                    'https://www.revenuquebec.ca/en/businesses/source-deductions-and-employer-contributions/remitting-source-deductions-and-employer-contributions/frequency-of-remittances/',
                    self::periods($frequency === RemittanceFrequency::Quarterly ? 'quarterly' : 'monthly', $calendarAnchor, 1, DueDayRule::DayOfMonth, 15),
                    note: $frequency === RemittanceFrequency::Monthly || $frequency === RemittanceFrequency::Quarterly
                        ? null
                        : __('Accelerated remitters: change the due day to match your Revenu Québec schedule.'));
                $out[] = new SuggestedDeadline('ca.qc.rl1', __('RL-1 slips and summary'), DeadlineCategory::InformationReturn, DeadlineKind::File,
                    'Revenu Québec', __('Payroll is on and your organization is in Quebec.'),
                    'https://www.revenuquebec.ca/en/businesses/source-deductions-and-employer-contributions/filing-rl-slips-and-the-rl-1-summary-general-information/',
                    self::periods('annual', $calendarAnchor, 2, DueDayRule::LastDay));
            }
        }

        if ($region === 'BC' && ($company->usesPayroll() || $wcbProvinces->contains('BC'))) {
            $why = __('Your organization has employees in British Columbia.');

            $out[] = new SuggestedDeadline('ca.bc.worksafebc_quarterly', __('WorkSafeBC quarterly payroll report'), DeadlineCategory::WorkersComp, DeadlineKind::FileAndPay,
                'WorkSafeBC', $why,
                self::WORKSAFEBC_DATES,
                self::periods('quarterly', $calendarAnchor, 1, DueDayRule::DayOfMonth, 20),
                note: __('Quarterly reporters. Annual reporters can untick this.'));
            $out[] = new SuggestedDeadline('ca.bc.worksafebc_annual', __('WorkSafeBC annual payroll reconciliation'), DeadlineCategory::WorkersComp, DeadlineKind::File,
                'WorkSafeBC', $why,
                self::WORKSAFEBC_DATES,
                self::yearly([[2, 28]], $calendarAnchor),
                note: __('For quarterly reporters. Annual-only reporters get their own due date from WorkSafeBC (early March); change the date to match.'));
            $out[] = new SuggestedDeadline('ca.bc.eht_return', __('BC employer health tax return'), DeadlineCategory::EmployerHealthTax, DeadlineKind::FileAndPay,
                'BC Ministry of Finance', __('BC employers whose BC remuneration is over the exemption file a return.'),
                'https://www2.gov.bc.ca/gov/content/taxes/employer-health-tax/employer-health-tax-overview/eht-file-pay',
                self::yearly([[3, 31]], $calendarAnchor),
                defaultOn: false,
                portalUrl: self::BC_ETAX);
            $out[] = new SuggestedDeadline('ca.bc.eht_instalments', __('BC employer health tax instalments'), DeadlineCategory::EmployerHealthTax, DeadlineKind::Pay,
                'BC Ministry of Finance', __('Required when last year’s employer health tax was over $2,925.'),
                'https://www2.gov.bc.ca/gov/content/taxes/employer-health-tax/employer-health-tax-overview/eht-file-pay',
                self::yearly([[6, 15], [9, 15], [12, 15]], $calendarAnchor),
                defaultOn: false,
                portalUrl: self::BC_ETAX);
        }

        if ($region === 'ON' && $company->usesPayroll()) {
            $out[] = new SuggestedDeadline('ca.on.eht_return', __('Ontario employer health tax return'), DeadlineCategory::EmployerHealthTax, DeadlineKind::FileAndPay,
                'Ontario Ministry of Finance', __('Ontario employers whose payroll is over the exemption file a return.'),
                'https://www.ontario.ca/document/employer-health-tax-eht',
                self::yearly([[3, 15]], $calendarAnchor),
                defaultOn: false);
            $out[] = new SuggestedDeadline('ca.on.eht_instalments', __('Ontario employer health tax instalments'), DeadlineCategory::EmployerHealthTax, DeadlineKind::Pay,
                'Ontario Ministry of Finance', __('Required when your Ontario payroll is over $1,200,000; each instalment is due the 15th of the following month.'),
                'https://www.ontario.ca/document/employer-health-tax-eht',
                self::periods('monthly', $calendarAnchor, 1, DueDayRule::DayOfMonth, 15),
                defaultOn: false);
        }

        return $out;
    }

    /**
     * @return list<SuggestedDeadline>
     */
    private static function incomeTax(
        FilingProfile $profile,
        SuggestionChoices $choices,
        CarbonImmutable $fyAnchor,
        CarbonImmutable $calendarAnchor,
        OrganizationType $orgType,
    ): array {
        $out = [];

        if ($profile->filesGifiStatement()) {
            $why = __('Corporations file a T2 return every year.');

            $out[] = new SuggestedDeadline('ca.t2.return', __('T2 corporation income tax return'), DeadlineCategory::IncomeTaxReturn, DeadlineKind::File,
                self::CRA, $why,
                'https://www.canada.ca/en/revenue-agency/services/tax/businesses/topics/corporations/corporation-income-tax-return/when-file-your-corporation-income-tax-return.html',
                self::periods('annual', $fyAnchor, 6, DueDayRule::LastDay),
                portalUrl: self::CRA_BUSINESS_ACCOUNT);
            $out[] = new SuggestedDeadline('ca.t2.balance', __('T2 balance owing'), DeadlineCategory::IncomeTaxReturn, DeadlineKind::Pay,
                self::CRA, $why,
                'https://www.canada.ca/en/revenue-agency/services/tax/businesses/topics/corporations/corporation-payments/paying-instalments/instalment-dates.html',
                self::periods('annual', $fyAnchor, $choices->ccpcThreeMonthBalance ? 3 : 2, DueDayRule::LastDay),
                note: __('Three months for a CCPC that claimed the small business deduction and meets CRA’s conditions; two months otherwise.'),
                portalUrl: self::CRA_BUSINESS_ACCOUNT);
            $out[] = new SuggestedDeadline('ca.t2.instalments', __('Corporate income tax instalments'), DeadlineCategory::IncomeTaxInstalment, DeadlineKind::Pay,
                self::CRA, __('Required when the corporation’s tax owing is over $3,000 under CRA’s instalment rules; never in its first tax year.'),
                'https://www.canada.ca/en/revenue-agency/services/tax/businesses/topics/corporations/corporation-payments/paying-instalments/instalment-dates.html',
                self::periods($choices->instalmentMethod === 'quarterly' ? 'quarterly' : 'monthly', $fyAnchor, 0, DueDayRule::LastDay),
                defaultOn: false,
                portalUrl: self::CRA_BUSINESS_ACCOUNT);
        }

        if ($profile->filesT2125()) {
            $why = __('Sole proprietors report business income on their T1 return.');
            $source = 'https://www.canada.ca/en/revenue-agency/services/tax/individuals/topics/important-dates-individuals/filing-dates-tax-return.html';

            $out[] = new SuggestedDeadline('ca.t1.return', __('T1 income tax return (self-employed)'), DeadlineCategory::IncomeTaxReturn, DeadlineKind::File,
                self::CRA, $why, $source, self::yearly([[6, 15]], $calendarAnchor));
            $out[] = new SuggestedDeadline('ca.t1.balance', __('T1 balance owing'), DeadlineCategory::IncomeTaxReturn, DeadlineKind::Pay,
                self::CRA, $why, $source, self::yearly([[4, 30]], $calendarAnchor));
            $out[] = new SuggestedDeadline('ca.t1.instalments', __('Personal income tax instalments'), DeadlineCategory::IncomeTaxInstalment, DeadlineKind::Pay,
                self::CRA, __('Required when CRA sends instalment reminders.'),
                'https://www.canada.ca/en/revenue-agency/services/payments/payments-cra/individual-payments/income-tax-instalments/due-dates.html',
                self::yearly([[3, 15], [6, 15], [9, 15], [12, 15]], $calendarAnchor),
                defaultOn: false);
        }

        if ($profile->filesT5013()) {
            $out[] = new SuggestedDeadline('ca.t5013', __('T5013 partnership information return'), DeadlineCategory::InformationReturn, DeadlineKind::File,
                self::CRA, __('Partnerships file a T5013 information return.'),
                'https://www.canada.ca/en/revenue-agency/services/forms-publications/publications/t4068/guide-partnership-information-return-t5013-forms.html',
                $choices->partnershipMembers === 'corporations'
                    ? self::periods('annual', $fyAnchor, 5, DueDayRule::LastDay)
                    : self::yearly([[3, 31]], $calendarAnchor),
                note: __('Partnerships with both individual and corporate members: the earlier of March 31 and five months after the year end.'));
        }

        if ($profile->filesT3010()) {
            $out[] = new SuggestedDeadline('ca.t3010', __('T3010 charity information return'), DeadlineCategory::InformationReturn, DeadlineKind::File,
                self::CRA, __('Registered charities file a T3010 every year.'),
                'https://www.canada.ca/en/revenue-agency/services/charities-giving/charities/operating-a-registered-charity/filing-t3010-charity-return/when-file.html',
                self::periods('annual', $fyAnchor, 6, DueDayRule::LastDay),
                portalUrl: self::CRA_BUSINESS_ACCOUNT);
        }

        if ($profile->filesT1044()) {
            $out[] = new SuggestedDeadline('ca.t1044', __('T1044 non-profit information return'), DeadlineCategory::InformationReturn, DeadlineKind::File,
                self::CRA, __('Non-profits over CRA’s income or asset thresholds file a T1044.'),
                'https://www.canada.ca/en/revenue-agency/services/forms-publications/publications/t4117/income-tax-guide-non-profit-organization-information-return.html',
                self::periods('annual', $fyAnchor, 6, DueDayRule::LastDay),
                defaultOn: false);
        }

        if (in_array($orgType, [OrganizationType::Corporation, OrganizationType::SoleProprietorship, OrganizationType::Partnership], true)) {
            $out[] = new SuggestedDeadline('ca.t5018', __('T5018 contract payments return'), DeadlineCategory::InformationReturn, DeadlineKind::File,
                self::CRA, __('Construction businesses that pay subcontractors.'),
                'https://www.canada.ca/en/revenue-agency/services/tax/businesses/topics/payroll/file-information-returns-slip-summaries/when-to-file.html',
                self::periods('annual', $fyAnchor, 6, DueDayRule::LastDay),
                defaultOn: false);
        }

        return $out;
    }
}
