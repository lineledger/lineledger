<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\RecurrenceDayAnchor;
use App\Enums\RecurrenceEndType;
use App\Enums\RecurrenceFrequency;
use App\Notifications\Reports\ReportEmailNotification;
use App\Services\Recurring\RecurringSchedule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recurring email delivery of a memorized report or memorized report group
 * (QBO "Set email schedule"). Exactly one of memorized_report_id /
 * memorized_report_group_id is set — enforced in app code, not the schema.
 * Per user + company, like the memorized reports it targets.
 *
 * Group schedules currently send one {@see ReportEmailNotification}
 * per renderable member report in the same run. Follow-up: bundle the group's
 * attachments into a single email.
 *
 * Unlike document generation, missed runs are never caught up one-by-one — a
 * stale report resent N times is noise. The sender emails once and fast-forwards
 * next_run_date past today.
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property ?int $memorized_report_id
 * @property ?int $memorized_report_group_id
 * @property array<int, string> $recipients
 * @property ?string $subject
 * @property ?string $body
 * @property bool $attach_xlsx
 * @property RecurrenceFrequency $frequency
 * @property CarbonInterface $start_date
 * @property ?int $day_of_month
 * @property ?RecurrenceDayAnchor $day_anchor
 * @property RecurrenceEndType $end_type
 * @property ?CarbonInterface $end_date
 * @property ?int $max_occurrences
 * @property int $occurrences_generated
 * @property ?CarbonInterface $next_run_date
 * @property ?CarbonInterface $last_sent_at
 * @property bool $is_active
 * @property ?string $paused_reason
 */
#[Fillable([
    'company_id', 'user_id', 'memorized_report_id', 'memorized_report_group_id',
    'recipients', 'subject', 'body', 'attach_xlsx',
    'frequency', 'start_date', 'day_of_month', 'day_anchor',
    'end_type', 'end_date', 'max_occurrences', 'occurrences_generated',
    'next_run_date', 'last_sent_at', 'is_active', 'paused_reason',
])]
class ReportEmailSchedule extends Model implements RecurringSchedule
{
    use BelongsToCompany;

    /**
     * @return BelongsTo<MemorizedReport, $this>
     */
    public function memorizedReport(): BelongsTo
    {
        return $this->belongsTo(MemorizedReport::class);
    }

    /**
     * @return BelongsTo<MemorizedReportGroup, $this>
     */
    public function memorizedReportGroup(): BelongsTo
    {
        return $this->belongsTo(MemorizedReportGroup::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
            'recipients' => 'array',
            'attach_xlsx' => 'boolean',
            'frequency' => RecurrenceFrequency::class,
            'end_type' => RecurrenceEndType::class,
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'next_run_date' => 'date:Y-m-d',
            'day_of_month' => 'integer',
            'day_anchor' => RecurrenceDayAnchor::class,
            'max_occurrences' => 'integer',
            'occurrences_generated' => 'integer',
            'last_sent_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
