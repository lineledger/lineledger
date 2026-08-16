<?php

use App\Enums\CompanyRole;
use App\Enums\PayBasis;
use App\Models\Company;
use App\Models\Contact;
use App\Models\PayrollSchedule;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('renders the payroll overview, employee setup and schedules pages', function () {
    $this->get(route('payroll.index', $this->company))->assertOk();
    $this->get(route('payroll.employees.index', $this->company))->assertOk();
    $this->get(route('payroll-schedules.index', $this->company))->assertOk();
});

it('creates a pay schedule and denormalizes periods per year', function () {
    Livewire::test('pages::payroll.schedules.index', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_name', 'Biweekly staff')
        ->set('f_frequency', 'biweekly')
        ->set('f_anchor_period_end_date', '2026-01-09')
        ->set('f_default_pay_offset_days', 5)
        ->call('save')
        ->assertHasNoErrors();

    $schedule = PayrollSchedule::query()->firstOrFail();

    expect($schedule->name)->toBe('Biweekly staff')
        ->and($schedule->periods_per_year)->toBe(26);
});

it('sets up an employee payroll profile with encrypted SIN and salary in cents', function () {
    $schedule = PayrollSchedule::factory()->create();
    $employee = Contact::create(['display_name' => 'Sam Salary', 'is_employee' => true]);

    Livewire::test('pages::payroll.employees.form', ['company' => $this->company, 'contact' => $employee])
        ->set('province_of_employment', 'BC')
        ->set('pay_basis', 'salary')
        ->set('annual_salary', '72,000.00')
        ->set('payroll_schedule_id', $schedule->id)
        ->set('sin', '123 456 789')
        ->set('td1_federal_claim', '16,599.00')
        ->set('td1_provincial_claim', '12,580.00')
        ->set('vacation_rate_pct', 4)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('payroll.employees.index', $this->company));

    $profile = $employee->fresh()->payrollProfile;

    expect($profile)->not->toBeNull()
        ->and($profile->province_of_employment)->toBe('BC')
        ->and($profile->pay_basis)->toBe(PayBasis::Salary)
        ->and($profile->annual_salary_cents)->toBe(7200000)
        ->and($profile->td1_federal_claim_cents)->toBe(1659900)
        ->and($profile->vacation_rate_bp)->toBe(400)
        ->and($profile->sin_last4)->toBe('6789')
        ->and($profile->sin_encrypted)->toBe('123456789');
});

it('accepts Quebec as a province of employment and persists the QPIP exemption', function () {
    $schedule = PayrollSchedule::factory()->create();
    $employee = Contact::create(['display_name' => 'Quincy Quebec', 'is_employee' => true]);

    Livewire::test('pages::payroll.employees.form', ['company' => $this->company, 'contact' => $employee])
        ->set('province_of_employment', 'QC')
        ->set('pay_basis', 'salary')
        ->set('annual_salary', '50000')
        ->set('payroll_schedule_id', $schedule->id)
        ->set('td1_federal_claim', '16599')
        ->set('td1_provincial_claim', '18571')
        ->set('qpip_exempt', true)
        ->set('vacation_rate_pct', 4)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('payroll.employees.index', $this->company));

    $profile = $employee->fresh()->payrollProfile;

    expect($profile->province_of_employment)->toBe('QC')
        ->and($profile->qpip_exempt)->toBeTrue();
});

it('does not persist a QPIP exemption for a rest-of-Canada employee', function () {
    $schedule = PayrollSchedule::factory()->create();
    $employee = Contact::create(['display_name' => 'Ron Roc', 'is_employee' => true]);

    Livewire::test('pages::payroll.employees.form', ['company' => $this->company, 'contact' => $employee])
        ->set('province_of_employment', 'ON')
        ->set('pay_basis', 'salary')
        ->set('annual_salary', '50000')
        ->set('payroll_schedule_id', $schedule->id)
        ->set('td1_federal_claim', '16599')
        ->set('td1_provincial_claim', '12747')
        ->set('qpip_exempt', true) // ignored for non-QC
        ->call('save')
        ->assertHasNoErrors();

    expect($employee->fresh()->payrollProfile->qpip_exempt)->toBeFalse();
});

it('hides payroll for non-payroll companies via 404', function () {
    $this->company->update(['features_payroll' => false]);

    $this->get(route('payroll.index', $this->company))->assertNotFound();
});
