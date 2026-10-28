<?php

namespace App\Support\Translation;

use App\Support\Statutory\StatutoryAccountsProfile;
use App\Support\Statutory\UkStatutoryCatalog;

/**
 * Registers the UK statutory accounts catalog — its lines, the statements
 * they are grouped under, the frameworks and the regimes — so lang/fr.json can
 * translate them on the report page's pickers. The accounts documents
 * themselves stay in English (see KeyExtractor::ENGLISH_ONLY).
 *
 * Kept in step with {@see UkStatutoryCatalog} and
 * {@see StatutoryAccountsProfile} by the translation catalog tests.
 */
final class StatutoryCopy
{
    /**
     * @return list<string>
     */
    public static function strings(): array
    {
        return [
            // Statements (the mapping picker's groups)
            __('Statement of Financial Position'),
            __('Statement of Comprehensive Income'),

            // Lines
            __('Intangible assets'),
            __('Tangible fixed assets — cost'),
            __('Tangible fixed assets — depreciation'),
            __('Investments'),
            __('Stocks'),
            __('Debtors'),
            __('Cash at bank and in hand'),
            __('Creditors: amounts falling due within one year'),
            __('Creditors: amounts falling due after more than one year'),
            __('Provisions for liabilities'),
            __('Called up share capital'),
            __('Share premium account'),
            __('Revaluation reserve'),
            __('Other reserves'),
            __('Retained earnings'),
            __('Turnover'),
            __('Cost of sales'),
            __('Other operating income'),
            __('Staff costs'),
            __('Depreciation'),
            __('Administrative expenses'),
            __('Interest receivable and similar income'),
            __('Interest payable and similar expenses'),
            __('Tax on profit'),
            __('Dividends'),
            __('Not shown in the statutory accounts'),

            // Frameworks
            __('FRS 102 Section 1A (small entities)'),
            __('FRS 105 (micro-entities)'),

            // Regimes
            __('UK — Companies Act 2006'),
            __('Isle of Man — Companies Acts 1931 to 2004'),
            __('Isle of Man — Companies Act 2006'),
        ];
    }
}
