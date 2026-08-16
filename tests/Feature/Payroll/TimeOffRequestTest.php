<?php

use App\Actions\Payroll\DecideTimeOffRequest;
use App\Actions\Payroll\PullTimeEntriesIntoPayRun;
use App\Actions\Payroll\SaveEmployeePayrollProfile;
use App\Actions\Payroll\SavePayRun;
use App\Actions\Payroll\SaveTimeOffRequest;
use App\Actions\Portal\SubmitOwnTimeOffRequest;
use App\Enums\CompanyRole;
use App\Enums\PayBasis;
use App\Enums\TimeEntryStatus;
use App\Enums\TimeOffRequestStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeeAccrualBalance;
use App\Models\EmployeePayrollProfile;
use App\Models\EmployeeTimeOffPolicy;
use App\Models\Membership;
use App\Models\PayrollSchedule;
use App\Models\TimeOffPolicy;
use App\Models\TimeOffRequest;
use App\Models\User;
use App\Notifications\Payroll\TimeOffRequestAwaitingConfirmation;
use App\Notifications\Payroll\TimeOffRequestSubmitted;
use App\Notifications\Portal\TimeOffRequestDecided;
use App\Services\Payroll\CalculatePayRun;
use App\Services\Payroll\TimeOffBalanceProjection;
use App\Services\Posting\PayRunPoster;
use App\Support\Payroll\TimeEntryPayCodeCatalogue;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Notification::fake();

    $this->user = User::factory()->create();
    $this->manager = User::factory()->create();
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    Membership::create(['company_id' => $this->company->id, 'user_id' => $this->user->id, 'role' => CompanyRole::Owner]);
    Membership::create(['company_id' => $this->company->id, 'user_id' => $this->manager->id, 'role' => CompanyRole::Admin]);
    actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->schedule = PayrollSchedule::factory()->create();

    $this->employee = Contact::create(['display_name' => 'Vacay Vera', 'email' => 'vera@emp.test', 'is_employee' => true, 'is_active' => true]);
    $this->profile = EmployeePayrollProfile::factory()->create([
        'contact_id' => $this->employee->id,
        'province_of_employment' => 'ON',
        'pay_basis' => PayBasis::Hourly->value,
        'hourly_rate_cents' => 3000,
        'payroll_schedule_id' => $this->schedule->id,
        'td1_federal_claim_cents' => 1612900,
        'td1_provincial_claim_cents' => 1294700,
        'approver_user_id' => $this->manager->id,
        'is_active' => true,
    ]);

    $this->policy = TimeOffPolicy::create([
        'name' => 'Vacation', 'code' => 'vacation_hours', 'category' => 'vacation', 'unit' => 'hours',
        'accrual_method' => 'manual', 'rate_hours' => 0, 'rate_bp' => 0, 'paid' => true, 'is_active' => true,
    ]);
    EmployeeTimeOffPolicy::create([
        'employee_payroll_profile_id' => $this->profile->id, 'time_off_policy_id' => $this->policy->id, 'is_active' => true,
    ]);
    EmployeeAccrualBalance::create([
        'employee_payroll_profile_id' => $this->profile->id, 'code' => 'vacation_hours', 'name' => 'Vacation', 'balance_hours' => 40,
    ]);
});

afterEach(fn () => app()->forgetInstance('current_company'));

/** Submit a Mon–Wed (2025-06-02..04) request as the employee, 8h/day = 24h. */
function torSubmit(): TimeOffRequest
{
    return app(SubmitOwnTimeOffRequest::class)->handle(test()->employee, [
        'time_off_policy_id' => test()->policy->id,
        'start_date' => '2025-06-02',
        'end_date' => '2025-06-04',
        'hours_per_day' => 8,
        'note' => 'Cottage week',
    ]);
}

// --- submission ------------------------------------------------------------

it('lets an employee submit a request, computing working-day hours and notifying the approver', function () {
    $request = torSubmit();

    expect($request->status)->toBe(TimeOffRequestStatus::Pending)
        ->and((float) $request->total_hours)->toBe(24.0)
        ->and($request->contact_id)->toBe($this->employee->id);

    // The designated approver is notified — not the whole payroll team.
    Notification::assertSentTo($this->manager, TimeOffRequestSubmitted::class);
    Notification::assertNotSentTo($this->user, TimeOffRequestSubmitted::class);
});

