<?php

namespace App\Support\Defaults;

use App\Enums\AccountSubtype;

/**
 * Default chart of accounts and lookups seeded on Canadian company creation.
 * Reasonable starter for a small Canadian service business; users edit freely
 * from there. "is_system" accounts are referenced by posting recipes and
 * cannot be deleted or have their code/subtype changed.
 */
class CanadianDefaults implements CompanyDefaults
{
    public function accounts(): array
    {
        return [
            // Assets
            ['code' => '1000', 'name' => 'Chequing', 'subtype' => AccountSubtype::Bank],
            ['code' => '1010', 'name' => 'Savings', 'subtype' => AccountSubtype::Bank],
            ['code' => '1100', 'name' => 'Accounts Receivable', 'subtype' => AccountSubtype::AccountsReceivable, 'is_system' => true],
            ['code' => '1200', 'name' => 'Undeposited Funds', 'subtype' => AccountSubtype::UndepositedFunds, 'is_system' => true],
            ['code' => '1300', 'name' => 'Prepaid Expenses', 'subtype' => AccountSubtype::CurrentAsset],
            ['code' => '1400', 'name' => 'Inventory Asset', 'subtype' => AccountSubtype::Inventory, 'is_system' => true],
            ['code' => '1500', 'name' => 'Office Equipment', 'subtype' => AccountSubtype::FixedAsset],
            ['code' => '1510', 'name' => 'Accumulated Depreciation', 'subtype' => AccountSubtype::FixedAsset],

            // Liabilities
            ['code' => '2000', 'name' => 'Accounts Payable', 'subtype' => AccountSubtype::AccountsPayable, 'is_system' => true],
            ['code' => '2100', 'name' => 'Credit Card', 'subtype' => AccountSubtype::CreditCard],
            ['code' => '2200', 'name' => 'GST/HST Payable', 'subtype' => AccountSubtype::TaxPayable, 'is_system' => true],
            ['code' => '2210', 'name' => 'PST Payable', 'subtype' => AccountSubtype::TaxPayable],
            ['code' => '2300', 'name' => 'Employee Reimbursements Payable', 'subtype' => AccountSubtype::CurrentLiability, 'is_system' => true],

            // Payroll liabilities
            ['code' => '2400', 'name' => 'CPP Payable', 'subtype' => AccountSubtype::CurrentLiability, 'is_system' => true],
            ['code' => '2410', 'name' => 'EI Payable', 'subtype' => AccountSubtype::CurrentLiability, 'is_system' => true],
            ['code' => '2420', 'name' => 'Income Tax Payable', 'subtype' => AccountSubtype::CurrentLiability, 'is_system' => true],
            ['code' => '2421', 'name' => 'Quebec Income Tax Payable', 'subtype' => AccountSubtype::CurrentLiability, 'is_system' => true],
            ['code' => '2422', 'name' => 'QPP Payable', 'subtype' => AccountSubtype::CurrentLiability, 'is_system' => true],
            ['code' => '2423', 'name' => 'QPIP Payable', 'subtype' => AccountSubtype::CurrentLiability, 'is_system' => true],
            ['code' => '2424', 'name' => 'QHSF Payable', 'subtype' => AccountSubtype::CurrentLiability, 'is_system' => true],
            ['code' => '2425', 'name' => 'CNESST Payable', 'subtype' => AccountSubtype::CurrentLiability, 'is_system' => true],
            ['code' => '2426', 'name' => "Workers' Compensation Payable", 'subtype' => AccountSubtype::CurrentLiability, 'is_system' => true],
            ['code' => '2430', 'name' => 'Vacation Payable', 'subtype' => AccountSubtype::CurrentLiability, 'is_system' => true],
            ['code' => '2435', 'name' => 'Banked Time Payable', 'subtype' => AccountSubtype::CurrentLiability, 'is_system' => true],
            ['code' => '2440', 'name' => 'Net Pay Clearing', 'subtype' => AccountSubtype::CurrentLiability, 'is_system' => true],
            ['code' => '2450', 'name' => 'RRSP Payable', 'subtype' => AccountSubtype::CurrentLiability],
            ['code' => '2460', 'name' => 'Garnishments Payable', 'subtype' => AccountSubtype::CurrentLiability],
            ['code' => '2470', 'name' => 'Employee Benefits Payable', 'subtype' => AccountSubtype::CurrentLiability],

            // Long-term liabilities
            ['code' => '2700', 'name' => 'Bank Loan', 'subtype' => AccountSubtype::LongTermLiability, 'description' => 'Principal owing on bank loans. For loan payments, split principal to this account and interest to an interest expense account.'],

            // Equity
            ['code' => '3000', 'name' => 'Opening Balance Equity', 'subtype' => AccountSubtype::Equity],
            ['code' => '3100', 'name' => 'Owner Contributions', 'subtype' => AccountSubtype::Equity],
            ['code' => '3200', 'name' => 'Owner Draws', 'subtype' => AccountSubtype::Equity],
            ['code' => '3900', 'name' => 'Retained Earnings', 'subtype' => AccountSubtype::RetainedEarnings, 'is_system' => true],

            // Income
            ['code' => '4000', 'name' => 'Sales', 'subtype' => AccountSubtype::Income],
            ['code' => '4100', 'name' => 'Services', 'subtype' => AccountSubtype::Income],
            ['code' => '4900', 'name' => 'Other Income', 'subtype' => AccountSubtype::OtherIncome],

            // Expenses
            ['code' => '5000', 'name' => 'Cost of Goods Sold', 'subtype' => AccountSubtype::CostOfGoodsSold, 'is_system' => true],
            ['code' => '6000', 'name' => 'Advertising', 'subtype' => AccountSubtype::Expense],
            ['code' => '6010', 'name' => 'Bank Charges', 'subtype' => AccountSubtype::Expense],
            ['code' => '6020', 'name' => 'Insurance', 'subtype' => AccountSubtype::Expense],
            ['code' => '6030', 'name' => 'Meals & Entertainment', 'subtype' => AccountSubtype::Expense],
            ['code' => '6040', 'name' => 'Office Supplies', 'subtype' => AccountSubtype::Expense],
            ['code' => '6050', 'name' => 'Professional Fees', 'subtype' => AccountSubtype::Expense],
            ['code' => '6060', 'name' => 'Rent', 'subtype' => AccountSubtype::Expense],
            ['code' => '6070', 'name' => 'Software & Subscriptions', 'subtype' => AccountSubtype::Expense],
            ['code' => '6080', 'name' => 'Telephone & Internet', 'subtype' => AccountSubtype::Expense],
            ['code' => '6090', 'name' => 'Travel', 'subtype' => AccountSubtype::Expense],
            ['code' => '6100', 'name' => 'Utilities', 'subtype' => AccountSubtype::Expense],

            // Payroll expenses
            ['code' => '6200', 'name' => 'Wages & Salaries Expense', 'subtype' => AccountSubtype::Expense, 'is_system' => true],
            ['code' => '6210', 'name' => 'Employer CPP/QPP Expense', 'subtype' => AccountSubtype::Expense, 'is_system' => true],
            ['code' => '6220', 'name' => 'Employer EI Expense', 'subtype' => AccountSubtype::Expense, 'is_system' => true],
            ['code' => '6221', 'name' => 'Employer QPIP Expense', 'subtype' => AccountSubtype::Expense, 'is_system' => true],
            ['code' => '6230', 'name' => 'Vacation Pay Expense', 'subtype' => AccountSubtype::Expense, 'is_system' => true],
            ['code' => '6240', 'name' => 'QHSF Expense', 'subtype' => AccountSubtype::Expense, 'is_system' => true],
            ['code' => '6250', 'name' => 'CNESST Expense', 'subtype' => AccountSubtype::Expense, 'is_system' => true],
            ['code' => '6260', 'name' => 'Employer Benefit Contributions Expense', 'subtype' => AccountSubtype::Expense, 'is_system' => true],
            ['code' => '6270', 'name' => 'Employer RPP Contributions Expense', 'subtype' => AccountSubtype::Expense, 'is_system' => true],
            ['code' => '6280', 'name' => "Workers' Compensation Expense", 'subtype' => AccountSubtype::Expense, 'is_system' => true],

            ['code' => '6900', 'name' => 'Miscellaneous Expense', 'subtype' => AccountSubtype::OtherExpense],
        ];
    }

