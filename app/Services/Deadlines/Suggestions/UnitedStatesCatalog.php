<?php

namespace App\Services\Deadlines\Suggestions;

use App\Enums\DeadlineCategory;
use App\Enums\DeadlineKind;
use App\Enums\DueDayRule;
use App\Enums\OrganizationType;
use App\Enums\WeekendAdjustment;
use App\Models\Company;
use Carbon\CarbonImmutable;

/**
 * Core US federal deadlines from irs.gov, plus a state sales tax template
 * whose due day the user supplies (it varies by state).
 */
final class UnitedStatesCatalog
{
    use BuildsSchedules;

    private const string IRS = 'Internal Revenue Service';

    private const string EFTPS = 'https://www.eftps.gov/';

    /**
     * @return list<SuggestedDeadline>
     */
    public static function for(Company $company, SuggestionChoices $choices, CarbonImmutable $today): array
    {
        $fyMonth = max(1, min(12, (int) ($company->fiscal_year_start_month ?: 1)));
        $fyAnchor = self::previousFiscalYearStart($today, $fyMonth);
        $calendarAnchor = self::previousCalendarYearStart($today);
        $orgType = $company->organization_type ?? OrganizationType::Other;
        // IRS: a due date on a weekend or legal holiday moves to the next business day.
        $next = WeekendAdjustment::NextBusinessDay;
        $out = [];

        $employerWhy = __('If you have employees.');

        $out[] = new SuggestedDeadline('us.941', __('Form 941 quarterly payroll return'), DeadlineCategory::PayrollRemittance, DeadlineKind::File,
            self::IRS, $employerWhy, 'https://www.irs.gov/instructions/i941',
            self::periods('quarterly', $calendarAnchor, 1, DueDayRule::LastDay, weekend: $next),
            defaultOn: false, portalUrl: self::EFTPS);
        $out[] = new SuggestedDeadline('us.940', __('Form 940 annual FUTA return'), DeadlineCategory::PayrollRemittance, DeadlineKind::File,
            self::IRS, $employerWhy, 'https://www.irs.gov/instructions/i940',
            self::periods('annual', $calendarAnchor, 1, DueDayRule::LastDay, weekend: $next),
            defaultOn: false);
        $out[] = new SuggestedDeadline('us.w2', __('Forms W-2 and W-3'), DeadlineCategory::InformationReturn, DeadlineKind::File,
            'Social Security Administration', $employerWhy, 'https://www.irs.gov/instructions/iw2w3',
            self::periods('annual', $calendarAnchor, 1, DueDayRule::LastDay, weekend: $next),
            defaultOn: false);
        $out[] = new SuggestedDeadline('us.1099_nec', __('Form 1099-NEC'), DeadlineCategory::InformationReturn, DeadlineKind::File,
            self::IRS, __('If you paid a contractor $2,000 or more in the year ($600 for payments before 2026).'), 'https://www.irs.gov/instructions/i1099mec',
            self::periods('annual', $calendarAnchor, 1, DueDayRule::LastDay, weekend: $next));

        if ($orgType === OrganizationType::SoleProprietorship || $orgType === OrganizationType::Partnership) {
            $out[] = new SuggestedDeadline('us.1040_es', __('Estimated tax payments (1040-ES)'), DeadlineCategory::IncomeTaxInstalment, DeadlineKind::Pay,
                self::IRS, __('Owners who expect to owe $1,000 or more pay estimated tax.'), 'https://www.irs.gov/publications/p505',
                self::yearly([[4, 15], [6, 15], [9, 15], [1, 15]], $calendarAnchor, $next),
                portalUrl: self::EFTPS);
        }

        if ($orgType === OrganizationType::Partnership) {
            $out[] = new SuggestedDeadline('us.1065', __('Form 1065 partnership return'), DeadlineCategory::IncomeTaxReturn, DeadlineKind::File,
                self::IRS, __('Partnerships file Form 1065.'), 'https://www.irs.gov/instructions/i1065',
                self::periods('annual', $fyAnchor, 3, DueDayRule::DayOfMonth, 15, weekend: $next));
        }

        if ($orgType === OrganizationType::Corporation) {
            if ($choices->usCorporationType === 's_corp') {
                $out[] = new SuggestedDeadline('us.1120s', __('Form 1120-S S corporation return'), DeadlineCategory::IncomeTaxReturn, DeadlineKind::File,
                    self::IRS, __('S corporations file Form 1120-S.'), 'https://www.irs.gov/instructions/i1120s',
                    self::periods('annual', $fyAnchor, 3, DueDayRule::DayOfMonth, 15, weekend: $next));
            } else {
                $out[] = new SuggestedDeadline('us.1120', __('Form 1120 corporation return'), DeadlineCategory::IncomeTaxReturn, DeadlineKind::FileAndPay,
                    self::IRS, __('C corporations file Form 1120.'), 'https://www.irs.gov/instructions/i1120',
                    self::periods('annual', $fyAnchor, 4, DueDayRule::DayOfMonth, 15, weekend: $next),
                    note: __('A June 30 year end is due the 15th day of the third month instead.'));
                $out[] = new SuggestedDeadline('us.1120_estimates', __('Corporate estimated tax'), DeadlineCategory::IncomeTaxInstalment, DeadlineKind::Pay,
                    self::IRS, __('Corporations that expect to owe $500 or more pay estimated tax.'), 'https://www.irs.gov/instructions/i1120',
                    self::yearly(array_map(fn (int $offset): array => [self::monthAfter($fyMonth, $offset), 15], [3, 5, 8, 11]), $calendarAnchor, $next),
                    portalUrl: self::EFTPS);
            }
        }

        if ($orgType->isNonProfit()) {
            $out[] = new SuggestedDeadline('us.990', __('Form 990 information return'), DeadlineCategory::InformationReturn, DeadlineKind::File,
                self::IRS, __('Tax-exempt organizations file a Form 990 series return.'), 'https://www.irs.gov/instructions/i990',
                self::periods('annual', $fyAnchor, 5, DueDayRule::DayOfMonth, 15, weekend: $next));
        }

        $out[] = new SuggestedDeadline('us.state_sales_tax', __('State sales tax return'), DeadlineCategory::SalesTax, DeadlineKind::FileAndPay,
            __('Your state’s revenue department'), __('If you collect sales tax. The due day varies by state; enter yours.'),
            null,
            self::periods($choices->stateSalesTaxFrequency, $calendarAnchor, 1, DueDayRule::DayOfMonth, $choices->stateSalesTaxDay ?? 20),
            defaultOn: false,
            note: $choices->stateSalesTaxDay === null ? __('Set the due day above before adding this one.') : null);

        return $out;
    }
}