it('skips weekend days when computing total hours', function () {
    // Fri 2025-06-06 → Mon 2025-06-09 spans a weekend: 2 working days.
    $request = app(SubmitOwnTimeOffRequest::class)->handle($this->employee, [
        'time_off_policy_id' => $this->policy->id,
        'start_date' => '2025-06-06',
        'end_date' => '2025-06-09',
        'hours_per_day' => 7.5,
    ]);

    expect((float) $request->total_hours)->toBe(15.0);
});

it('offers company-default policies to unassigned employees and materializes the assignment on first use', function () {
    // A default ("Use for new employees") policy the employee was never assigned.
    $default = TimeOffPolicy::create([
        'name' => 'Personal days', 'code' => 'personal', 'category' => 'personal', 'unit' => 'hours',
        'accrual_method' => 'manual', 'rate_hours' => 0, 'rate_bp' => 0, 'paid' => true,
        'is_default' => true, 'is_active' => true,
    ]);

    // Visible in the portal: the policy list and the timesheet pay codes.
    expect($this->profile->availableTimeOffPolicies()->pluck('id'))->toContain($default->id)
        ->and(TimeEntryPayCodeCatalogue::portalOptions($this->profile))->toHaveKey('personal');

    // Requestable — and the first use creates the assignment so the leave
    // days can draw a balance when paid.
    $request = app(SubmitOwnTimeOffRequest::class)->handle($this->employee, [
        'time_off_policy_id' => $default->id,
        'start_date' => '2025-06-02',
        'end_date' => '2025-06-02',
        'hours_per_day' => 8,
    ]);

    expect($request->status)->toBe(TimeOffRequestStatus::Pending)
        ->and($this->profile->timeOffPolicies()->where('time_off_policy_id', $default->id)->where('is_active', true)->exists())->toBeTrue();
});

it('auto-assigns active default policies when a payroll profile is first created', function () {
    TimeOffPolicy::create([
        'name' => 'Sick days', 'code' => 'sick_default', 'category' => 'sick', 'unit' => 'hours',
        'accrual_method' => 'manual', 'rate_hours' => 0, 'rate_bp' => 0, 'paid' => true,
        'is_default' => true, 'is_active' => true,
    ]);

    $newHire = Contact::create(['display_name' => 'New Nadia', 'is_employee' => true, 'is_active' => true]);

    $profile = app(SaveEmployeePayrollProfile::class)->handle([
        'contact_id' => $newHire->id,
        'province_of_employment' => 'ON',
        'pay_basis' => PayBasis::Hourly->value,
        'hourly_rate_cents' => 2500,
        'payroll_schedule_id' => $this->schedule->id,
        'td1_federal_claim_cents' => 1612900,
        'td1_provincial_claim_cents' => 1294700,
        'vacation_policy' => 'accrue',
    ]);

    expect($profile->timeOffPolicies()->whereHas('policy', fn ($q) => $q->where('code', 'sick_default'))->exists())->toBeTrue()
        // The non-default vacation_hours policy from the suite setup is NOT auto-assigned.
        ->and($profile->timeOffPolicies()->where('time_off_policy_id', $this->policy->id)->exists())->toBeFalse();
});

it('rejects a policy that is not assigned to the employee', function () {
    $other = TimeOffPolicy::create([
        'name' => 'Sick', 'code' => 'sick', 'category' => 'sick', 'unit' => 'hours',
        'accrual_method' => 'manual', 'rate_hours' => 0, 'rate_bp' => 0, 'paid' => true, 'is_active' => true,
    ]);

    app(SubmitOwnTimeOffRequest::class)->handle($this->employee, [
        'time_off_policy_id' => $other->id,
        'start_date' => '2025-06-02',
        'end_date' => '2025-06-03',
        'hours_per_day' => 8,
    ]);
})->throws(ValidationException::class);

it('notifies every payroll user when no approver is designated', function () {
    $this->profile->update(['approver_user_id' => null]);

    torSubmit();

    Notification::assertSentTo($this->user, TimeOffRequestSubmitted::class);
    Notification::assertSentTo($this->manager, TimeOffRequestSubmitted::class);
});

