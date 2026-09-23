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
            self::T2 => __('T2 Corporation Income Tax Return'),
            self::T5013 => __('T5013 Partnership Information Return'),
            self::T2125 => __('T2125 Statement of Business or Professional Activities'),
            self::T3010 => __('T3010 Registered Charity Information Return'),
            self::T1044 => __('T1044 Non-Profit Organization (NPO) Information Return'),
        };
    }

    public function description(): string
    {
        // Copy follows the CRA form pages (canada.ca), so the settings screen
        // matches the agency's own description of each return.
        return match ($this) {
            self::T2 => __('This form serves as a federal, provincial, and territorial corporation income tax return, unless the corporation is located in Quebec or Alberta. If such is the case, you have to file a separate provincial corporate return.'),
            self::T5013 => __('Partnership Financial Return (T5013FIN). Reports the partnership’s financial information for the fiscal period.'),
            self::T2125 => __('Use the T2125 form to report either business or professional income and expenses.'),
            self::T3010 => __('A registered charity must complete Form T3010, Registered Charity Information Return, annually and submit it within six months of the end of its fiscal period.'),
            self::T1044 => __('For use by a non-profit organization, an agricultural organization, a board of trade or a chamber of commerce to report general information about itself and its income and expenses in a fiscal year.'),
        };
    }

    public function craReference(): string
    {
        $french = app()->getLocale() === 'fr';

        return match ($this) {
            self::T2 => $french
                ? 'https://www.canada.ca/fr/agence-revenu/services/formulaires-publications/formulaires/t2.html'
                : 'https://www.canada.ca/en/revenue-agency/services/forms-publications/forms/t2.html',
            self::T5013 => $french
                ? 'https://www.canada.ca/fr/agence-revenu/services/formulaires-publications/formulaires/t5013fin.html'
                : 'https://www.canada.ca/en/revenue-agency/services/forms-publications/forms/t5013fin.html',
            self::T2125 => $french
                ? 'https://www.canada.ca/fr/agence-revenu/services/formulaires-publications/formulaires/t2125.html'
                : 'https://www.canada.ca/en/revenue-agency/services/forms-publications/forms/t2125.html',
            self::T3010 => $french
                ? 'https://www.canada.ca/fr/agence-revenu/services/formulaires-publications/formulaires/t3010.html'
                : 'https://www.canada.ca/en/revenue-agency/services/forms-publications/forms/t3010.html',
            self::T1044 => $french
                ? 'https://www.canada.ca/fr/agence-revenu/services/formulaires-publications/formulaires/t1044.html'
                : 'https://www.canada.ca/en/revenue-agency/services/forms-publications/forms/t1044.html',
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
