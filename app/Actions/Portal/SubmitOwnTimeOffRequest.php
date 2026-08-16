<?php

namespace App\Actions\Portal;

use App\Enums\AuditAction;
use App\Enums\Section;
use App\Enums\TimeOffRequestStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Models\TimeOffRequest;
use App\Models\User;
use App\Notifications\Payroll\TimeOffRequestSubmitted;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Audit\TimeEntryAuditPayload;
use App\Services\Audit\TimeOffRequestAuditPayload;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * The employee-portal write path for time-off requests. Deliberately narrow,
 * like {@see SaveOwnTimeEntry}: it operates strictly on the authenticated
 * employee Contact, forces contact_id and the Pending status, and only accepts
 * a time-off policy that is active AND assigned to that employee (an
 * unassigned policy would pay as plain wages instead of drawing a balance).
 * Submission notifies the employee's designated approver — or every
 * payroll-section user when none is set.
 *
 * Expected $data: time_off_policy_id: int, start_date: string (Y-m-d),
 * end_date: string (Y-m-d), hours_per_day: float|string, note: ?string
 */
final class SubmitOwnTimeOffRequest
{
    public function __construct(private readonly AccountingAuditRecorder $recorder) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Contact $employee, array $data): TimeOffRequest
    {
        return DB::transaction(function () use ($employee, $data): TimeOffRequest {
            $profile = $employee->payrollProfile;

            abort_unless($profile !== null, 403);

            $policy = $profile->availableTimeOffPolicies()
                ->firstWhere('id', (int) ($data['time_off_policy_id'] ?? 0));

            if ($policy === null) {
                throw ValidationException::withMessages(['time_off_policy_id' => __('Pick one of your available time-off types.')]);
            }

            // A company-default policy used for the first time materializes
            // the assignment now, so the leave day can draw a balance later.
            $profile->ensureTimeOffPolicyAssigned($policy);

            $request = new TimeOffRequest([
                'contact_id' => $employee->id,                  // forced — never from input
                'time_off_policy_id' => $policy->id,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'hours_per_day' => (float) ($data['hours_per_day'] ?? 0),
                'employee_note' => ($data['note'] ?? null) ?: null,
                'status' => TimeOffRequestStatus::Pending,      // forced — approvals decide
            ]);

            $this->validateRange($request);
            $request->total_hours = number_format(count($request->businessDays()) * (float) $request->hours_per_day, 2, '.', '');
            $request->save();

            $this->recorder->record((int) $request->company_id, AuditAction::TimeOffRequestSubmitted, $request, [
                'attributes' => TimeOffRequestAuditPayload::snapshot($request->refresh()),
                'actor' => TimeEntryAuditPayload::employeeActor($employee),
            ]);

            $company = app('current_company');
            Notification::send($this->approvers($company, $request), new TimeOffRequestSubmitted($request, $company));

            return $request->refresh();
        });
    }

    /**
     * An employee may withdraw their own request while it is still in the
     * approval pipeline (Pending or ManagerApproved — nothing has been
     * scheduled into payroll yet).
     */
    public function cancelOwn(Contact $employee, TimeOffRequest $request): TimeOffRequest
    {
        abort_unless(
            (int) $request->contact_id === (int) $employee->id
                && in_array($request->status, [TimeOffRequestStatus::Pending, TimeOffRequestStatus::ManagerApproved], true),
            403,
        );

        return DB::transaction(function () use ($employee, $request): TimeOffRequest {
            $request->forceFill(['status' => TimeOffRequestStatus::Cancelled])->save();

            $this->recorder->record((int) $request->company_id, AuditAction::TimeOffRequestCancelled, $request, [
                'attributes' => TimeOffRequestAuditPayload::snapshot($request->refresh()),
                'actor' => TimeEntryAuditPayload::employeeActor($employee),
            ]);

            return $request;
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

    /**
     * Step-1 recipients: the designated approver when set, else every member
     * with payroll-section access.
     *
     * @return Collection<int, User>
     */
    private function approvers(Company $company, TimeOffRequest $request): Collection
    {
        $approver = $request->employee?->payrollProfile?->approver;

        // A designated approver who has since been removed from the company
        // must not keep receiving employee leave details.
        if ($approver !== null && $company->members()->whereKey($approver->id)->exists()) {
            return collect([$approver]);
        }

        return $company->members()
            ->get()
            ->filter(fn ($user) => $user->canAccessSection($company, Section::Payroll))
            ->values();
    }
}
