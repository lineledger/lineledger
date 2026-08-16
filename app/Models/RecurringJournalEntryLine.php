<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int|null $fund_id
 */
#[Fillable([
    'recurring_journal_entry_id', 'company_id', 'account_id', 'debit_cents', 'credit_cents',
    'memo', 'contact_id', 'line_order', 'class_id', 'location_id', 'fund_id',
])]
class RecurringJournalEntryLine extends Model
{
    use BelongsToCompany;

    /**
     * @return BelongsTo<RecurringJournalEntry, $this>
     */
    public function recurringJournalEntry(): BelongsTo
    {
        return $this->belongsTo(RecurringJournalEntry::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<Classification, $this>
     */
    public function classification(): BelongsTo
    {
        return $this->belongsTo(Classification::class, 'class_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class)->withoutGlobalScopes();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'debit_cents' => 'integer',
            'credit_cents' => 'integer',
            'line_order' => 'integer',
        ];
    }
}
