<?php

namespace App\Enums;

use App\Support\Tax\FilingProfile;

/**
 * The CRA returns a Canadian company may file. Which form applies is derived
 * from the company's organization type and legal tier — see
 * {@see FilingProfile}. Each form points at the in-app report
 * that gathers its financial figures (or null for information-only returns the
 * app does not yet generate, like the T1044).
 */
enum TaxForm: string
{
    case T2 = 't2';
    case T5013 = 't5013';
    case T2125 = 't2125';
    case T3010 = 't3010';
    case T1044 = 't1044';

    public function code(): string
    {
        return match ($this) {
            self::T2 => 'T2',
            self::T5013 => 'T5013',
            self::T2125 => 'T2125',
            self::T3010 => 'T3010',
            self::T1044 => 'T1044',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::T2 => 'T2 Corporation Income Tax Return',
            self::T5013 => 'T5013 Partnership Information Return',
            self::T2125 => 'T2125 Statement of Business or Professional Activities',
            self::T3010 => 'T3010 Registered Charity Information Return',
            self::T1044 => 'T1044 Non-Profit Organization (NPO) Information Return',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::T2 => 'Annual corporate return. Financial statements are filed as GIFI Schedules 100 (balance sheet) and 125 (income statement).',
            self::T5013 => 'Partnership information return. Financial statements use the same GIFI Schedules 100/125, plus a partner income allocation.',
            self::T2125 => 'Business/professional income reported on the partner or proprietor’s personal T1, including a capital cost allowance (CCA) schedule.',
            self::T3010 => 'Annual return for registered charities, reporting receipted donations, revenue, expenditures, and balance-sheet totals.',
            self::T1044 => 'Information return required of many non-profit organizations meeting CRA income or asset thresholds.',
        };
    }

    public function craReference(): string
    {
        return match ($this) {
            self::T2 => 'https://www.canada.ca/en/revenue-agency/services/forms-publications/forms/t2.html',
            self::T5013 => 'https://www.canada.ca/en/revenue-agency/services/forms-publications/forms/t5013fin.html',
            self::T2125 => 'https://www.canada.ca/en/revenue-agency/services/forms-publications/forms/t2125.html',
            self::T3010 => 'https://www.canada.ca/en/revenue-agency/services/forms-publications/forms/t3010.html',
            self::T1044 => 'https://www.canada.ca/en/revenue-agency/services/forms-publications/forms/t1044.html',
        };
    }

    /**
     * The route name of the in-app report that gathers this form's figures, or
     * null for information-only returns the app does not generate.
     */
    public function reportRoute(): ?string
    {
        return match ($this) {
            self::T2 => 'reports.gifi',
            self::T5013 => 'reports.t5013',
            self::T2125 => 'reports.t2125',
            self::T3010 => 'reports.t3010',
            self::T1044 => null,
        };
    }
}
