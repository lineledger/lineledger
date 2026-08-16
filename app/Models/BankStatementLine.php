<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\StatementLineMatchStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One normalized transaction parsed from a statement file. {@see $amount_cents}
 * is a signed book-delta (positive = debit to the account = money into an asset
 * bank; negative = credit), matching the ledger's debit_cents - credit_cents.
 */
#[Fillable([
    'company_id',
    'bank_statement_import_id',
    'account_id',
    'txn_date',
    'amount_cents',
    'description',
    'check_number',
    'reference',
    'external_id',
    'fingerprint',
    'balance_cents',
    'raw',
    'match_status',
    'match_confidence',
    'match_reason',
    'matched_journal_line_id',
    'created_journal_entry_id',
    'suggested_account_id',
])]
class BankStatementLine extends Model
{
    use BelongsToCompany, HasFactory;

    /**
     * @return BelongsTo<BankStatementImport, $this>
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(BankStatementImport::class, 'bank_statement_import_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<JournalLine, $this>
     */
    public function matchedJournalLine(): BelongsTo
    {
        return $this->belongsTo(JournalLine::class, 'matched_journal_line_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function createdJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'created_journal_entry_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function suggestedAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'suggested_account_id');
    }

    public function isInflow(): bool
    {
        return $this->amount_cents > 0;
    }

    /**
     * The standing "For review" queue across every import: lines still awaiting a
     * categorization decision — unmatched or rule-suggested, and not yet posted.
     *
     * @param  Builder<BankStatementLine>  $query
     */
    public function scopeForReview(Builder $query): void
    {
        $query->whereIn('match_status', [
            StatementLineMatchStatus::Unmatched->value,
            StatementLineMatchStatus::Suggested->value,
        ])->whereNull('created_journal_entry_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'txn_date' => 'date:Y-m-d',
            'amount_cents' => 'integer',
            'balance_cents' => 'integer',
            'raw' => 'array',
            'match_status' => StatementLineMatchStatus::class,
            'match_confidence' => 'integer',
        ];
    }
}