// --- the two-level decision ------------------------------------------------

it('walks Pending → ManagerApproved → Approved, generating per-day entries the pull consumes', function () {
    $request = torSubmit();

    app(DecideTimeOffRequest::class)->managerApprove($request, $this->manager, 'Enjoy!');
    $request->refresh();

    expect($request->status)->toBe(TimeOffRequestStatus::ManagerApproved)
        ->and($request->manager_decided_by_user_id)->toBe($this->manager->id);
    Notification::assertSentTo($this->user, TimeOffRequestAwaitingConfirmation::class);

    app(DecideTimeOffRequest::class)->approve($request, $this->user);
    $request->refresh();

    expect($request->status)->toBe(TimeOffRequestStatus::Approved)
        ->and($request->decided_by_user_id)->toBe($this->user->id);
    Notification::assertSentTo($this->employee, TimeOffRequestDecided::class);

    // One Approved entry per working day, coded with the policy.
    $entries = $request->timeEntries()->get();
    expect($entries)->toHaveCount(3)
        ->and($entries->every(fn ($e) => $e->pay_code === 'vacation_hours' && $e->status === TimeEntryStatus::Approved))->toBeTrue();

    // Pull → calculate → post: pays at the rate and draws the balance.
    $bank = Account::query()->where('code', '1000')->firstOrFail();
    $run = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => $this->schedule->id,
        'period_start_date' => '2025-06-01',
        'period_end_date' => '2025-06-14',
        'pay_date' => '2025-06-20',
        'bank_account_id' => $bank->id,
        'lines' => [['contact_id' => $this->employee->id]],
    ]);
    app(PullTimeEntriesIntoPayRun::class)->handle($run->fresh());
    app(CalculatePayRun::class)->calculate($run->fresh());

    $line = $run->lines()->firstOrFail();
    expect((int) $line->earnings->firstWhere('code', 'vacation_hours')?->amount_cents)->toBe(72000); // 24h × $30

    app(PayRunPoster::class)->post($run->fresh());

    $balance = EmployeeAccrualBalance::query()->where('employee_payroll_profile_id', $this->profile->id)->where('code', 'vacation_hours')->first();
    expect((float) $balance->balance_hours)->toBe(16.0)
        ->and((float) $balance->used_ytd_hours)->toBe(24.0);
});

it('fast-tracks approval from Pending, recording the same user for both steps', function () {
    $request = torSubmit();

    app(DecideTimeOffRequest::class)->approve($request, $this->user, 'Fine by me');
    $request->refresh();

    expect($request->status)->toBe(TimeOffRequestStatus::Approved)
        ->and($request->manager_decided_by_user_id)->toBe($this->user->id)
        ->and($request->decided_by_user_id)->toBe($this->user->id);
});

it('denies with a comment that reaches the employee', function () {
    $request = torSubmit();

    app(DecideTimeOffRequest::class)->deny($request, $this->manager, 'Quarter-end crunch');

    expect($request->fresh()->status)->toBe(TimeOffRequestStatus::Denied)
        ->and($request->fresh()->decision_note)->toBe('Quarter-end crunch');
    Notification::assertSentTo($this->employee, TimeOffRequestDecided::class);
});

it('cancelling pulls the leave back out of a still-draft run but keeps entries a POSTED run paid', function () {
    $request = torSubmit();
    app(DecideTimeOffRequest::class)->approve($request, $this->user);

    // Pull the generated days into a DRAFT run: cancelling must remove them
    // and re-derive the run, since nothing has been paid yet.
    $bank = Account::query()->where('code', '1000')->firstOrFail();
    $run = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => $this->schedule->id,
        'period_start_date' => '2025-06-01',
        'period_end_date' => '2025-06-14',
        'pay_date' => '2025-06-20',
        'bank_account_id' => $bank->id,
        'lines' => [['contact_id' => $this->employee->id]],
    ]);
    app(PullTimeEntriesIntoPayRun::class)->handle($run->fresh());
    expect($request->timeEntries()->whereNotNull('pay_run_id')->count())->toBe(3);

    app(DecideTimeOffRequest::class)->cancel($request->fresh(), $this->user, 'Plans changed');

    $line = $run->fresh()->lines()->firstOrFail();
    expect($request->fresh()->status)->toBe(TimeOffRequestStatus::Cancelled)
        ->and($request->timeEntries()->count())->toBe(0)
        // The re-pull stripped the leave earnings back off the draft run.
        ->and($line->manualEarnings()->where('code', 'vacation_hours')->count())->toBe(0);
});

