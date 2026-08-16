<?php

namespace App\Enums;

enum AccountSubtype: string
{
    // Assets
    case Bank = 'bank';
    case AccountsReceivable = 'accounts_receivable';
    case UndepositedFunds = 'undeposited_funds';
    case Inventory = 'inventory';
    case CurrentAsset = 'current_asset';
    case FixedAsset = 'fixed_asset';
    case OtherAsset = 'other_asset';

    // Liabilities
    case AccountsPayable = 'accounts_payable';
    case CreditCard = 'credit_card';
    case TaxPayable = 'tax_payable';
    case CurrentLiability = 'current_liability';
    case LongTermLiability = 'long_term_liability';
    case OtherLiability = 'other_liability';

    // Equity
    case Equity = 'equity';
    case RetainedEarnings = 'retained_earnings';
    case UnrestrictedNetAssets = 'unrestricted_net_assets';
    case RestrictedNetAssets = 'restricted_net_assets';
    case EndowmentNetAssets = 'endowment_net_assets';

    // Income
    case Income = 'income';
    case OtherIncome = 'other_income';

    // Expenses
    case CostOfGoodsSold = 'cost_of_goods_sold';
    case Expense = 'expense';
    case OtherExpense = 'other_expense';

    public function type(): AccountType
    {
        return match ($this) {
            self::Bank,
            self::AccountsReceivable,
            self::UndepositedFunds,
            self::Inventory,
            self::CurrentAsset,
            self::FixedAsset,
            self::OtherAsset => AccountType::Asset,
            self::AccountsPayable,
            self::CreditCard,
            self::TaxPayable,
            self::CurrentLiability,
            self::LongTermLiability,
            self::OtherLiability => AccountType::Liability,
            self::Equity,
            self::RetainedEarnings,
            self::UnrestrictedNetAssets,
            self::RestrictedNetAssets,
            self::EndowmentNetAssets => AccountType::Equity,
            self::Income,
            self::OtherIncome => AccountType::Income,
            self::CostOfGoodsSold,
            self::Expense,
            self::OtherExpense => AccountType::Expense,
        };
    }

    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }

    /**
     * Map a QuickBooks account type to the closest LineLedger subtype. Accepts both
     * the report labels ("Other Current Asset") and the IIF type codes ("OCASSET").
     * Falls back to OtherAsset for anything unrecognised.
     */
    public static function fromQuickBooksType(string $type): self
    {
        return match (strtolower(trim($type))) {
            'bank' => self::Bank,
            'accounts receivable', 'ar' => self::AccountsReceivable,
            'other current asset', 'ocasset' => self::CurrentAsset,
            'fixed asset', 'fixasset' => self::FixedAsset,
            'other asset', 'oasset' => self::OtherAsset,
            'accounts payable', 'ap' => self::AccountsPayable,
            'credit card', 'ccard' => self::CreditCard,
            'other current liability', 'ocliab' => self::CurrentLiability,
            'long term liability', 'ltliab' => self::LongTermLiability,
            'equity' => self::Equity,
            'income', 'inc' => self::Income,
            'other income', 'exinc' => self::OtherIncome,
            'cost of goods sold', 'cogs' => self::CostOfGoodsSold,
            'expense', 'exp' => self::Expense,
            'other expense', 'exexp' => self::OtherExpense,
            default => self::OtherAsset,
        };
    }
}
