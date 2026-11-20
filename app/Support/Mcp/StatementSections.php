<?php

namespace App\Support\Mcp;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\StatementSection;
use App\Models\BillLine;
use App\Models\InvoiceLine;

/**
 * Decides which statement section a document line falls in. A line posted to
 * a tax-payable account is tax charged as its own line. Any other line posted
 * to a liability account is a disbursement: the money passes through to
 * someone else (a pass-through cost recorded against a clearing liability)
 * rather than being earned. Everything else is the organization's own
 * services and goods.
 */
final class StatementSections
{
    public static function for(InvoiceLine|BillLine $line): StatementSection
    {
        // getAttribute() rather than ->type: the cast makes it an AccountType at
        // runtime, but Larastan reads the column as a string (see the Account
        // entries in phpstan-baseline.neon), which would flag the comparison.
        $type = $line->account?->getAttribute('type');
        $subtype = $line->account?->getAttribute('subtype');

        return match (true) {
            $subtype === AccountSubtype::TaxPayable => StatementSection::Tax,
            $type === AccountType::Liability => StatementSection::Disbursement,
            default => StatementSection::Services,
        };
    }
}
