<?php

namespace App\Services\Audit;

use App\Models\Contact;
use App\Models\TimeEntry;

/**
 * Builds JSON-safe audit payload fragments for time-entry mutations, shared by
 * the staff and portal write paths. Values are normalized to plain scalars
 * (dates as Y-m-d strings, hours as floats) so the same payload round-trips
 * identically through MySQL and SQLite JSON columns.
 */
final class TimeEntryAuditPayload
{
    /**
     * A full snapshot of an entry, used for created and deleted events.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(TimeEntry $entry): array
    {
        return [
            'id' => (int) $entry->id,
            'contact_id' => (int) $entry->contact_id,
            'date_worked' => $entry->date_worked->toDateString(),
            'hours' => (float) $entry->hours,
            'pay_code' => $entry->pay_code,
            'description' => $entry->description,
            'billable' => (bool) $entry->billable,
            'customer_id' => $entry->customer_id !== null ? (int) $entry->customer_id : null,
            'item_id' => $entry->item_id !== null ? (int) $entry->item_id : null,
            'billable_rate_cents' => $entry->billable_rate_cents !== null ? (int) $entry->billable_rate_cents : null,
            'class_id' => $entry->class_id !== null ? (int) $entry->class_id : null,
            'location_id' => $entry->location_id !== null ? (int) $entry->location_id : null,
            'status' => $entry->status->value,
            'pay_run_id' => $entry->pay_run_id !== null ? (int) $entry->pay_run_id : null,
            'invoice_id' => $entry->invoice_id !== null ? (int) $entry->invoice_id : null,
            'time_off_request_id' => $entry->time_off_request_id !== null ? (int) $entry->time_off_request_id : null,
        ];
    }

    /**
     * The dirty diff of a filled-but-unsaved entry: field => [from, to], with
     * "from" taken from the attribute values as loaded (i.e. BEFORE save).
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public static function changes(TimeEntry $entry): array
    {
        $changes = [];

        foreach ($entry->getDirty() as $key => $value) {
            $changes[$key] = [
                'from' => self::normalize($key, $entry->getRawOriginal($key)),
                'to' => self::normalize($key, $value),
            ];
        }

        return $changes;
    }

    /**
     * Identifies the acting portal employee inside the payload, since a portal
     * Contact never lands in actor_user_id.
     *
     * @return array{type: string, contact_id: int, name: ?string}
     */
    public static function employeeActor(Contact $employee): array
    {
        return [
            'type' => 'employee',
            'contact_id' => (int) $employee->id,
            'name' => $employee->display_name,
        ];
    }

    /**
     * Raw attribute values differ by driver (MySQL DATE columns drop the time
     * part, decimals come back as strings); normalize per-field so payloads are
     * driver-independent and JSON-safe.
     */
    private static function normalize(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return match ($key) {
            'date_worked' => substr((string) $value, 0, 10),
            'hours' => (float) $value,
            'billable' => (bool) $value,
            default => $value,
        };
    }
}
