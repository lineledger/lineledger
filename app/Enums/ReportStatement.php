<?php

namespace App\Enums;

enum ReportStatement: string
{
    case IncomeStatement = 'income_statement';
    case BalanceSheet = 'balance_sheet';
    case CashFlow = 'cash_flow';

    public function label(): string
    {
        return match ($this) {
            self::IncomeStatement => 'Income Statement',
            self::BalanceSheet => 'Balance Sheet',
            self::CashFlow => 'Cash Flow Statement',
        };
    }
}
