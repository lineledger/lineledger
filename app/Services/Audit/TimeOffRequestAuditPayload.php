<?php

namespace App\Services\Audit;

use App\Models\TimeOffRequest;

/**
 * JSON-safe audit payload fragments for time-off-request mutations, mirroring
 * {@see TimeEntryAuditPayload}: scalars only (dates as Y-m-d, hours as floats)
 * so payloads round-trip identically through MySQL and SQLite JSON columns.
 * Portal-actor identification reuses {@see TimeEntryAuditPayload::employeeActor()}.
 */
final class TimeOffRequestAuditPayload
{
    /**
     * A full snapshot, used for submitted/decision events.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(TimeOffRequest $request): array
    {
        return [
            'id' => (int) $request->id,
            'contact_id' => (int) $request->contact_id,
            'time_off_policy_id' => (int) $request->time_off_policy_id,
            'start_date' => $request->start_date->toDateString(),
            'end_date' => $request->end_date->toDateString(),
            'hours_per_day' => (float) $request->hours_per_day,
            'total_hours' => (float) $request->total_hours,
            'employee_note' => $request->employee_note,
            'status' => $request->status->value,
            'manager_decided_by_user_id' => $request->manager_decided_by_user_id !== null ? (int) $request->manager_decided_by_user_id : null,
            'manager_note' => $request->manager_note,
            'decided_by_user_id' => $request->decided_by_user_id !== null ? (int) $request->decided_by_user_id : null,
            'decision_note' => $request->decision_note,
        ];
    }
}
