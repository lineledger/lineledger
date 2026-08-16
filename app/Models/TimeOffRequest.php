<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\TimeOffRequestStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One employee's request for a stretch of time off, tied to a time-off policy
 * (whose code decides pay + balance treatment). Two-level approval; the payroll
 * confirmation generates Approved {@see TimeEntry} rows (stamped with this
 * request) that the pay-run pull consumes — the GL/balance machinery never
 * reads requests directly.
 *
 * @property int $id
 * @property int $company_id
 * @property int $contact_id
 * @property int $time_off_policy_id
 * @property CarbonInterface $start_date
 * @property CarbonInterface $end_date
 * @property string $hours_per_day
 * @property string $total_hours
 * @property ?string $employee_note
 * @property TimeOffRequestStatus $status
 * @property ?int $manager_decided_by_user_id
 * @property ?CarbonInterface $manager_decided_at
 * @property ?string $manager_note
 * @property ?int $decided_by_user_id
 * @property ?CarbonInterface $decided_at
 * @property ?string $decision_note
 * @property-read ?Contact $employee
 * @property-read ?TimeOffPolicy $policy
 */
#[Fillable([
    'company_id', 'contact_id', 'time_off_policy_id', 'start_date', 'end_date',
    'hours_per_day', 'total_hours', 'employee_note', 'status',
    'manager_decided_by_user_id', 'manager_decided_at', 'manager_note',
    'decided_by_user_id', 'decided_at', 'decision_note',
])]
class TimeOffRequest extends Model
{
    use BelongsToCompany, HasFactory;

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * @return BelongsTo<TimeOffPolicy, $this>
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(TimeOffPolicy::class, 'time_off_policy_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function managerDecidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_decided_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    /**
     * The Approved time entries the payroll confirmation generated.
     *
     * @return HasMany<TimeEntry, $this>
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /**
     * The working days (Mon–Fri) covered by the request, as Y-m-d strings —
     * the days total_hours is computed over and the days the payroll
     * confirmation generates time entries for.
     *
     * @return list<string>
     */
    public function businessDays(): array
    {
        $days = [];
        $cursor = $this->start_date->toImmutable();
        $end = $this->end_date->toImmutable();

        while ($cursor->lte($end)) {
            if (! $cursor->isWeekend()) {
                $days[] = $cursor->toDateString();
            }

            $cursor = $cursor->addDay();
        }

        return $days;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'hours_per_day' => 'decimal:2',
            'total_hours' => 'decimal:2',
            'status' => TimeOffRequestStatus::class,
            'manager_decided_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }
}
