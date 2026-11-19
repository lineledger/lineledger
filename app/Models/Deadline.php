<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\DeadlineCategory;
use App\Enums\DeadlineFrequency;
use App\Enums\DeadlineKind;
use App\Enums\DueDayRule;
use App\Enums\RecurrenceEndType;
use App\Enums\WeekendAdjustment;
use App\Services\Deadlines\DeadlineOccurrenceSync;
use App\Services\Deadlines\DeadlineRule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One filing or payment obligation an organization keeps track of (a GST/HST
 * return, a source-deduction remittance, a licence renewal), with the rule
 * that works out each period and its due date. The dated instances live in
 * {@see DeadlineOccurrence}; {@see DeadlineOccurrenceSync}
 * keeps them in step with the rule.
 *
 * @property int $id
 * @property int $company_id
 * @property string $title
 * @property DeadlineCategory $category
 * @property DeadlineKind $kind
 * @property string|null $agency_name
 * @property int|null $tax_agency_id
 * @property string|null $account_reference
 * @property string|null $portal_url
 * @property string|null $instructions
 * @property int|null $expected_amount_cents
 * @property int|null $assigned_user_id
 * @property list<int>|null $notify_days_before
 * @property DeadlineFrequency $frequency
 * @property int $interval
 * @property CarbonImmutable|null $period_anchor
 * @property CarbonImmutable|null $due_date
 * @property CarbonImmutable|null $track_from
 * @property list<array{month: int, day: int|string}>|null $annual_dates
 * @property int $due_months_after
 * @property DueDayRule $due_day_rule
 * @property int|null $due_day_of_month
 * @property int $due_days_after
 * @property WeekendAdjustment $weekend_adjustment
 * @property RecurrenceEndType $end_type
 * @property CarbonImmutable|null $end_date
 * @property int|null $max_occurrences
 * @property bool $is_active
 * @property CarbonInterface|null $archived_at
 * @property-read User|null $assignee
 * @property string|null $template_key
 * @property int|null $created_by_user_id
 */
#[Fillable([
    'title', 'category', 'kind', 'agency_name', 'tax_agency_id', 'account_reference', 'portal_url',
    'instructions', 'expected_amount_cents', 'assigned_user_id', 'notify_days_before',
    'frequency', 'interval', 'period_anchor', 'due_date', 'track_from', 'annual_dates', 'due_months_after',
    'due_day_rule', 'due_day_of_month', 'due_days_after', 'weekend_adjustment', 'end_type',
    'end_date', 'max_occurrences', 'is_active', 'archived_at', 'template_key', 'created_by_user_id',
])]
class Deadline extends Model
{
    use BelongsToCompany, HasFactory;

    /** Lead days used when a deadline doesn't set its own. */
    public const array DEFAULT_NOTIFY_DAYS = [7, 1];

    protected $attributes = [
        'interval' => 1,
        'due_months_after' => 0,
        'due_day_rule' => 'same_day',
        'due_days_after' => 0,
        'weekend_adjustment' => 'keep',
        'end_type' => 'never',
        'is_active' => true,
    ];

    /**
     * @return HasMany<DeadlineOccurrence, $this>
     */
    public function occurrences(): HasMany
    {
        return $this->hasMany(DeadlineOccurrence::class);
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<TaxAgency, $this>
     */
    public function taxAgency(): BelongsTo
    {
        return $this->belongsTo(TaxAgency::class);
    }

    /**
     * Deadlines that haven't been archived.
     *
     * @param  Builder<Deadline>  $query
     * @return Builder<Deadline>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Whether the deadline still generates occurrences.
     */
    public function isLive(): bool
    {
        return $this->is_active && ! $this->isArchived();
    }

    /**
     * The days before each due date to email the assignee, largest first.
     *
     * @return list<int>
     */
    public function leadDays(): array
    {
        $days = $this->notify_days_before ?? self::DEFAULT_NOTIFY_DAYS;
        $days = array_values(array_unique(array_map('intval', $days)));
        rsort($days);

        return $days;
    }

    /**
     * The scheduling rule, detached from the model.
     */
    public function rule(): DeadlineRule
    {
        return DeadlineRule::fromDeadline($this);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => DeadlineCategory::class,
            'kind' => DeadlineKind::class,
            'frequency' => DeadlineFrequency::class,
            'due_day_rule' => DueDayRule::class,
            'weekend_adjustment' => WeekendAdjustment::class,
            'end_type' => RecurrenceEndType::class,
            'period_anchor' => 'immutable_date:Y-m-d',
            'due_date' => 'immutable_date:Y-m-d',
            'track_from' => 'immutable_date:Y-m-d',
            'end_date' => 'immutable_date:Y-m-d',
            'annual_dates' => 'array',
            'notify_days_before' => 'array',
            'expected_amount_cents' => 'integer',
            'interval' => 'integer',
            'due_months_after' => 'integer',
            'due_day_of_month' => 'integer',
            'due_days_after' => 'integer',
            'max_occurrences' => 'integer',
            'is_active' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }
}
