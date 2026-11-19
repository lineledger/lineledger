<?php

namespace App\Services\Deadlines;

use App\Models\Deadline;
use App\Models\DeadlineOccurrence;

/**
 * The fields of a deadline or occurrence the audit log records.
 */
final class DeadlineAuditPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function deadline(Deadline $deadline): array
    {
        return [
            'title' => $deadline->title,
            'category' => $deadline->category->value,
            'kind' => $deadline->kind->value,
            'agency_name' => $deadline->agency_name,
            'assigned_user_id' => $deadline->assigned_user_id,
            'frequency' => $deadline->frequency->value,
            'interval' => $deadline->interval,
            'period_anchor' => $deadline->period_anchor?->toDateString(),
            'due_date' => $deadline->due_date?->toDateString(),
            'annual_dates' => $deadline->annual_dates,
            'due_months_after' => $deadline->due_months_after,
            'due_day_rule' => $deadline->due_day_rule->value,
            'due_day_of_month' => $deadline->due_day_of_month,
            'due_days_after' => $deadline->due_days_after,
            'weekend_adjustment' => $deadline->weekend_adjustment->value,
            'end_type' => $deadline->end_type->value,
            'end_date' => $deadline->end_date?->toDateString(),
            'max_occurrences' => $deadline->max_occurrences,
            'expected_amount_cents' => $deadline->expected_amount_cents,
            'is_active' => $deadline->is_active,
            'archived' => $deadline->isArchived(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function occurrence(DeadlineOccurrence $occurrence): array
    {
        return [
            'deadline_id' => $occurrence->deadline_id,
            'period_start' => $occurrence->period_start?->toDateString(),
            'period_end' => $occurrence->period_end?->toDateString(),
            'due_date' => $occurrence->due_date->toDateString(),
            'status' => $occurrence->status->value,
            'assigned_user_id' => $occurrence->assigned_user_id,
            'completed_by_user_id' => $occurrence->completed_by_user_id,
            'completed_on' => $occurrence->completed_on?->toDateString(),
            'amount_cents' => $occurrence->amount_cents,
            'confirmation_number' => $occurrence->confirmation_number,
            'skipped_reason' => $occurrence->skipped_reason,
        ];
    }
}
