<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\RemittanceAgency;
use App\Enums\RemittanceFrequency;
use App\Enums\RemittanceStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recorded source-deduction remittance for one agency + period: a snapshot of
 * the amounts remitted and a link to the balanced journal entry that cleared the
 * statutory payables against the bank. Mirrors {@see TaxReturnPayment} for sales tax.
 *
 * @property RemittanceAgency $agency
 * @property RemittanceFrequency $frequency
 * @property RemittanceStatus $status
 * @property int $total_cents
 * @property array<string, int> $breakdown
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property CarbonImmutable $due_date
 * @property CarbonImmutable $payment_date
 */
#[Fillable([
    'company_id', 'agency', 'frequency', 'period_start', 'period_end', 'due_date',
    'status', 'total_cents', 'breakdown', 'bank_account_id', 'payment_date',
    'reference', 'notes', 'journal_entry_id', 'posted_at', 'posted_by_user_id',
    'voided_at', 'voided_by_user_id',
])]
class PayrollRemittance extends Model
{
    use BelongsToCompany, HasFactory;

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'agency' => RemittanceAgency::class,
            'frequency' => RemittanceFrequency::class,
            'status' => RemittanceStatus::class,
            'period_start' => 'immutable_date:Y-m-d',
            'period_end' => 'immutable_date:Y-m-d',
            'due_date' => 'immutable_date:Y-m-d',
            'payment_date' => 'immutable_date:Y-m-d',
            'total_cents' => 'integer',
            'breakdown' => 'array',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }
}
