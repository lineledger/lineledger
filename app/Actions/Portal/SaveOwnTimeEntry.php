<?php

namespace App\Actions\Portal;

use App\Enums\AuditAction;
use App\Enums\TimeEntryStatus;
use App\Models\Contact;
use App\Models\TimeEntry;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Audit\TimeEntryAuditPayload;
use App\Support\Payroll\TimeEntryPayCodeCatalogue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The employee-portal write path for self-logged time. Like
 * {@see UpdateOwnEmployeeInfo} it is deliberately narrow: it operates strictly on
 * the authenticated employee Contact, FORCES contact_id to that employee and
 * status to Pending (staff approve), and never lets the employee set a billable
 * rate (pricing is a staff decision). Employees may only edit/delete their own
 * still-Pending, unconsumed entries. Every mutation writes an immutable audit
 * row; since a portal Contact never lands in actor_user_id, the acting employee
 * is identified inside the payload instead.
 */
final class SaveOwnTimeEntry
{
    public function __construct(private readonly AccountingAuditRecorder $recorder) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Contact $employee, array $data, ?TimeEntry $entry = null): TimeEntry
    {
        return DB::transaction(function () use ($employee, $data, $entry): TimeEntry {
            if ($entry !== null && $entry->exists) {
                $this->assertOwnEditable($employee, $entry);
            }

            $billable = (bool) ($data['billable'] ?? false);

            $payCode = (string) ($data['pay_code'] ?? TimeEntryPayCodeCatalogue::REGULAR);

            // Validated against THIS employee's portal subset (their available
            // time-off policies; banked overtime only when their profile may
            // bank), so staff-only or unavailable codes can never arrive from
            // the employee timesheet.
            if (! TimeEntryPayCodeCatalogue::isValid($payCode, $employee->payrollProfile, portal: true)) {
                throw ValidationException::withMessages(['pay_code' => __('Unknown pay type.')]);
            }

            // Using a company-default policy for the first time materializes the
            // assignment, so the pulled leave day prices + draws correctly.
            if (! TimeEntryPayCodeCatalogue::isWageCode($payCode)) {
                $profile = $employee->payrollProfile;
                $policy = $profile?->availableTimeOffPolicies()->firstWhere('code', $payCode);

                if ($profile !== null && $policy !== null) {
                    $profile->ensureTimeOffPolicyAssigned($policy);
                }
            }

            $attributes = [
                'contact_id' => $employee->id,                 // forced — never from input
                'date_worked' => $data['date_worked'],
                'hours' => (float) ($data['hours'] ?? 0),
                'pay_code' => $payCode,
                'description' => ($data['description'] ?? null) ?: null,
                'billable' => $billable,
                'customer_id' => $billable ? ($data['customer_id'] ?? null) : null,
                'item_id' => $billable ? ($data['item_id'] ?? null) : null,
                'class_id' => $data['class_id'] ?? null,
                'status' => TimeEntryStatus::Pending->value,   // forced — staff approve
                // billable_rate_cents is intentionally never set here.
            ];

            if ($entry !== null && $entry->exists) {
                $entry->fill($attributes);
                $changes = TimeEntryAuditPayload::changes($entry);
                $entry->save();

                if ($changes !== []) {
                    $this->recorder->record((int) $entry->company_id, AuditAction::TimeEntryUpdated, $entry, [
                        'changes' => $changes,
                        'actor' => TimeEntryAuditPayload::employeeActor($employee),
                    ]);
                }
            } else {
                $entry = TimeEntry::create($attributes);

                $this->recorder->record((int) $entry->company_id, AuditAction::TimeEntryCreated, $entry, [
                    'attributes' => TimeEntryAuditPayload::snapshot($entry->refresh()),
                    'actor' => TimeEntryAuditPayload::employeeActor($employee),
                ]);
            }

            return $entry->refresh();
        });
    }

    public function delete(Contact $employee, TimeEntry $entry): void
    {
        $this->assertOwnEditable($employee, $entry);

        DB::transaction(function () use ($employee, $entry): void {
            // Recorded before the delete so the snapshot still reads from a
            // live row; both land or neither does.
            $this->recorder->record((int) $entry->company_id, AuditAction::TimeEntryDeleted, $entry, [
                'attributes' => TimeEntryAuditPayload::snapshot($entry),
                'actor' => TimeEntryAuditPayload::employeeActor($employee),
            ]);

            $entry->delete();
        });
    }

    /**
     * An employee may only touch their own entry while it is still Pending and not
     * yet consumed by a pay run or invoice.
     */
    private function assertOwnEditable(Contact $employee, TimeEntry $entry): void
    {
        abort_unless(
            (int) $entry->contact_id === (int) $employee->id
                && $entry->status === TimeEntryStatus::Pending
                && $entry->pay_run_id === null
                && $entry->invoice_id === null,
            403,
        );
    }
}