    public function coreAccounts(): array
    {
        return [
            ['code' => '1000', 'name' => 'Chequing', 'subtype' => AccountSubtype::Bank],
            ['code' => '1100', 'name' => 'Accounts Receivable', 'subtype' => AccountSubtype::AccountsReceivable, 'is_system' => true],
            ['code' => '1200', 'name' => 'Undeposited Funds', 'subtype' => AccountSubtype::UndepositedFunds, 'is_system' => true],
            ['code' => '1400', 'name' => 'Inventory Asset', 'subtype' => AccountSubtype::Inventory, 'is_system' => true],
            ['code' => '2000', 'name' => 'Accounts Payable', 'subtype' => AccountSubtype::AccountsPayable, 'is_system' => true],
            ['code' => '2200', 'name' => 'GST/HST Payable', 'subtype' => AccountSubtype::TaxPayable, 'is_system' => true],
            ['code' => '2300', 'name' => 'Employee Reimbursements Payable', 'subtype' => AccountSubtype::CurrentLiability, 'is_system' => true],
            ['code' => '3000', 'name' => 'Opening Balance Equity', 'subtype' => AccountSubtype::Equity],
            ['code' => '3900', 'name' => 'Retained Earnings', 'subtype' => AccountSubtype::RetainedEarnings, 'is_system' => true],
            ['code' => '5000', 'name' => 'Cost of Goods Sold', 'subtype' => AccountSubtype::CostOfGoodsSold, 'is_system' => true],
        ];
    }

    public function paymentMethods(): array
    {
        return [
            ['name' => 'Cash', 'is_cheque' => false],
            ['name' => 'Cheque', 'is_cheque' => true],
            ['name' => 'E-transfer', 'is_cheque' => false],
            ['name' => 'EFT', 'is_cheque' => false],
            ['name' => 'Wire', 'is_cheque' => false],
            ['name' => 'Credit card', 'is_cheque' => false],
        ];
    }

    public function taxAgencies(): array
    {
        return [
            ['name' => 'Canada Revenue Agency'],
        ];
    }

    public function taxCodes(): array
    {
        return [
            ['code' => 'GST', 'name' => 'GST (5%)', 'rate_basis_points' => 500, 'recoverable' => true],
            ['code' => 'HST-ON', 'name' => 'HST Ontario (13%)', 'rate_basis_points' => 1300, 'recoverable' => true],
            ['code' => 'HST-NS', 'name' => 'HST Nova Scotia (15%)', 'rate_basis_points' => 1500, 'recoverable' => true],
            ['code' => 'ZR', 'name' => 'Zero-rated (0%)', 'rate_basis_points' => 0, 'recoverable' => true],
            ['code' => 'EX', 'name' => 'Exempt', 'rate_basis_points' => 0, 'recoverable' => false],
        ];
    }
}
