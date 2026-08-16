<?php

namespace App\Actions\Payroll;

use App\Enums\AuditAction;
use App\Enums\PayRunStatus;
use App\Enums\Section;
use App\Enums\TimeEntryStatus;
use App\Enums\TimeOffRequestStatus;
use App\Models\Company;
use App\Models\PayRun;
use App\Models\TimeEntry;
use App\Models\TimeOffRequest;
use App\Models\User;
use App\Notifications\Payroll\TimeOffRequestAwaitingConfirmation;
use App\Notifications\Portal\TimeOffRequestDecided;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Audit\TimeOffRequestAuditPayload;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * The two-level decision path for a time-off request:
 *
 *   managerApprove()  Pending → ManagerApproved   the absence is accepted
 *   approve()         ManagerApproved (or Pending, fast-tracking both steps)
 *                     → Approved                  payroll confirms the PAY
 *                     treatment and generates one Approved TimeEntry per
 *                     working day (pay_code = the policy code), which the
 *                     pay-run pull consumes — drawdown is the existing
 *                     accrual machinery, never this action.
 *   deny()/cancel()   close the request; cancel deletes the generated
 *                     entries a pay run hasn't consumed yet.
 *
 * Any payroll-section user can take either step (the designated approver is
 * who gets NOTIFIED for step 1; payroll can always act). Every transition is
 * audited and the final decision is mailed to the employee.
 */
final class DecideTimeOffRequest
{
    public function __construct(private readonly AccountingAuditRecorder $recorder) {}

    public function managerApprove(TimeOffRequest $request, User $actor, ?string $note = null): TimeOffRequest
    {
        return DB::transaction(function () use ($request, $actor, $note): TimeOffRequest {
            $request = $this->lockFresh($request, [TimeOffRequestStatus::Pending]);

            $request->forceFill([
                'status' => TimeOffRequestStatus::ManagerApproved,
                'manager_decided_by_user_id' => $actor->id,
                'manager_decided_at' => now(),
                'manager_note' => $note ?: null,
            ])->save();

            $this->recorder->record((int) $request->company_id, AuditAction::TimeOffRequestManagerApproved, $request, [
                'attributes' => TimeOffRequestAuditPayload::snapshot($request->refresh()),
            ]);

            $company = app('current_company');
            Notification::send($this->payrollUsers($company), new TimeOffRequestAwaitingConfirmation($request, $company));

            return $request;
        });
    }

    public function approve(TimeOffRequest $request, User $actor, ?string $note = null, bool $generateEntries = true): TimeOffRequest
    {
        return DB::transaction(function () use ($request, $actor, $note, $generateEntries): TimeOffRequest {
            $request = $this->lockFresh($request, [TimeOffRequestStatus::Pending, TimeOffRequestStatus::ManagerApproved]);

            // Fast-tracking a Pending request records the same user for both steps.
            if ($request->status === TimeOffRequestStatus::Pending) {
                $request->forceFill([
                    'manager_decided_by_user_id' => $actor->id,
                    'manager_decided_at' => now(),
                ]);
            }

            $request->forceFill([
                'status' => TimeOffRequestStatus::Approved,
                'decided_by_user_id' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $note ?: null,
            ])->save();

            $generated = 0;
            $skipped = 0;

            if ($generateEntries) {
                // Scheduling the days requires the policy assignment (pricing +
                // balance drawdown key off it) — materialize it if missing,
                // e.g. for an admin-recorded request on an unassigned policy.
                $request->employee?->payrollProfile?->ensureTimeOffPolicyAssigned($request->policy);

                [$generated, $skipped] = $this->generateEntries($request);
            }

            $this->recorder->record((int) $request->company_id, AuditAction::TimeOffRequestApproved, $request, [
                'attributes' => TimeOffRequestAuditPayload::snapshot($request->refresh()),
                'generated_entries' => $generated,
                'skipped_days_with_existing_entries' => $skipped,
            ]);

            $request->employee?->notify(new TimeOffRequestDecided($request, app('current_company')));

            return $request->refresh();
        });
    }

    public function deny(TimeOffRequest $request, User $actor, ?string $note = null): TimeOffRequest
    {
        return DB::transaction(function () use ($request, $actor, $note): TimeOffRequest {
            $request = $this->lockFresh($request, [TimeOffRequestStatus::Pending, TimeOffRequestStatus::ManagerApproved]);

            $request->forceFill([
                'status' => TimeOffRequestStatus::Denied,
                'decided_by_user_id' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $note ?: null,
            ])->save();

            $this->recorder->record((int) $request->company_id, AuditAction::TimeOffRequestDenied, $request, [
                'attributes' => TimeOffRequestAuditPayload::snapshot($request->refresh()),
            ]);

            $request->employee?->notify(new TimeOffRequestDecided($request, app('current_company')));

            return $request;
        });
    }

