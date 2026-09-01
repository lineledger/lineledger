<?php

namespace App\Enums;

/**
 * The two flavours of customer-facing statement: a list of what's currently
 * owed (open invoices as of a date) or the account activity over a period.
 */
enum CustomerStatementType: string
{
    case OpenInvoices = 'open-invoices';
    case Activity = 'activity';

    public function label(): string
    {
        return match ($this) {
            self::OpenInvoices => __('Open invoices'),
            self::Activity => __('Account activity'),
        };
    }
}
