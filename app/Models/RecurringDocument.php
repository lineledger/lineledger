<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\BillType;
use App\Enums\RecurrenceEndType;
use App\Enums\RecurrenceFrequency;
use App\Enums\RecurringAutomationMode;
use App\Enums\RecurringDocumentType;
use App\Services\Recurring\RecurringSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'company_id', 'document_type', 'automation_mode', 'contact_id', 'bill_type', 'vendor_reference',
    'terms_id', 'memo', 'name', 'frequency', 'start_date', 'day_of_month',
    'end_type', 'end_date', 'max_occurrences', 'occurrences_generated',
    'next_run_date', 'last_generated_at', 'is_active', 'paused_reason',
])]
class RecurringDocument extends Model implements RecurringSchedule
{
    use BelongsToCompany, SoftDeletes;

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<PaymentTerm, $this>
     */
    public function terms(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class, 'terms_id');
    }

    /**
     * @return HasMany<RecurringDocumentLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(RecurringDocumentLine::class)->orderBy('line_order');
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @return HasMany<Bill, $this>
     */
    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    public function isInvoice(): bool
    {
        return $this->document_type === RecurringDocumentType::Invoice;
    }

    public function isBill(): bool
    {
        return $this->document_type === RecurringDocumentType::Bill;
    }

    /**
     * Whether this schedule has reached its end and stopped generating.
     */
    public function hasEnded(): bool
    {
        return ! $this->is_active && $this->next_run_date === null && $this->paused_reason === null;
    }

    public function scopeDue(Builder $query, string $onOrBeforeDate): Builder
    {
        return $query->where('is_active', true)
            ->whereNotNull('next_run_date')
            ->whereDate('next_run_date', '<=', $onOrBeforeDate);
    }

    public function scheduleFrequency(): RecurrenceFrequency
    {
        return $this->frequency;
    }

    public function scheduleDayOfMonth(): ?int
    {
        return $this->day_of_month;
    }

    public function scheduleStartDate(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->start_date->toDateString());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_type' => RecurringDocumentType::class,
            'automation_mode' => RecurringAutomationMode::class,
            'bill_type' => BillType::class,
            'frequency' => RecurrenceFrequency::class,
            'end_type' => RecurrenceEndType::class,
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'next_run_date' => 'date:Y-m-d',
            'day_of_month' => 'integer',
            'max_occurrences' => 'integer',
            'occurrences_generated' => 'integer',
            'last_generated_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