    /**
     * Withdraw an open (or already approved) request. Generated entries a pay
     * run hasn't consumed are deleted; consumed ones are history — the pay run
     * already paid them and only a void can unwind that.
     */
    public function cancel(TimeOffRequest $request, User $actor, ?string $note = null): TimeOffRequest
    {
        return DB::transaction(function () use ($request, $actor, $note): TimeOffRequest {
            $request = $this->lockFresh($request, [TimeOffRequestStatus::Pending, TimeOffRequestStatus::ManagerApproved, TimeOffRequestStatus::Approved]);

            $removed = $request->timeEntries()->whereNull('pay_run_id')->delete();

            // Entries already pulled into a run that hasn't POSTED yet aren't
            // paid history — remove them too and re-pull each affected run so
            // its generated earnings/hours re-derive without the cancelled
            // leave. (Posted runs keep their entries; only a void unwinds pay.)
            $draftRunIds = $request->timeEntries()
                ->whereNotNull('pay_run_id')
                ->whereHas('payRun', fn ($q) => $q->whereIn('status', [PayRunStatus::Draft->value, PayRunStatus::Calculated->value]))
                ->pluck('pay_run_id')
                ->unique();

            if ($draftRunIds->isNotEmpty()) {
                $removed += $request->timeEntries()->whereIn('pay_run_id', $draftRunIds)->delete();

                foreach (PayRun::query()->whereIn('id', $draftRunIds)->get() as $affectedRun) {
                    app(PullTimeEntriesIntoPayRun::class)->handle($affectedRun);
                }
            }

            $request->forceFill([
                'status' => TimeOffRequestStatus::Cancelled,
                'decided_by_user_id' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $note ?: null,
            ])->save();

            $this->recorder->record((int) $request->company_id, AuditAction::TimeOffRequestCancelled, $request, [
                'attributes' => TimeOffRequestAuditPayload::snapshot($request->refresh()),
                'removed_entries' => $removed,
            ]);

            $request->employee?->notify(new TimeOffRequestDecided($request, app('current_company')));

            return $request;
        });
    }

    /**
     * One Approved time entry per working day, stamped with the request so a
     * later cancel removes exactly the unconsumed ones. pay_code carries the
     * policy code, so the pull + engine + poster treat the day exactly like a
     * staff-entered leave entry.
     */
    /**
     * @return array{0: int, 1: int} [generated, skipped] — days the employee
     *                               already has ANY time entry on are skipped,
     *                               so an approval can never stack leave pay on
     *                               top of logged hours (or double-generate).
     */
    private function generateEntries(TimeOffRequest $request): array
    {
        $policy = $request->policy;

        $existing = TimeEntry::query()
            ->where('contact_id', $request->contact_id)
            ->whereIn('date_worked', $request->businessDays())
            ->pluck('date_worked')
            ->map(fn ($date) => substr((string) $date, 0, 10))
            ->all();

        $generated = 0;
        $skipped = 0;

        foreach ($request->businessDays() as $date) {
            if (in_array($date, $existing, true)) {
                $skipped++;

                continue;
            }

            $request->timeEntries()->create([
                'contact_id' => $request->contact_id,
                'date_worked' => $date,
                'hours' => (float) $request->hours_per_day,
                'pay_code' => $policy->code,
                'description' => __(':policy (requested time off)', ['policy' => $policy->name]),
                'status' => TimeEntryStatus::Approved->value,
            ]);

            $generated++;
        }

        return [$generated, $skipped];
    }

    /**
     * Re-fetch the request fresh WITH a row lock inside the caller's
     * transaction, then assert the transition is still allowed — so two
     * concurrent decisions (or a decision racing the employee's withdrawal)
     * serialize, and the loser gets a friendly validation message instead of
     * double-generating entries or resurrecting a closed request.
     *
     * @param  list<TimeOffRequestStatus>  $allowed
     */
    private function lockFresh(TimeOffRequest $request, array $allowed): TimeOffRequest
    {
        $request = TimeOffRequest::query()->lockForUpdate()->findOrFail($request->id);

        if (! in_array($request->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => __('This request is now :status — it may have been decided or withdrawn while you had it open.', ['status' => $request->status->label()]),
            ]);
        }

        return $request;
    }

    /**
     * @return Collection<int, User>
     */
    private function payrollUsers(Company $company): Collection
    {
        return $company->members()
            ->get()
            ->filter(fn (User $user) => $user->canAccessSection($company, Section::Payroll))
            ->values();
    }
}
