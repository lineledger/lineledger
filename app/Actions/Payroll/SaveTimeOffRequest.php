<?php

namespace App\Actions\Payroll;

use App\Enums\AuditAction;
use App\Enums\TimeOffRequestStatus;
use App\Models\Contact;
use App\Models\TimeOffPolicy;
use App\Models\TimeOffRequest;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Audit\TimeOffRequestAuditPayload;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staff create/edit of a time-off request on an employee's behalf (phoned-in
 * vacation, a correction before approval). Requests stay editable only while
 * still in the approval pipeline; decisions go through
 * {@see DecideTimeOffRequest}.
 *
 * Expected $data: contact_id: int, time_off_policy_id: int,
 * start_date/end_date: string (Y-m-d), hours_per_day: float|string, note: ?string
 */
final class SaveTimeOffRequest
{
    public function __construct(private readonly AccountingAuditRecorder $recorder) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?TimeOffRequest $request = null): TimeOffRequest
    {
        return DB::transaction(function () use ($data, $request): TimeOffRequest {
            $policy = TimeOffPolicy::query()
                ->where('id', (int) ($data['time_off_policy_id'] ?? 0))
                ->where('is_active', true)
                ->first();

            if ($policy === null) {
                throw ValidationException::withMessages(['time_off_policy_id' => __('Pick an active time-off policy.')]);
            }

            // The id must resolve to one of THIS company's employees — the
            // tenant scope on Contact enforces the company side; is_employee
            // keeps customers/vendors out of payroll.
            $employee = Contact::query()
                ->where('is_employee', true)
                ->find((int) ($data['contact_id'] ?? 0));

            if ($employee === null) {
                throw ValidationException::withMessages(['contact_id' => __('Pick one of your employees.')]);
            }

            $isNew = $request === null || ! $request->exists;

            if (! $isNew && ! in_array($request->status, [TimeOffRequestStatus::Pending, TimeOffRequestStatus::ManagerApproved], true)) {
                throw ValidationException::withMessages(['status' => __('Only requests still awaiting approval can be edited.')]);
            }

            $request ??= new TimeOffRequest;
            $request->fill([
                'contact_id' => $employee->id,
                'time_off_policy_id' => $policy->id,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'hours_per_day' => (float) ($data['hours_per_day'] ?? 0),
                'employee_note' => ($data['note'] ?? null) ?: null,
            ]);

            if ($isNew) {
                $request->status = TimeOffRequestStatus::Pending;
            }

            // Changing the substance of a manager-approved request invalidates
            // the manager's decision — it goes back through step one.
            if (! $isNew
                && $request->status === TimeOffRequestStatus::ManagerApproved
                && $request->isDirty(['contact_id', 'time_off_policy_id', 'start_date', 'end_date', 'hours_per_day'])) {
                $request->forceFill([
                    'status' => TimeOffRequestStatus::Pending,
                    'manager_decided_by_user_id' => null,
                    'manager_decided_at' => null,
                    'manager_note' => null,
                ]);
            }

            $this->validateRange($request);
            $request->total_hours = number_format(count($request->businessDays()) * (float) $request->hours_per_day, 2, '.', '');
            $request->save();

            $this->recorder->record(
                (int) $request->company_id,
                $isNew ? AuditAction::TimeOffRequestSubmitted : AuditAction::TimeOffRequestUpdated,
                $request,
                ['attributes' => TimeOffRequestAuditPayload::snapshot($request->refresh())],
            );

            return $request->refresh();
        });
    }

    private function validateRange(TimeOffRequest $request): void
    {
        $start = CarbonImmutable::parse((string) $request->getAttribute('start_date'));
        $end = CarbonImmutable::parse((string) $request->getAttribute('end_date'));

        if ($end->lt($start)) {
            throw ValidationException::withMessages(['end_date' => __('The end date must be on or after the start date.')]);
        }

        // Cap the span BEFORE walking the days: an unbounded range (a year
        // typo, or a hostile 9999-12-31) would loop millions of Carbon days
        // and overflow the total_hours column.
        if ($start->diffInDays($end) > 366) {
            throw ValidationException::withMessages(['end_date' => __('A time-off request can cover at most one year.')]);
        }

        $hours = (float) $request->hours_per_day;

        if ($hours <= 0 || $hours > 24) {
            throw ValidationException::withMessages(['hours_per_day' => __('Hours per day must be between 0.25 and 24.')]);
        }

        if ($request->businessDays() === []) {
            throw ValidationException::withMessages(['start_date' => __('The selected range has no working days (Mon–Fri).')]);
        }
    }
}
