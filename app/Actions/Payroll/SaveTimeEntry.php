<?php

namespace App\Actions\Payroll;

use App\Enums\AuditAction;
use App\Enums\TimeEntryStatus;
use App\Models\EmployeePayrollProfile;
use App\Models\TimeEntry;
use App\Models\TimeOffPolicy;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Audit\TimeEntryAuditPayload;
use App\Support\Payroll\BankedOvertimeRules;
use App\Support\Payroll\TimeEntryPayCodeCatalogue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates one time entry. Shared by the staff CRUD page. Billing
 * fields (customer / item / rate) are only kept when the entry is billable, so a
 * non-billable entry never carries a stray customer. Staff-created entries default
 * to Approved (staff is trusted); the portal path sends Pending instead. Every
 * mutation writes an immutable audit row (created with a snapshot, updated with
 * a from/to diff of the dirty fields).
 *
 * Expected $data:
 *   contact_id: int (the employee), date_worked: string (Y-m-d), hours: float|string,
 *   pay_code: ?string (defaults to regular; must be a TimeEntryPayCodeCatalogue code),
 *   description: ?string, billable: bool, customer_id: ?int, item_id: ?int,
 *   billable_rate_cents: ?int, class_id: ?int, location_id: ?int, status: ?string
 */
final class SaveTimeEntry
{
    public function __construct(private readonly AccountingAuditRecorder $recorder) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?TimeEntry $entry = null): TimeEntry
    {
        return DB::transaction(function () use ($data, $entry): TimeEntry {
            // Once a pay run or invoice consumed the entry, its content is part
            // of what was paid/billed — editing would silently diverge the
            // timesheet from the frozen run/invoice. The status path has been
            // locked this way all along ({@see SetTimeEntryStatus}).
            if ($entry && $entry->exists && ($entry->pay_run_id !== null || $entry->invoice_id !== null)) {
                throw ValidationException::withMessages(['entry' => __('This entry has already been paid or billed and can no longer be edited.')]);
            }

            $billable = (bool) ($data['billable'] ?? false);

            $rate = $data['billable_rate_cents'] ?? null;

            $payCode = (string) ($data['pay_code'] ?? TimeEntryPayCodeCatalogue::REGULAR);

            if (! TimeEntryPayCodeCatalogue::isValid($payCode)) {
                throw ValidationException::withMessages(['pay_code' => __('Unknown pay type.')]);
            }

            // Banked overtime is gated per employee: enabled on the profile, the
            // province permits banking, and the written agreement is recorded.
            if ($payCode === TimeEntryPayCodeCatalogue::OVERTIME_BANKED) {
                $profile = EmployeePayrollProfile::query()->where('contact_id', $data['contact_id'])->first();

                if ($profile === null || ! BankedOvertimeRules::canBank($profile)) {
                    throw ValidationException::withMessages(['pay_code' => __('Banked overtime is not enabled for this employee (it needs the profile toggle, a permitted province, and a written-agreement date).')]);
                }
            }

            // A leave code needs its policy ASSIGNED to the employee, or the
            // engine prices the hours as plain wages (full pay on top of a
            // salary) with no balance draw-down — staff picking the code IS the
            // assignment decision.
            if (! TimeEntryPayCodeCatalogue::isWageCode($payCode)) {
                $policy = TimeOffPolicy::query()->where('code', $payCode)->where('is_active', true)->first();
                $profile = EmployeePayrollProfile::query()->where('contact_id', $data['contact_id'])->first();

                if ($policy !== null && $profile !== null) {
                    $profile->ensureTimeOffPolicyAssigned($policy);
                }
            }

            $attributes = [
                'contact_id' => $data['contact_id'],
                'date_worked' => $data['date_worked'],
                'hours' => (float) ($data['hours'] ?? 0),
                'pay_code' => $payCode,
                'description' => ($data['description'] ?? null) ?: null,
                'billable' => $billable,
                'customer_id' => $billable ? ($data['customer_id'] ?? null) : null,
                'item_id' => $billable ? ($data['item_id'] ?? null) : null,
                'billable_rate_cents' => $billable && $rate !== null && $rate !== '' ? (int) $rate : null,
                'class_id' => $data['class_id'] ?? null,
                'location_id' => $data['location_id'] ?? null,
            ];

            if (array_key_exists('status', $data) && $data['status'] !== null) {
                $attributes['status'] = $data['status'];
            }

            if ($entry && $entry->exists) {
                $entry->fill($attributes);
                $changes = TimeEntryAuditPayload::changes($entry);
                $entry->save();

                if ($changes !== []) {
                    $this->recorder->record((int) $entry->company_id, AuditAction::TimeEntryUpdated, $entry, [
                        'changes' => $changes,
                    ]);
                }
            } else {
                $attributes['status'] ??= TimeEntryStatus::Approved->value;
                $entry = TimeEntry::create($attributes);

                $this->recorder->record((int) $entry->company_id, AuditAction::TimeEntryCreated, $entry, [
                    'attributes' => TimeEntryAuditPayload::snapshot($entry->refresh()),
                ]);
            }

            return $entry->refresh();
        });
    }
}
