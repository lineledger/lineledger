<?php

namespace App\Enums;

enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Expense = 'expense';

    public function label(): string
    {
        return match ($this) {
            self::Asset => __('Asset'),
            self::Liability => __('Liability'),
            self::Equity => __('Equity'),
            self::Income => __('Income'),
            self::Expense => __('Expense'),
        };
    }

    public function normalBalance(): NormalBalance
    {
        return match ($this) {
            self::Asset, self::Expense => NormalBalance::Debit,
            self::Liability, self::Equity, self::Income => NormalBalance::Credit,
        };
    }
}
