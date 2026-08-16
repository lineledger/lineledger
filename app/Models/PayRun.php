<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\PayRunStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property PayRunStatus $status
 */
#[Fillable([
    'company_id', 'payroll_schedule_id', 'run_no', 'period_start_date',
    'period_end_date', 'pay_date', 'bank_account_id', 'status',
    'gross_cents', 'total_deductions_cents', 'total_employer_cost_cents', 'net_cents',
    'posted_at', 'posted_by_user_id', 'voided_at', 'voided_by_user_id', 'journal_entry_id',
])]
class PayRun extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    /**
     * @return HasMany<PayRunLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PayRunLine::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<PayrollSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(PayrollSchedule::class, 'payroll_schedule_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return HasMany<PayrollCheque, $this>
     */
    public function cheques(): HasMany
    {
        return $this->hasMany(PayrollCheque::class);
    }

    public function isPosted(): bool
    {
        return $this->status->isPosted();
    }

    /**
     * Recompute the header roll-ups from the current pay-run lines.
     */
    public function recalculateTotals(): void
    {
        $this->loadMissing('lines');

        $this->forceFill([
            'gross_cents' => (int) $this->lines->sum('gross_cents'),
            'total_deductions_cents' => (int) $this->lines->sum('total_deductions_cents'),
            'net_cents' => (int) $this->lines->sum('net_cents'),
            'total_employer_cost_cents' => (int) $this->lines->sum(fn (PayRunLine $line) => $line->employerContributionsCents() + $line->vacation_accrued_cents),
        ])->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start_date' => 'date:Y-m-d',
            'period_end_date' => 'date:Y-m-d',
            'pay_date' => 'date:Y-m-d',
            'status' => PayRunStatus::class,
            'gross_cents' => 'integer',
            'total_deductions_cents' => 'integer',
            'total_employer_cost_cents' => 'integer',
            'net_cents' => 'integer',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }
}
