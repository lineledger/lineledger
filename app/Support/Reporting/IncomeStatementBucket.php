<?php

namespace App\Support\Reporting;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Models\Account;

/**
 * Single source of truth for which Income Statement bucket an account belongs to.
 * Shared by the report itself, the section config page, and ReportSection::accepts()
 * so they never disagree on placement.
 */
class IncomeStatementBucket
{
    /**
     * @return 'income'|'cogs'|'expense'|null
     */
    public static function for(Account $account): ?string
    {
        return self::forValues($account->type, $account->subtype);
    }

    /**
     * Bucket from a bare type/subtype pair — used for combined report lines, which
     * carry the same type/subtype enums as accounts but aren't Account models.
     *
     * @return 'income'|'cogs'|'expense'|null
     */
    public static function forValues(AccountType $type, ?AccountSubtype $subtype): ?string
    {
        return match (true) {
            $subtype === AccountSubtype::CostOfGoodsSold => 'cogs',
            $type === AccountType::Income => 'income',
            $type === AccountType::Expense => 'expense',
            default => null,
        };
    }

    /**
     * The buckets in presentation order, keyed by group_key.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'income' => 'Income',
            'cogs' => 'Cost of Goods Sold',
            'expense' => 'Expenses',
        ];
    }
}
