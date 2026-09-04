<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\RecurrenceDayAnchor;
use App\Enums\RecurrenceEndType;
use App\Enums\RecurrenceFrequency;
use App\Services\Recurring\RecurringSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A memorized journal entry: a balanced debit/credit template plus a schedule that
 * generates Draft journal entries (never posts). Sibling of {@see RecurringDocument},
 * but JE lines are debit/credit and need no contact, so it has its own model.
 */
#[Fillable([
    'company_id', 'name', 'memo', 'frequency', 'start_date', 'day_of_month', 'day_anchor',
    'end_type', 'end_date', 'max_occurrences', 'occurrences_generated',
    'next_run_date', 'last_generated_at', 'is_active', 'paused_reason',
])]
class RecurringJournalEntry extends Model implements RecurringSchedule
{
    use BelongsToCompany, SoftDeletes;

    /**
     * @return HasMany<RecurringJournalEntryLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(RecurringJournalEntryLine::class)->orderBy('line_order');
    }

    /**
     * @return HasMany<JournalEntry, $this>
     */
    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
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

    public function scheduleDayAnchor(): RecurrenceDayAnchor
    {
        return $this->day_anchor ?? RecurrenceDayAnchor::DayOfMonth;
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
            'frequency' => RecurrenceFrequency::class,
            'end_type' => RecurrenceEndType::class,
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'next_run_date' => 'date:Y-m-d',
            'day_of_month' => 'integer',
            'day_anchor' => RecurrenceDayAnchor::class,
            'max_occurrences' => 'integer',
            'occurrences_generated' => 'integer',
            'last_generated_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
