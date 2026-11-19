<?php

namespace Database\Factories;

use App\Enums\DeadlineCategory;
use App\Enums\DeadlineFrequency;
use App\Enums\DeadlineKind;
use App\Enums\DueDayRule;
use App\Enums\RecurrenceEndType;
use App\Enums\WeekendAdjustment;
use App\Models\Deadline;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A monthly deadline due on the last day of the following month. Bind a
 * company (or pass company_id) when creating; call DeadlineOccurrenceSync to
 * materialize its occurrences.
 *
 * @extends Factory<Deadline>
 */
class DeadlineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->randomElement(['GST/HST return', 'PST return', 'Source deductions', 'WCB report']),
            'category' => DeadlineCategory::SalesTax,
            'kind' => DeadlineKind::FileAndPay,
            'agency_name' => 'Canada Revenue Agency',
            'frequency' => DeadlineFrequency::Monthly,
            'interval' => 1,
            'period_anchor' => '2026-01-01',
            'track_from' => '2026-01-01',
            'due_months_after' => 1,
            'due_day_rule' => DueDayRule::LastDay,
            'due_days_after' => 0,
            'weekend_adjustment' => WeekendAdjustment::Keep,
            'end_type' => RecurrenceEndType::Never,
            'notify_days_before' => [7, 1],
            'is_active' => true,
        ];
    }

    public function once(string $dueDate): static
    {
        return $this->state(fn (): array => [
            'frequency' => DeadlineFrequency::Once,
            'period_anchor' => null,
            'due_date' => $dueDate,
            'track_from' => null,
        ]);
    }
}
