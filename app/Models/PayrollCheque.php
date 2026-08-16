<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\PayrollChequeStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'company_id', 'pay_run_id', 'pay_run_line_id', 'bank_account_id', 'cheque_no',
    'cheque_date', 'payee_contact_id', 'payee_name', 'amount_cents', 'status',
    'posted_at', 'posted_by_user_id', 'voided_at', 'voided_by_user_id', 'journal_entry_id',
])]
class PayrollCheque extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    /**
     * @return BelongsTo<PayRun, $this>
     */
    public function payRun(): BelongsTo
    {
        return $this->belongsTo(PayRun::class);
    }

    /**
     * @return BelongsTo<PayRunLine, $this>
     */
    public function payRunLine(): BelongsTo
    {
        return $this->belongsTo(PayRunLine::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function payee(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'payee_contact_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cheque_date' => 'date:Y-m-d',
            'status' => PayrollChequeStatus::class,
            'amount_cents' => 'integer',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }
}
