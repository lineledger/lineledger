<?php

use App\Actions\Payroll\SaveEmployeePayrollProfile;
use App\Actions\Payroll\SavePayRun;
use App\Enums\CompanyRole;
use App\Enums\PayBasis;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollSchedule;
use App\Models\PayRunLine;
use App\Models\User;
use App\Services\Payroll\CalculatePayRun;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->schedule = PayrollSchedule::factory()->create();
    $this->employee = Contact::create(['display_name' => 'Flag Tester', 'is_employee' => true]);
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

/** @param array<string, mixed> $item */
function flagItemLine(array $item): PayRunLine
{
    app(SaveEmployeePayrollProfile::class)->handle([
        'contact_id' => test()->employee->id,
        'province_of_employment' => 'AB',
        'pay_basis' => 'salary',
        'annual_salary_cents' => 6000000,
        'payroll_schedule_id' => test()->schedule->id,
        'td1_federal_claim_cents' => 1612900,
        'td1_provincial_claim_cents' => 2232300,
        'vacation_policy' => 'accrue',
        'vacation_rate_bp' => 400,
        'recurring_items' => [$item],
    ], test()->employee->fresh()->payrollProfile);

    $run = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => test()->schedule->id,
        'period_start_date' => '2025-06-01',
        'period_end_date' => '2025-06-14',
        'pay_date' => '2025-06-20',
        'lines' => [['contact_id' => test()->employee->id]],
    ]);
    app(CalculatePayRun::class)->calculate($run);

    return $run->fresh()->lines->first();
}

it('excludes a non-pensionable earning from CPP pensionable earnings', function () {
    $line = flagItemLine([
        'kind' => 'earning', 'code' => 'misc', 'name' => 'Misc earning',
        'calc_type' => 'fixed', 'amount_cents' => 50000, 'cpp_qpp' => false,
    ]);

    // The $500 earning is taxable/insurable but NOT pensionable: gross includes it,
    // CPP pensionable stays at the regular pay only.
    expect($line->gross_cents)->toBe(280769)
        ->and($line->cpp_pensionable_cents)->toBe(230769);
});

it('keeps a net-pay-only earning out of gross but in net pay', function () {
    $line = flagItemLine([
        'kind' => 'earning', 'code' => 'reimb', 'name' => 'Mileage reimbursement',
        'calc_type' => 'fixed', 'amount_cents' => 20000, 'add_to_net_pay_only' => true,
    ]);

    // Excluded from gross/box-14, but the employee still receives the $200.
    expect($line->gross_cents)->toBe(230769)
        ->and($line->net_cents - ($line->gross_cents - $line->totalEmployeeDeductionsCents()))->toBe(20000);
});

it('lowers income tax for a pre-tax deduction', function () {
    $preTax = flagItemLine([
        'kind' => 'deduction', 'code' => 'rrsp', 'name' => 'RRSP', 'calc_type' => 'fixed',
        'amount_cents' => 50000, 'pre_tax_federal' => true, 'pre_tax_provincial' => true,
    ]);
    $afterTax = flagItemLine([
        'kind' => 'deduction', 'code' => 'rrsp', 'name' => 'RRSP', 'calc_type' => 'fixed',
        'amount_cents' => 50000, 'pre_tax_federal' => false, 'pre_tax_provincial' => false,
    ]);

    expect($preTax->federal_tax_computed_cents)->toBeLessThan($afterTax->federal_tax_computed_cents);
});
