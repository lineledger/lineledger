<?php

namespace App\Enums;

enum DataMigrationMode: string
{
    /** Standard accountant conversion: lists + balances as of a conversion date, then lock. */
    case OpeningBalance = 'opening_balance';

    /** Replays the complete transaction history from QuickBooks Desktop into the GL. */
    case FullHistory = 'full_history';

    public function label(): string
    {
        return match ($this) {
            self::OpeningBalance => 'Opening balances',
            self::FullHistory => 'Full transaction history',
        };
    }

    /**
     * The ordered step map for this mode (step number => step key).
     *
     * @return array<int, string>
     */
    public function steps(): array
    {
        return match ($this) {
            self::OpeningBalance => [
                1 => 'setup',
                2 => 'chart_of_accounts',
                3 => 'confirm_control_accounts',
                4 => 'customers',
                5 => 'vendors',
                6 => 'items',
                7 => 'open_invoices',
                8 => 'open_bills',
                9 => 'inventory_opening_balance',
                10 => 'fixed_assets',
                11 => 'trial_balance',
                12 => 'review',
            ],
            self::FullHistory => [
                1 => 'setup',
                2 => 'chart_of_accounts',
                3 => 'confirm_control_accounts',
                4 => 'customers',
                5 => 'vendors',
                6 => 'general_ledger',
                7 => 'open_invoices',
                8 => 'open_bills',
                9 => 'review',
            ],
        };
    }
}
