<?php

namespace App\Support\Translation;

/**
 * Registers CRA GIFI section names and code labels so lang/fr.json can
 * translate them on GIFI, T2125, and T5013 reports.
 */
final class GifiCopy
{
    /**
     * @return list<string>
     */
    public static function strings(): array
    {
        return [
            // Sections
            __('Current assets'),
            __('Capital assets'),
            __('Other assets'),
            __('Current liabilities'),
            __('Long-term liabilities'),
            __('Shareholder equity'),
            __('Revenue'),
            __('Cost of sales'),
            __('Operating expenses'),

            // Codes
            __('Cash and deposits'),
            __('Accounts receivable'),
            __('Allowance for doubtful accounts'),
            __('Inventories'),
            __('Short-term investments'),
            __('Loans and notes receivable'),
            __('Due from shareholder(s)/director(s)'),
            __('Other current assets'),
            __('Prepaid expenses'),
            __('Land'),
            __('Buildings'),
            __('Machinery, equipment, furniture and fixtures'),
            __('Motor vehicles'),
            __('Computer equipment and software'),
            __('Leasehold improvements'),
            __('Accumulated amortization of tangible assets'),
            __('Long-term investments'),
            __('Goodwill'),
            __('Other long-term assets'),
            __('Bank overdraft'),
            __('Accounts payable and accrued liabilities'),
            __('Taxes payable'),
            __('Short-term debt'),
            __('Due to shareholder(s)/director(s)'),
            __('Other current liabilities'),
            __('Long-term debt'),
            __('Deferred income taxes'),
            __('Due to related parties (long-term)'),
            __('Other long-term liabilities'),
            __('Common shares'),
            __('Preferred shares'),
            __('Contributed surplus'),
            __('Retained earnings / deficit'),
            __('Trade sales of goods and services'),
            __('Investment revenue'),
            __('Rental revenue'),
            __('Other revenue'),
            __('Purchases / cost of materials'),
            __('Subcontracts'),
            __('Advertising and promotion'),
            __('Bad debt expense'),
            __('Insurance'),
            __('Interest and bank charges'),
            __('Interest on long-term debt'),
            __('Office expenses'),
            __('Professional fees'),
            __('Rental'),
            __('Repairs and maintenance'),
            __('Salaries and wages'),
            __('Supplies'),
            __('Property taxes'),
            __('Travel expenses'),
            __('Utilities'),
            __('Fuel costs'),
            __('Vehicle expenses'),
            __('Amortization of tangible assets'),
            __('Amortization of intangible assets'),
            __('Other expenses'),
        ];
    }
}
