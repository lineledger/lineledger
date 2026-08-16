<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'journal_entry_template_id', 'company_id', 'account_id', 'debit_cents', 'credit_cents',
    'memo', 'tax_code_id', 'class_id', 'location_id', 'fund_id', 'line_order',
])]
class JournalEntryTemplateLine extends Model
{
    use BelongsToCompany;

    /**
     * @return BelongsTo<JournalEntryTemplate, $this>
     */
    public function journalEntryTemplate(): BelongsTo
    {
        return $this->belongsTo(JournalEntryTemplate::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<TaxCode, $this>
     */
    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class)->withoutGlobalScopes();
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