it('cancelling after the run POSTED keeps the paid entries (only a void unwinds pay)', function () {
    $request = torSubmit();
    app(DecideTimeOffRequest::class)->approve($request, $this->user);

    $bank = Account::query()->where('code', '1000')->firstOrFail();
    $run = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => $this->schedule->id,
        'period_start_date' => '2025-06-01',
        'period_end_date' => '2025-06-14',
        'pay_date' => '2025-06-20',
        'bank_account_id' => $bank->id,
        'lines' => [['contact_id' => $this->employee->id]],
    ]);
    app(PullTimeEntriesIntoPayRun::class)->handle($run->fresh());
    app(CalculatePayRun::class)->calculate($run->fresh());
    app(PayRunPoster::class)->post($run->fresh());

    app(DecideTimeOffRequest::class)->cancel($request->fresh(), $this->user, 'Too late');

    expect($request->fresh()->status)->toBe(TimeOffRequestStatus::Cancelled)
        ->and($request->timeEntries()->count())->toBe(3)
        ->and($request->timeEntries()->whereNull('pay_run_id')->count())->toBe(0);
});

it('lets the employee withdraw while pending but not after approval', function () {
    $request = torSubmit();

    app(SubmitOwnTimeOffRequest::class)->cancelOwn($this->employee, $request);
    expect($request->fresh()->status)->toBe(TimeOffRequestStatus::Cancelled);

    $second = torSubmit();
    app(DecideTimeOffRequest::class)->approve($second, $this->user);

    expect(fn () => app(SubmitOwnTimeOffRequest::class)->cancelOwn($this->employee, $second->fresh()))
        ->toThrow(HttpException::class);
});

// --- projection ------------------------------------------------------------

it('projects the balance net of in-flight requests', function () {
    torSubmit(); // 24h pending

    $projection = app(TimeOffBalanceProjection::class)->for($this->employee, $this->policy);

    expect($projection['current'])->toBe(40.0)
        ->and($projection['pending'])->toBe(24.0)
        ->and($projection['projected'])->toBe(16.0);
});

it('counts approved-but-unconsumed generated entries in the projection', function () {
    $request = torSubmit();
    app(DecideTimeOffRequest::class)->approve($request, $this->user);

    $projection = app(TimeOffBalanceProjection::class)->for($this->employee, $this->policy);

    expect($projection['pending'])->toBe(24.0)
        ->and($projection['projected'])->toBe(16.0);
});

// --- admin save ------------------------------------------------------------

it('lets staff record and edit a request while it awaits approval', function () {
    $request = app(SaveTimeOffRequest::class)->handle([
        'contact_id' => $this->employee->id,
        'time_off_policy_id' => $this->policy->id,
        'start_date' => '2025-06-09',
        'end_date' => '2025-06-10',
        'hours_per_day' => 8,
        'note' => 'Phoned in',
    ]);

    expect($request->status)->toBe(TimeOffRequestStatus::Pending)
        ->and((float) $request->total_hours)->toBe(16.0);

    $request = app(SaveTimeOffRequest::class)->handle([
        'contact_id' => $this->employee->id,
        'time_off_policy_id' => $this->policy->id,
        'start_date' => '2025-06-09',
        'end_date' => '2025-06-11',
        'hours_per_day' => 8,
    ], $request);

    expect((float) $request->total_hours)->toBe(24.0);

    app(DecideTimeOffRequest::class)->approve($request, $this->user);

    expect(fn () => app(SaveTimeOffRequest::class)->handle([
        'contact_id' => $this->employee->id,
        'time_off_policy_id' => $this->policy->id,
        'start_date' => '2025-06-09',
        'end_date' => '2025-06-12',
        'hours_per_day' => 8,
    ], $request->fresh()))->toThrow(ValidationException::class);
});
