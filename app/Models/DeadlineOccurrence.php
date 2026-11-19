<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\DeadlineState;
use App\Enums\DeadlineStatus;
use App\Support\Deadlines\DeadlineDates;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * One dated instance of a {@see Deadline}: the period it covers, when it's due,
 * who it's assigned to and, once dealt with, who completed it, when, for how
 * much and with what confirmation number.
 *
 * `customized_at` marks an occurrence someone has edited (reassigned, moved,
 * annotated), so regenerating the series after a rule change leaves it alone.
 *
 * @property int $id
 * @property int $company_id
 * @property int $deadline_id
 * @property string $occurrence_key
 * @property CarbonImmutable|null $period_start
 * @property CarbonImmutable|null $period_end
 * @property CarbonImmutable $unadjusted_due_date
 * @property CarbonImmutable $due_date
 * @property DeadlineStatus $status
 * @property int|null $assigned_user_id
 * @property int|null $completed_by_user_id
 * @property CarbonImmutable|null $completed_on
 * @property CarbonInterface|null $completed_at
 * @property int|null $amount_cents
 * @property string|null $confirmation_number
 * @property string|null $notes
 * @property string|null $skipped_reason
 * @property CarbonInterface|null $customized_at
 * @property-read Deadline $deadline
 * @property-read User|null $assignee
 * @property-read User|null $completedBy
 */
#[Fillable([
    'deadline_id', 'occurrence_key', 'period_start', 'period_end', 'unadjusted_due_date', 'due_date',
    'status', 'assigned_user_id', 'completed_by_user_id', 'completed_on', 'completed_at',
    'amount_cents', 'confirmation_number', 'notes', 'skipped_reason', 'customized_at',
])]
class DeadlineOccurrence extends Model
{
    use BelongsToCompany, HasFactory;

    /** An open occurrence counts as "due soon" this many days ahead. */
    public const int DUE_SOON_DAYS = 7;

    protected $attributes = [
        'status' => 'open',
    ];

    /**
     * @return BelongsTo<Deadline, $this>
     */
    public function deadline(): BelongsTo
    {
        return $this->belongsTo(Deadline::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    /**
     * @param  Builder<DeadlineOccurrence>  $query
     * @return Builder<DeadlineOccurrence>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', DeadlineStatus::Open->value);
    }

    /**
     * Occurrences whose deadline hasn't been archived.
     *
     * @param  Builder<DeadlineOccurrence>  $query
     * @return Builder<DeadlineOccurrence>
     */
    public function scopeOfCurrentDeadlines(Builder $query): Builder
    {
        return $query->whereHas('deadline', fn (Builder $q) => $q->whereNull('archived_at'));
    }

    /**
     * @param  Builder<DeadlineOccurrence>  $query
     * @return Builder<DeadlineOccurrence>
     */
    public function scopeDueBetween(Builder $query, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $query->whereBetween('due_date', [$from->toDateString(), $to->toDateString()]);
    }

    public function isOpen(): bool
    {
        return $this->status === DeadlineStatus::Open;
    }

    /**
     * Where the occurrence stands on `$today` (the organization's date).
     */
    public function stateOn(CarbonImmutable $today): DeadlineState
    {
        return match ($this->status) {
            DeadlineStatus::Done => DeadlineState::Done,
            DeadlineStatus::Skipped => DeadlineState::Skipped,
            // Compared as Y-m-d strings: `$today` may carry the organization's
            // timezone while the stored date is midnight in the app's.
            DeadlineStatus::Open => match (true) {
                $this->due_date->toDateString() < $today->toDateString() => DeadlineState::Overdue,
                $this->due_date->toDateString() === $today->toDateString() => DeadlineState::DueToday,
                $this->due_date->toDateString() <= $today->addDays(self::DUE_SOON_DAYS)->toDateString() => DeadlineState::DueSoon,
                default => DeadlineState::Upcoming,
            },
        };
    }

    /**
     * A short name for the period, e.g. "Aug 2026", "Aug–Oct 2026",
     * "Jun 16 – Sep 15, 2026", or null when the occurrence has no period.
     */
    public function periodLabel(): ?string
    {
        if ($this->period_start === null || $this->period_end === null) {
            return null;
        }

        $start = $this->period_start;
        $end = $this->period_end;

        if ($start->day === 1 && $end->isSameDay($end->endOfMonth())) {
            if ($start->isSameMonth($end)) {
                return DeadlineDates::monthYear($start);
            }

            if ($start->year === $end->year) {
                return DeadlineDates::month($start).'–'.DeadlineDates::monthYear($end);
            }

            return DeadlineDates::monthYear($start).' – '.DeadlineDates::monthYear($end);
        }

        if ($start->year === $end->year) {
            return DeadlineDates::short($start).' – '.DeadlineDates::medium($end);
        }

        return DeadlineDates::medium($start).' – '.DeadlineDates::medium($end);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeadlineStatus::class,
            'period_start' => 'immutable_date:Y-m-d',
            'period_end' => 'immutable_date:Y-m-d',
            'unadjusted_due_date' => 'immutable_date:Y-m-d',
            'due_date' => 'immutable_date:Y-m-d',
            'completed_on' => 'immutable_date:Y-m-d',
            'completed_at' => 'datetime',
            'customized_at' => 'datetime',
            'amount_cents' => 'integer',
        ];
    }
}
