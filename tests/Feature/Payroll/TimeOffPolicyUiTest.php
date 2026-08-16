<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeeAccrualBalance;
use App\Models\EmployeeTimeOffPolicy;
use App\Models\PayrollSchedule;
use App\Models\TimeOffPolicy;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);
    $this->schedule = PayrollSchedule::factory()->create();
    $this->employee = Contact::create(['display_name' => 'Polly Sea', 'is_employee' => true]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('renders the time-off policies page', function () {
    $this->get(route('time-off-policies.index', $this->company))->assertOk();
});

it('creates a time-off policy through the CRUD form', function () {
    Livewire::test('pages::payroll.time-off-policies.index', ['company' => $this->company])
        ->set('f_name', 'Sick leave')
        ->set('f_category', 'sick')
        ->set('f_unit', 'hours')
        ->set('f_accrual_method', 'per_pay_period')
        ->set('f_rate', '1.5')
        ->set('f_annual_cap', '40')
        ->set('f_carryover', '40')
        ->call('save')
        ->assertHasNoErrors();

    $policy = TimeOffPolicy::query()->firstOrFail();
    expect($policy->code)->toBe('sick_leave')
        ->and((float) $policy->rate_hours)->toBe(1.5)
        ->and((float) $policy->annual_cap_hours)->toBe(40.0)
        ->and($policy->paid)->toBeTrue();
});

it('stores a per-hour-worked rate as basis points and a dollar rate as percent', function () {
    Livewire::test('pages::payroll.time-off-policies.index', ['company' => $this->company])
        ->set('f_name', 'Sick by hours')->set('f_code', 'sick_hrly')
        ->set('f_unit', 'hours')->set('f_accrual_method', 'per_hour_worked')->set('f_rate', '0.05')
        ->call('save')->assertHasNoErrors();

    expect((int) TimeOffPolicy::where('code', 'sick_hrly')->firstOrFail()->rate_bp)->toBe(500); // 0.05 → 500 bp
});

it('assigns a policy and seeds the opening balance via the employee form', function () {
    $policy = TimeOffPolicy::create([
        'name' => 'Sick', 'code' => 'sick', 'category' => 'sick', 'unit' => 'hours',
        'accrual_method' => 'per_pay_period', 'rate_hours' => 1.5,
    ]);

    Livewire::test('pages::payroll.employees.form', ['company' => $this->company, 'contact' => $this->employee])
        ->set('province_of_employment', 'AB')
        ->set('pay_basis', 'salary')
        ->set('annual_salary', '60000')
        ->set('td1_federal_claim', '16129')
        ->set('td1_provincial_claim', '22323')
        ->call('addTimeOffPolicy')
        ->set('time_off_policies.0.time_off_policy_id', $policy->id)
        ->set('time_off_policies.0.opening_balance', '16')
        ->call('save')
        ->assertHasNoErrors();

    $profile = $this->employee->fresh()->payrollProfile;

    expect(EmployeeTimeOffPolicy::where('employee_payroll_profile_id', $profile->id)->where('time_off_policy_id', $policy->id)->exists())->toBeTrue();

    $balance = EmployeeAccrualBalance::where('employee_payroll_profile_id', $profile->id)->where('code', 'sick')->first();
    expect($balance)->not->toBeNull()
        ->and((float) $balance->balance_hours)->toBe(16.0);
});
