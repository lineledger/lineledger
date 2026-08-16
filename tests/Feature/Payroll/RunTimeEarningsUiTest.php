<?php

use App\Actions\Payroll\SaveEmployeePayrollProfile;
use App\Actions\Payroll\SavePayRun;
use App\Enums\CompanyRole;
use App\Enums\PayBasis;
use App\Enums\PayRunStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollSchedule;
use App\Models\PayRun;
use App\Models\User;
use App\Services\Payroll\CalculatePayRun;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);
    $this->schedule = PayrollSchedule::factory()->create();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/** @param array<string, mixed> $overrides */
function payrollEmployee(string $name, array $overrides = []): Contact
{
    $contact = Contact::create(['display_name' => $name, 'is_employee' => true]);
    EmployeePayrollProfile::factory()->create(array_merge([
        'contact_id' => $contact->id,
        'province_of_employment' => 'AB',
        'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 6000000,
        'payroll_schedule_id' => test()->schedule->id,
        'td1_federal_claim_cents' => 1612900,
        'td1_provincial_claim_cents' => 2232300,
        'vacation_policy' => 'accrue',
        'vacation_rate_bp' => 400,
    ], $overrides));

    return $contact;
}

function payRunForm(Contact $employee)
{
    return Livewire::test('pages::payroll.pay-runs.form', ['company' => test()->company])
        ->set('payroll_schedule_id', test()->schedule->id)
        ->set('period_start_date', '2025-06-01')
        ->set('period_end_date', '2025-06-14')
        ->set('pay_date', '2025-06-20')
        ->set("rows.{$employee->id}.selected", true);
}

it('adds a one-off bonus on the pay run and includes it in gross', function () {
    $emp = payrollEmployee('Sam Salary');

    payRunForm($emp)
        ->call('addManualEarning', $emp->id)
        ->set("rows.{$emp->id}.manual_earnings.0.code", 'bonus')
        ->set("rows.{$emp->id}.manual_earnings.0.value", '500')
        ->call('calculate')
        ->assertHasNoErrors();

    $line = PayRun::query()->firstOrFail()->lines->first();
    // $60,000/26 = $2,307.69 + $500 bonus = $2,807.69.
    expect($line->gross_cents)->toBe(280769)
        ->and($line->earnings->firstWhere('code', 'bonus')?->amount_cents)->toBe(50000);
});

it('computes overtime as rate × 1.5 × hours', function () {
    $emp = payrollEmployee('Holly Hourly', [
        'pay_basis' => PayBasis::Hourly->value,
        'annual_salary_cents' => null,
        'hourly_rate_cents' => 3000, // $30/hr
        'default_hours_per_period' => 80,
    ]);

    payRunForm($emp)
        ->set("rows.{$emp->id}.hours", '80')
        ->call('addManualEarning', $emp->id)
        ->set("rows.{$emp->id}.manual_earnings.0.code", 'overtime')
        ->set("rows.{$emp->id}.manual_earnings.0.value", '10')
        ->call('calculate')
        ->assertHasNoErrors();

    $line = PayRun::query()->firstOrFail()->lines->first();
    // Regular 80h × $30 = $2,400; OT 10h × $30 × 1.5 = $450 → gross $2,850.
    expect($line->earnings->firstWhere('code', 'overtime')?->amount_cents)->toBe(45000)
        ->and($line->gross_cents)->toBe(285000);
});

it('derives an overtime rate from salary for a salaried employee', function () {
    $emp = payrollEmployee('Sal Aried'); // $60,000 salary, no hourly rate

    payRunForm($emp)
        ->call('addManualEarning', $emp->id)
        ->set("rows.{$emp->id}.manual_earnings.0.code", 'overtime_double')
        ->set("rows.{$emp->id}.manual_earnings.0.value", '1')
        ->call('calculate')
        ->assertHasNoErrors();

    $line = PayRun::query()->firstOrFail()->lines->first();
    // Derived rate $60,000 / 2080 = $28.85/hr; 2× × 1h = $57.70 — added to gross.
    expect($line->earnings->firstWhere('code', 'overtime_double')?->amount_cents)->toBe(5770)
        ->and($line->gross_cents)->toBe(230769 + 5770);
});

it('keeps run-time earnings across a recalculation', function () {
    $emp = payrollEmployee('Rita Recalc');

    payRunForm($emp)
        ->call('addManualEarning', $emp->id)
        ->set("rows.{$emp->id}.manual_earnings.0.code", 'bonus')
        ->set("rows.{$emp->id}.manual_earnings.0.value", '500')
        ->call('calculate');

    $run = PayRun::query()->firstOrFail();
    expect($run->fresh()->lines->first()->gross_cents)->toBe(280769);

    // Recalculate from scratch — the manual bonus input must persist.
    $run->fresh()->forceFill(['status' => PayRunStatus::Draft])->save();
    app(CalculatePayRun::class)->calculate($run->fresh());

    $line = $run->fresh()->lines->first();
    expect($line->gross_cents)->toBe(280769)
        ->and($line->earnings->firstWhere('code', 'bonus')?->amount_cents)->toBe(50000);
});

it('pays a commission-only employee from a run-time commission earning', function () {
    $emp = payrollEmployee('Cory Commission', [
        'pay_basis' => PayBasis::Commission->value,
        'annual_salary_cents' => null,
    ]);

    payRunForm($emp)
        ->call('addManualEarning', $emp->id)
        ->set("rows.{$emp->id}.manual_earnings.0.code", 'commission')
        ->set("rows.{$emp->id}.manual_earnings.0.value", '1000')
        ->call('calculate')
        ->assertHasNoErrors();

    $line = PayRun::query()->firstOrFail()->lines->first();
    // No base pay; $1,000 commission only.
    expect($line->gross_cents)->toBe(100000)
        ->and($line->regular_earnings_cents)->toBe(0)
        ->and($line->earnings->firstWhere('code', 'commission')?->amount_cents)->toBe(100000);
});

it('treats a reimbursement earning as non-pensionable, non-insurable and non-taxable', function () {
    $emp = payrollEmployee('Reggie Reimburse');

    app(SaveEmployeePayrollProfile::class)->handle([
        'contact_id' => $emp->id,
        'province_of_employment' => 'AB',
        'pay_basis' => 'salary',
        'annual_salary_cents' => 6000000,
        'payroll_schedule_id' => $this->schedule->id,
        'td1_federal_claim_cents' => 1612900,
        'td1_provincial_claim_cents' => 2232300,
        'vacation_policy' => 'accrue',
        'vacation_rate_bp' => 400,
        'recurring_items' => [[
            'kind' => 'earning', 'code' => 'reimbursement', 'name' => 'Mileage',
            'calc_type' => 'fixed', 'amount_cents' => 20000,
        ]],
    ], $emp->fresh()->payrollProfile);

    $run = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => $this->schedule->id,
        'period_start_date' => '2025-06-01',
        'period_end_date' => '2025-06-14',
        'pay_date' => '2025-06-20',
        'lines' => [['contact_id' => $emp->id]],
    ]);
    app(CalculatePayRun::class)->calculate($run);
    $line = $run->fresh()->lines->first();

    $reimbursement = $line->earnings->firstWhere('code', 'reimbursement');
    expect($reimbursement->is_taxable)->toBeFalse()
        ->and($reimbursement->is_pensionable)->toBeFalse()
        ->and($reimbursement->is_insurable)->toBeFalse()
        // Regular pay is pensionable; the reimbursement is excluded from the base.
        ->and($line->cpp_pensionable_cents)->toBe(230769);
});
