<?php

namespace App\Services\Deadlines\Suggestions;

use App\Enums\DeadlineCategory;
use App\Enums\DeadlineKind;
use App\Enums\DueDayRule;
use App\Enums\OrganizationType;
use App\Models\Company;
use Carbon\CarbonImmutable;

/**
 * UK filing and payment deadlines from GOV.UK (HMRC and Companies House).
 */
final class UnitedKingdomCatalog
{
    use BuildsSchedules;

    private const string HMRC = 'HM Revenue & Customs';

    /**
     * @return list<SuggestedDeadline>
     */
    public static function for(Company $company, SuggestionChoices $choices, CarbonImmutable $today): array
    {
        $fyMonth = max(1, min(12, (int) ($company->fiscal_year_start_month ?: 4)));
        $fyAnchor = self::previousFiscalYearStart($today, $fyMonth);
        $calendarAnchor = self::previousCalendarYearStart($today);
        $orgType = $company->organization_type ?? OrganizationType::Other;
        $out = [];

        if (filled($company->tax_number)) {
            // Stagger 1 periods end Mar/Jun/Sep/Dec, 2 end Apr/Jul/Oct/Jan, 3 end May/Aug/Nov/Feb.
            $firstMonth = match ($choices->vatStagger) {
                '2' => 2,
                '3' => 3,
                default => 1,
            };

            $out[] = new SuggestedDeadline('gb.vat', __('VAT return and payment'), DeadlineCategory::SalesTax, DeadlineKind::FileAndPay,
                self::HMRC, __('Your organization has a VAT number.'),
                'https://www.gov.uk/submit-vat-return',
                self::periods('quarterly', CarbonImmutable::create($today->year - 1, $firstMonth, 1), 1, DueDayRule::LastDay, plusDays: 7),
                note: __('Pick the quarter your VAT periods end in. Annual accounting scheme users should edit the schedule.'));
        }

        $out[] = new SuggestedDeadline('gb.paye', __('PAYE and National Insurance'), DeadlineCategory::PayrollRemittance, DeadlineKind::Pay,
            self::HMRC, __('Employers pay HMRC every tax month.'),
            'https://www.gov.uk/pay-paye-tax',
            self::periods('monthly', CarbonImmutable::create($today->year - 1, 4, 6), 0, DueDayRule::DayOfMonth, 22),
            defaultOn: false,
            note: __('Due the 22nd when you pay electronically (the 19th by post). Small employers paying quarterly should change the schedule.'));

        if ($orgType === OrganizationType::Corporation || $orgType === OrganizationType::NonProfit || $orgType === OrganizationType::Charity) {
            $why = __('Limited companies pay Corporation Tax and file accounts.');

            if ($orgType === OrganizationType::Corporation) {
                $out[] = new SuggestedDeadline('gb.ct.payment', __('Corporation Tax payment'), DeadlineCategory::IncomeTaxReturn, DeadlineKind::Pay,
                    self::HMRC, $why,
                    'https://www.gov.uk/pay-corporation-tax',
                    self::periods('annual', $fyAnchor, 9, DueDayRule::SameDay, plusDays: 1),
                    note: __('Companies with profits over £1.5 million pay in instalments instead.'));
                $out[] = new SuggestedDeadline('gb.ct.return', __('Company Tax Return (CT600)'), DeadlineCategory::IncomeTaxReturn, DeadlineKind::File,
                    self::HMRC, $why,
                    'https://www.gov.uk/company-tax-returns',
                    self::periods('annual', $fyAnchor, 12, DueDayRule::SameDay));
            }

            $out[] = new SuggestedDeadline('gb.companies_house.accounts', __('Companies House annual accounts'), DeadlineCategory::CorporateFiling, DeadlineKind::File,
                'Companies House', __('Companies file accounts with Companies House every year.'),
                'https://www.gov.uk/prepare-file-annual-accounts-for-limited-company',
                self::periods('annual', $fyAnchor, 9, DueDayRule::SameDay),
                defaultOn: $orgType === OrganizationType::Corporation,
                note: __('Private companies, after their first accounts.'));

            if ($choices->confirmationStatementDate !== null) {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $choices->confirmationStatementDate);

                $out[] = new SuggestedDeadline('gb.companies_house.confirmation', __('Companies House confirmation statement'), DeadlineCategory::CorporateFiling, DeadlineKind::File,
                    'Companies House', __('Every company files a confirmation statement at least once every 12 months.'),
                    'https://www.gov.uk/running-a-limited-company/confirmation-statement',
                    self::yearly([[(int) $date->month, (int) $date->day]], $calendarAnchor));
            }
        }

        if ($orgType === OrganizationType::SoleProprietorship || $orgType === OrganizationType::Partnership) {
            $why = __('Sole traders and partners file a Self Assessment return.');

            $out[] = new SuggestedDeadline('gb.self_assessment.return', __('Self Assessment return and payment'), DeadlineCategory::IncomeTaxReturn, DeadlineKind::FileAndPay,
                self::HMRC, $why,
                'https://www.gov.uk/self-assessment-tax-returns/deadlines',
                self::yearly([[1, 31]], $calendarAnchor),
                note: __('Online returns. Paper returns are due 31 October.'));
            $out[] = new SuggestedDeadline('gb.self_assessment.payment_on_account', __('Self Assessment second payment on account'), DeadlineCategory::IncomeTaxInstalment, DeadlineKind::Pay,
                self::HMRC, $why,
                'https://www.gov.uk/understand-self-assessment-bill/payments-on-account',
                self::yearly([[7, 31]], $calendarAnchor),
                defaultOn: false);
        }

        return $out;
    }
}
