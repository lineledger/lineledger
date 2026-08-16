<?php

namespace App\Enums;

/**
 * Which CRA GIFI schedule a code belongs to. Drives how a mapped account's
 * amount is computed on the GIFI Statement (balance-sheet codes carry a
 * cumulative as-of balance; income-statement codes carry period activity) and
 * which half of the report it renders in.
 */
enum GifiStatement: string
{
    case BalanceSheet = 'balance_sheet';
    case IncomeStatement = 'income_statement';

    public function label(): string
    {
        return match ($this) {
            self::BalanceSheet => 'Balance Sheet (Schedule 100)',
            self::IncomeStatement => 'Income Statement (Schedule 125)',
        };
    }
}
