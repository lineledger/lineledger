<?php

namespace App\Models;

use App\Enums\ReportStatement;
use App\Support\Reporting\CashFlowBucket;
use App\Support\Reporting\IncomeStatementBucket;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user-defined display grouping on a combined report group's Income Statement
 * or Balance Sheet. Combined lines are assigned to a section to render together
 * under a sub-header with a subtotal. Sections never change a report's grand
 * totals — they only regroup lines within their anchor.
 *
 * `group_key` anchors the section within its statement:
 *   - Balance Sheet: the line's AccountSubtype value, or its AccountType value
 *     when the line has no subtype (matches CombinedReportCalculator's bucketing)
 *   - Income Statement: a bucket literal ('income' | 'cogs' | 'expense')
 *   - Cash Flow: an activity literal ('operating' | 'investing' | 'financing'),
 *     honoring the line's own cash_flow_activity override
 *
 * Scoped to a ReportGroup (user-owned), not to a company.
 */
#[Fillable([
    'report_group_id',
    'statement',
    'group_key',
    'name',
    'sort_order',
])]
class ReportGroupSection extends Model
{
    /**
     * @return BelongsTo<ReportGroup, $this>
     */
    public function reportGroup(): BelongsTo
    {
        return $this->belongsTo(ReportGroup::class);
    }

    /**
     * @return HasMany<ReportGroupLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ReportGroupLine::class, 'report_group_section_id');
    }

    /**
     * Whether this section is a valid home for the given line: the line's current
     * anchor (subtype/type for the balance sheet, bucket for the income statement)
     * must match this section's group_key.
     */
    public function accepts(ReportGroupLine $line): bool
    {
        return match ($this->statement) {
            ReportStatement::BalanceSheet => ($line->subtype?->value ?? $line->type->value) === $this->group_key,
            ReportStatement::IncomeStatement => IncomeStatementBucket::forValues($line->type, $line->subtype) === $this->group_key,
            ReportStatement::CashFlow => CashFlowBucket::forLine($line) === $this->group_key,
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
