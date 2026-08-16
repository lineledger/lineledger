<?php

use App\Actions\Payroll\TerminateEmployee;
use App\Enums\CompanyRole;
use App\Enums\PayBasis;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollSchedule;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->schedule = PayrollSchedule::factory()->create();
    $this->employee = Contact::create(['display_name' => 'Quincy Quitter', 'is_employee' => true]);
    $this->profile = EmployeePayrollProfile::factory()->create([
        'contact_id' => $this->employee->id,
        'province_of_employment' => 'AB',
        'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 6000000,
        'payroll_schedule_id' => $this->schedule->id,
        'td1_federal_claim_cents' => 1612900,
        'td1_provincial_claim_cents' => 2232300,
        'is_active' => true,
    ]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('stamps the termination date and deactivates the profile', function () {
    app(TerminateEmployee::class)->handle($this->profile, '2025-03-20');

    $fresh = $this->profile->fresh();
    expect($fresh->termination_date->toDateString())->toBe('2025-03-20')
        ->and($fresh->is_active)->toBeFalse();
});

it('terminates and deep-links to a prefilled ROE from the employee form', function () {
    Livewire::test('pages::payroll.employees.form', ['company' => $this->company, 'contact' => $this->employee->fresh()])
        ->call('openTerminate')
        ->set('f_term_last_day', '2025-03-20')
        ->set('f_term_reason', 'E') // quit
        ->call('terminate')
        ->assertRedirect(route('payroll.reports.roe', [
            'company' => $this->company,
            'contact' => $this->employee->id,
            'reason' => 'E',
            'last' => '2025-03-20',
        ]));

    expect($this->profile->fresh()->termination_date->toDateString())->toBe('2025-03-20')
        ->and($this->profile->fresh()->is_active)->toBeFalse();
});

it('prefills the ROE page from the deep-link query params', function () {
    Livewire::withQueryParams(['contact' => $this->employee->id, 'reason' => 'E', 'last' => '2025-03-20'])
        ->test('pages::payroll.reports.roe', ['company' => $this->company])
        ->assertSet('contactId', $this->employee->id)
        ->assertSet('reason', 'E')
        ->assertSet('lastDay', '2025-03-20');
});
