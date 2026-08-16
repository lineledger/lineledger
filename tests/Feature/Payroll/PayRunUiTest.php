<?php

use App\Enums\CompanyRole;
use App\Enums\PayBasis;
use App\Enums\PayFrequency;
use App\Enums\PayRunStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollSchedule;
use App\Models\PayRun;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->schedule = PayrollSchedule::factory()->create();
    $this->employee = Contact::create(['display_name' => 'Riley Runner', 'is_employee' => true]);
    EmployeePayrollProfile::factory()->create([
        'contact_id' => $this->employee->id,
        'province_of_employment' => 'AB',
        'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 6000000,
        'payroll_schedule_id' => $this->schedule->id,
    ]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('renders the pay-runs index and create form', function () {
    $this->get(route('pay-runs.index', $this->company))->assertOk();
    $this->get(route('pay-runs.create', $this->company))->assertOk();
});

it('creates and calculates a pay run from the form, then redirects to the run', function () {
    Livewire::test('pages::payroll.pay-runs.form', ['company' => $this->company])
        ->set('payroll_schedule_id', $this->schedule->id)
        ->set('period_start_date', '2025-06-01')
        ->set('period_end_date', '2025-06-14')
        ->set('pay_date', '2025-06-20')
        ->set("rows.{$this->employee->id}.selected", true)
        ->call('calculate')
        ->assertHasNoErrors();

    $run = PayRun::query()->firstOrFail();

    expect($run->status)->toBe(PayRunStatus::Calculated)
        ->and($run->lines)->toHaveCount(1)
        ->and($run->gross_cents)->toBe(230769);
});

it('requires at least one employee', function () {
    $component = Livewire::test('pages::payroll.pay-runs.form', ['company' => $this->company])
        ->set('payroll_schedule_id', $this->schedule->id)
        ->set("rows.{$this->employee->id}.selected", false)
        ->call('calculate')
        ->assertHasErrors('rows');

    expect(PayRun::query()->count())->toBe(0);
});

it('posts a pay run and writes cheques from the show page', function () {
    Livewire::test('pages::payroll.pay-runs.form', ['company' => $this->company])
        ->set('payroll_schedule_id', $this->schedule->id)
        ->set('period_start_date', '2025-06-01')
        ->set('period_end_date', '2025-06-14')
        ->set('pay_date', '2025-06-20')
        ->set("rows.{$this->employee->id}.selected", true)
        ->call('calculate');

    $run = PayRun::query()->firstOrFail();

    Livewire::test('pages::payroll.pay-runs.show', ['company' => $this->company, 'payRun' => $run])
        ->call('post')
        ->assertHasNoErrors();

    expect($run->fresh()->status)->toBe(PayRunStatus::Posted);

    Livewire::test('pages::payroll.pay-runs.show', ['company' => $this->company, 'payRun' => $run->fresh()])
        ->set('startingChequeNumber', '5001')
        ->call('writeCheques')
        ->assertHasNoErrors();

    expect($run->fresh()->status)->toBe(PayRunStatus::Paid)
        ->and($run->fresh()->cheques)->toHaveCount(1)
        ->and($run->fresh()->cheques->first()->cheque_no)->toBe('5001');
});

it('auto-fills the period and pay date from the schedule anchor when there are no prior runs', function () {
    // Factory schedule: biweekly, anchor 2026-01-09, +5 day pay offset. Freeze
    // "today" before the anchor so it isn't rolled forward.
    $this->travelTo(Carbon::parse('2026-01-05 12:00'));

    Livewire::test('pages::payroll.pay-runs.form', ['company' => $this->company])
        ->assertSet('period_end_date', '2026-01-09')
        ->assertSet('period_start_date', '2025-12-27') // biweekly: 13 days before the end
        ->assertSet('pay_date', '2026-01-14');         // anchor + 5 offset days
});

it('advances to the next open period after the last pay run on the schedule', function () {
    PayRun::factory()->create([
        'payroll_schedule_id' => $this->schedule->id,
        'period_start_date' => '2025-12-27',
        'period_end_date' => '2026-01-09',
        'pay_date' => '2026-01-14',
        'status' => PayRunStatus::Posted,
    ]);

    Livewire::test('pages::payroll.pay-runs.form', ['company' => $this->company])
        ->assertSet('period_end_date', '2026-01-23')   // anchor + 2 weeks
        ->assertSet('period_start_date', '2026-01-10')
        ->assertSet('pay_date', '2026-01-28');         // +5 offset days
});

it('preserves a manual date override when the schedule changes', function () {
    $weekly = PayrollSchedule::factory()->frequency(PayFrequency::Weekly)->create();

    Livewire::test('pages::payroll.pay-runs.form', ['company' => $this->company])
        ->set('period_end_date', '2026-03-31')      // manual edit flips off auto-fill
        ->set('payroll_schedule_id', $weekly->id)   // would otherwise re-derive dates
        ->assertSet('period_end_date', '2026-03-31');
});

it('lets an operator override a deduction on the show page', function () {
    $run = PayRun::factory()->create([
        'payroll_schedule_id' => $this->schedule->id,
        'status' => PayRunStatus::Draft,
    ]);
    $line = $run->lines()->create([
        'contact_id' => $this->employee->id,
        'employee_payroll_profile_id' => $this->employee->payrollProfile->id,
        'province_of_employment' => 'AB',
        'pay_basis' => PayBasis::Salary->value,
        'gross_cents' => 230769,
        'federal_tax_computed_cents' => 20000,
        'net_cents' => 210769,
    ]);

    Livewire::test('pages::payroll.pay-runs.show', ['company' => $this->company, 'payRun' => $run])
        ->call('openAdjust', $line->id)
        ->set('adj_federal', '150.00')
        ->call('saveAdjust')
        ->assertHasNoErrors();

    expect($line->fresh()->federal_tax_override_cents)->toBe(15000)
        ->and($line->fresh()->federalTaxCents())->toBe(15000);
});
