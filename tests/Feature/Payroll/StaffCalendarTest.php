<?php

use App\Actions\Payroll\DecideTimeOffRequest;
use App\Actions\Payroll\SaveTimeOffRequest;
use App\Enums\CompanyRole;
use App\Enums\PayBasis;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\EmployeeTimeOffPolicy;
use App\Models\Membership;
use App\Models\PayrollSchedule;
use App\Models\TimeOffPolicy;
use App\Models\TimeOffRequest;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    Notification::fake();

    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    Membership::create(['company_id' => $this->company->id, 'user_id' => $this->user->id, 'role' => CompanyRole::Owner]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->schedule = PayrollSchedule::factory()->create();
    $this->employee = Contact::create(['display_name' => 'Cal Endar', 'is_employee' => true, 'is_active' => true]);
    $this->profile = EmployeePayrollProfile::factory()->create([
        'contact_id' => $this->employee->id,
        'province_of_employment' => 'ON',
        'pay_basis' => PayBasis::Hourly->value,
        'hourly_rate_cents' => 3000,
        'payroll_schedule_id' => $this->schedule->id,
        'is_active' => true,
    ]);

    $this->policy = TimeOffPolicy::create([
        'name' => 'Vacation', 'code' => 'vacation_hours', 'category' => 'vacation', 'unit' => 'hours',
        'accrual_method' => 'manual', 'rate_hours' => 0, 'rate_bp' => 0, 'paid' => true, 'is_active' => true,
    ]);
    EmployeeTimeOffPolicy::create([
        'employee_payroll_profile_id' => $this->profile->id, 'time_off_policy_id' => $this->policy->id, 'is_active' => true,
    ]);
});

afterEach(fn () => app()->forgetInstance('current_company'));

function scRequest(string $start, string $end): TimeOffRequest
{
    return app(SaveTimeOffRequest::class)->handle([
        'contact_id' => test()->employee->id,
        'time_off_policy_id' => test()->policy->id,
        'start_date' => $start,
        'end_date' => $end,
        'hours_per_day' => 8,
    ]);
}

it('shows approved and pending requests as chips, hiding pending when toggled off', function () {
    $approved = scRequest('2025-06-02', '2025-06-03');
    app(DecideTimeOffRequest::class)->approve($approved, $this->user, generateEntries: false);
    scRequest('2025-06-05', '2025-06-05'); // stays pending

    $page = Livewire::test('pages::payroll.staff-calendar', ['company' => $this->company])
        ->set('month', '2025-06');

    $page->assertSeeHtml('data-test="staff-cal-chip"')
        ->assertSee('Cal Endar');

    // Both requests render chips (2 approved days + 1 pending day).
    expect(substr_count($page->html(), 'data-test="staff-cal-chip"'))->toBe(3);

    $page->set('showPending', false);
    expect(substr_count($page->html(), 'data-test="staff-cal-chip"'))->toBe(2);
});

it('approves a pending request straight from the day panel', function () {
    $request = scRequest('2025-06-04', '2025-06-04');

    Livewire::test('pages::payroll.staff-calendar', ['company' => $this->company])
        ->set('month', '2025-06')
        ->call('managerApprove', $request->id);

    expect($request->fresh()->status->value)->toBe('manager_approved');
});

it('shows the team calendar in the portal with approved absences only, honouring the company toggle', function () {
    $approved = scRequest('2025-06-02', '2025-06-03');
    app(DecideTimeOffRequest::class)->approve($approved, $this->user, generateEntries: false);
    scRequest('2025-06-05', '2025-06-05'); // pending — must NOT show

    $this->actingAs($this->employee, 'customer');

    $page = Livewire::test('pages::employee-portal.time-off', ['company' => $this->company])
        ->set('month', '2025-06');

    expect(substr_count($page->html(), 'data-test="team-cal-chip"'))->toBe(2);

    $this->company->update(['portal_team_calendar' => false]);

    $page = Livewire::test('pages::employee-portal.time-off', ['company' => $this->company])
        ->set('month', '2025-06');

    expect(substr_count($page->html(), 'data-test="team-calendar"'))->toBe(0);
});
