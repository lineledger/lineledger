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

            // Codes (CRA RC4088 names, in catalog order)
            __('Cash and deposits'),
            __('Cash'),
            __('Accounts receivable'),
            __('Allowance for doubtful accounts'),
            __('Interest receivable'),
            __('Inventories'),
            __('Short-term investments'),
            __('Loans and notes receivable'),
            __('Due from shareholder(s)/director(s)'),
            __('Other current assets'),
            __('Prepaid expenses'),
            __('Land'),
            __('Buildings'),
            __('Accumulated amortization of buildings'),
            __('Machinery, equipment, furniture, and fixtures'),
            __('Accumulated amortization of machinery, equipment, furniture, and fixtures'),
            __('Motor vehicles'),
            __('Accumulated amortization of motor vehicles'),
            __('Computer equipment/software'),
            __('Accumulated amortization of computer equipment/software'),
            __('Leasehold improvements'),
            __('Accumulated amortization of leasehold improvements'),
            __('Intangible assets'),
            __('Goodwill'),
            __('Long-term investments'),
            __('Long-term loans'),
            __('Other long-term assets'),
            __('Bank overdraft'),
            __('Amounts payable and accrued liabilities'),
            __('Taxes payable'),
            __('Short-term debt'),
            __('Due to shareholder(s)/director(s)'),
            __('Other current liabilities'),
            __('Long-term debt'),
            __('Deferred income'),
            __('Future (deferred) income taxes (corporations only)'),
            __('Due to related parties'),
            __('Other long-term liabilities'),
            __('Common shares'),
            __('Preferred shares'),
            __('Contributed and other surplus'),
            __('Retained earnings/deficit'),
            __('Trade sales of goods and services'),
            __('Investment revenue'),
            __('Rental revenue'),
            __('Realized gains/losses on disposal of assets'),
            __('Other revenue'),
            __('Purchases/cost of materials'),
            __('Trades and sub-contracts'),
            __('Freight-in and duty'),
            __('Cost of sales'),
            __('Advertising and promotion'),
            __('Amortization of intangible assets'),
            __('Bad debt expense'),
            __('Employee benefits'),
            __('Amortization of tangible assets'),
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
            __('Other expenses'),
            __('Vehicle expenses'),
        ];
    }
}
