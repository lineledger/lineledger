<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\StatementLineMatchStatus;
use App\Enums\StatementSuggestionSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One normalized transaction parsed from a statement file. {@see $amount_cents}
 * is a signed book-delta (positive = debit to the account = money into an asset
 * bank; negative = credit), matching the ledger's debit_cents - credit_cents.
 *
 * @property StatementLineMatchStatus $match_status
 * @property StatementSuggestionSource|null $suggestion_source
 * @property int|null $suggested_account_id
 * @property int|null $suggested_contact_id
 * @property int|null $suggested_bill_id
 * @property array<int, array{bill_id: int, amount_cents: int}>|null $suggested_bill_allocations
 * @property int|null $suggested_tax_code_id
 * @property int|null $suggested_secondary_tax_code_id
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
    'suggested_contact_id',
    'suggested_bill_id',
    'suggested_bill_allocations',
    'suggested_tax_code_id',
    'suggested_secondary_tax_code_id',
    'suggestion_source',
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

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function suggestedContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'suggested_contact_id');
    }

    /**
     * @return BelongsTo<Bill, $this>
     */
    public function suggestedBill(): BelongsTo
    {
        return $this->belongsTo(Bill::class, 'suggested_bill_id');
    }

    /**
     * @return BelongsTo<TaxCode, $this>
     */
    public function suggestedTaxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'suggested_tax_code_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<TaxCode, $this>
     */
    public function suggestedSecondaryTaxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'suggested_secondary_tax_code_id')->withoutGlobalScopes();
    }

    /**
     * The multi-bill split offered for (or chosen on) this outflow, normalised
     * to ints; empty when none. Mirrors BankReconciliation::markedLineIds().
     *
     * @return list<array{bill_id: int, amount_cents: int}>
     */
    public function suggestedBillAllocations(): array
    {
        $out = [];

        foreach ($this->suggested_bill_allocations ?? [] as $row) {
            $out[] = ['bill_id' => (int) $row['bill_id'], 'amount_cents' => (int) $row['amount_cents']];
        }

        return $out;
    }

    /**
     * @return array{0: int|null, 1: int|null} primary, secondary
     */
    public function suggestedTaxCodeIds(): array
    {
        return [
            $this->suggested_tax_code_id !== null ? (int) $this->suggested_tax_code_id : null,
            $this->suggested_secondary_tax_code_id !== null ? (int) $this->suggested_secondary_tax_code_id : null,
        ];
    }

    public function isInflow(): bool
    {
        return $this->amount_cents > 0;
    }

    public function isOutflow(): bool
    {
        return $this->amount_cents < 0;
    }

    /**
     * A pre-filled category / bill the user has not yet confirmed. Suggestions
     * are suggest-only: the line stays Unmatched until the user confirms it
     * (match_status = Created), and the committer never posts it before then.
     */
    public function hasUnconfirmedSuggestion(): bool
    {
        return $this->match_status === StatementLineMatchStatus::Unmatched
            && ($this->suggested_account_id !== null
                || $this->suggested_bill_id !== null
                || $this->suggestedBillAllocations() !== []);
    }

    /**
     * @param  Builder<BankStatementLine>  $query
     */
    public function scopeUnconfirmedSuggestions(Builder $query): void
    {
        $query->where('match_status', StatementLineMatchStatus::Unmatched->value)
            ->where(fn (Builder $q) => $q->whereNotNull('suggested_account_id')
                ->orWhereNotNull('suggested_bill_id')
                ->orWhereNotNull('suggested_bill_allocations'));
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
            'suggestion_source' => StatementSuggestionSource::class,
            'suggested_bill_allocations' => 'array',
        ];
    }
}
