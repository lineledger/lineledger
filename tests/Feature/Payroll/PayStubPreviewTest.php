<?php

use App\Actions\Payroll\SavePayRun;
use App\Enums\CompanyRole;
use App\Enums\PayBasis;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollSchedule;
use App\Models\PayRun;
use App\Models\User;
use App\Services\Payroll\CalculatePayRun;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);
    $this->schedule = PayrollSchedule::factory()->create();
    $this->employee = Contact::create(['display_name' => 'Pat Stub', 'is_employee' => true]);
    EmployeePayrollProfile::factory()->create([
        'contact_id' => $this->employee->id,
        'province_of_employment' => 'AB',
        'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 6000000,
        'payroll_schedule_id' => $this->schedule->id,
        'td1_federal_claim_cents' => 1612900,
        'td1_provincial_claim_cents' => 2232300,
        'vacation_policy' => 'accrue',
        'vacation_rate_bp' => 400,
    ]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function draftRun(): PayRun
{
    return app(SavePayRun::class)->handle([
        'payroll_schedule_id' => test()->schedule->id,
        'period_start_date' => '2025-06-01',
        'period_end_date' => '2025-06-14',
        'pay_date' => '2025-06-20',
        'lines' => [['contact_id' => test()->employee->id]],
    ]);
}

it('renders a pay-stub preview PDF for a calculated line', function () {
    $run = draftRun();
    app(CalculatePayRun::class)->calculate($run);
    $line = $run->fresh()->lines->first();

    $response = $this->get(route('payroll.pay-stub.preview', ['company' => $this->company, 'payRunLine' => $line]));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

it('does not render a stub for a draft (uncalculated) run', function () {
    $line = draftRun()->lines->first();

    $this->get(route('payroll.pay-stub.preview', ['company' => $this->company, 'payRunLine' => $line]))
        ->assertNotFound();
});
