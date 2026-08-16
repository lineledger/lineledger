<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\ReportStatement;
use App\Support\Reporting\CashFlowBucket;
use App\Support\Reporting\IncomeStatementBucket;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user-defined display grouping on the Income Statement or Balance Sheet.
 * Accounts are assigned to a section to be rendered together under a sub-header
 * with a subtotal. Sections never post to the GL and never change a report's
 * grand totals — they only regroup accounts within their anchor.
 *
 * `group_key` anchors the section within its statement:
 *   - Balance Sheet: an AccountSubtype value (e.g. 'current_liability')
 *   - Income Statement: a bucket literal ('income' | 'cogs' | 'expense')
 */
#[Fillable([
    'company_id',
    'statement',
    'group_key',
    'name',
    'sort_order',
])]
class ReportSection extends Model
{
    use BelongsToCompany;

    /**
     * @return HasMany<Account, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class, 'report_section_id');
    }

    /**
     * Whether this section is a valid home for the given account: the account's
     * current anchor (subtype for the balance sheet, bucket for the income
     * statement) must match this section's group_key.
     */
    public function accepts(Account $account): bool
    {
        return match ($this->statement) {
            ReportStatement::BalanceSheet => $account->subtype->value === $this->group_key,
            ReportStatement::IncomeStatement => IncomeStatementBucket::for($account) === $this->group_key,
            ReportStatement::CashFlow => CashFlowBucket::for($account) === $this->group_key,
        };
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'statement' => ReportStatement::class,
            'sort_order' => 'integer',
        ];
    }
}
