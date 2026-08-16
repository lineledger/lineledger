<?php

use App\Actions\Payroll\SaveEmployeePayrollProfile;
use App\Actions\Payroll\SavePayRun;
use App\Enums\CompanyRole;
use App\Enums\PayBasis;
use App\Enums\PayRunStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\EmployeeRecurringItem;
use App\Models\PayrollSchedule;
use App\Models\PayRun;
use App\Models\User;
use App\Services\Payroll\CalculatePayRun;
use App\Services\Posting\PayRunPoster;
use App\Services\Reporting\T4SlipCalculator;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->schedule = PayrollSchedule::factory()->create();
    $this->bank = Account::query()->where('code', '1000')->firstOrFail();
    $this->employee = Contact::create(['display_name' => 'Cody Contributor', 'is_employee' => true]);
    $this->profile = EmployeePayrollProfile::factory()->create([
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

function addRecurring(EmployeePayrollProfile $profile, array $item): void
{
    app(SaveEmployeePayrollProfile::class)->handle([
        'contact_id' => $profile->contact_id,
        'province_of_employment' => $profile->province_of_employment,
        'pay_basis' => $profile->pay_basis->value,
        'annual_salary_cents' => $profile->annual_salary_cents,
        'payroll_schedule_id' => $profile->payroll_schedule_id,
        'td1_federal_claim_cents' => $profile->td1_federal_claim_cents,
        'td1_provincial_claim_cents' => $profile->td1_provincial_claim_cents,
        'vacation_policy' => $profile->vacation_policy->value,
        'vacation_rate_bp' => $profile->vacation_rate_bp,
        'recurring_items' => [$item],
    ], $profile->fresh());
}

function newRun(): PayRun
{
    return app(SavePayRun::class)->handle([
        'payroll_schedule_id' => test()->schedule->id,
        'period_start_date' => '2025-06-01',
        'period_end_date' => '2025-06-14',
        'pay_date' => '2025-06-20',
        'bank_account_id' => test()->bank->id,
        'lines' => [['contact_id' => test()->employee->id]],
    ]);
}

function jeSide(PayRun $run, string $code, string $side): int
{
    $accountId = Account::query()->where('code', $code)->value('id');

    return (int) $run->journalEntry->lines->where('account_id', $accountId)->sum($side.'_cents');
}

it('posts an employer contribution as DR expense / CR liability without changing net pay', function () {
    addRecurring($this->profile, [
        'kind' => 'contribution', 'code' => 'extended_health', 'name' => 'Extended Health ER',
        'calc_type' => 'fixed', 'amount_cents' => 10000,
    ]);

    $run = newRun();
    app(CalculatePayRun::class)->calculate($run);
    $line = $run->fresh()->lines->first();

    // The contribution is recorded but is NOT part of the employee deduction total or net.
    expect((int) $line->contributions->firstWhere('code', 'extended_health')?->amount_cents)->toBe(10000)
        ->and($line->net_cents)->toBe($line->gross_cents - $line->totalEmployeeDeductionsCents());

    $netBefore = $line->net_cents;

    app(PayRunPoster::class)->post($run->fresh());
    $run = $run->fresh()->load('journalEntry.lines', 'lines');

    // DR 6260 (employer benefit expense) / CR 2470 (employee benefits payable), balanced.
    expect(jeSide($run, '6260', 'debit'))->toBe(10000)
        ->and(jeSide($run, '2470', 'credit'))->toBe(10000)
        ->and($run->journalEntry->lines->sum('debit_cents'))->toBe($run->journalEntry->lines->sum('credit_cents'))
        ->and($run->lines->first()->net_cents)->toBe($netBefore); // net unchanged by the employer cost
});

it('stops an employer contribution once its annual maximum is reached', function () {
    addRecurring($this->profile, [
        'kind' => 'contribution', 'code' => 'rrsp_match', 'name' => 'RRSP match',
        'calc_type' => 'fixed', 'amount_cents' => 20000, 'annual_maximum_cents' => 50000,
    ]);

    // Prior POSTED run already contributed $400 this year (room left = $100).
    $prior = PayRun::factory()->create([
        'payroll_schedule_id' => $this->schedule->id,
        'pay_date' => '2025-06-06',
        'status' => PayRunStatus::Posted,
    ]);
    $priorLine = $prior->lines()->create([
        'contact_id' => $this->employee->id,
        'employee_payroll_profile_id' => $this->profile->id,
        'province_of_employment' => 'AB',
        'pay_basis' => PayBasis::Salary->value,
    ]);
    $priorLine->contributions()->create(['code' => 'rrsp_match', 'name' => 'RRSP match', 'amount_cents' => 40000]);

    $run = newRun();
    app(CalculatePayRun::class)->calculate($run);
    $line = $run->fresh()->lines->first();

    // min($20000, room $10000) = $10000.
    expect((int) $line->contributions->firstWhere('code', 'rrsp_match')?->amount_cents)->toBe(10000);
});

it('stops a recurring deduction once its annual maximum is reached', function () {
    addRecurring($this->profile, [
        'kind' => 'deduction', 'code' => 'union', 'name' => 'Union dues',
        'calc_type' => 'fixed', 'amount_cents' => 20000, 'annual_maximum_cents' => 50000,
    ]);

    $prior = PayRun::factory()->create([
        'payroll_schedule_id' => $this->schedule->id,
        'pay_date' => '2025-06-06',
        'status' => PayRunStatus::Posted,
    ]);
    $priorLine = $prior->lines()->create([
        'contact_id' => $this->employee->id,
        'employee_payroll_profile_id' => $this->profile->id,
        'province_of_employment' => 'AB',
        'pay_basis' => PayBasis::Salary->value,
    ]);
    $priorLine->deductions()->create(['code' => 'union', 'name' => 'Union dues', 'amount_cents' => 40000]);

    $run = newRun();
    app(CalculatePayRun::class)->calculate($run);
    $line = $run->fresh()->lines->first();

    expect((int) $line->deductions->firstWhere('code', 'union')?->amount_cents)->toBe(10000);
});

it('surfaces a box-40 taxable-benefit contribution on the T4 slip', function () {
    addRecurring($this->profile, [
        'kind' => 'contribution', 'code' => 'taxable_benefit', 'name' => 'Group life (taxable)',
        'calc_type' => 'fixed', 'amount_cents' => 5000, 't4_box' => '40',
    ]);

    $run = newRun();
    app(CalculatePayRun::class)->calculate($run);
    app(PayRunPoster::class)->post($run->fresh());

    $slips = app(T4SlipCalculator::class)->slipsForYear($this->company, 2025);

    expect($slips[0]['other']['40'] ?? 0)->toBe(5000);
});

it('adds an employer contribution through the employee form', function () {
    Livewire::test('pages::payroll.employees.form', ['company' => $this->company, 'contact' => $this->employee->fresh()])
        ->set('province_of_employment', 'AB')
        ->set('pay_basis', 'salary')
        ->set('annual_salary', '60000')
        ->set('td1_federal_claim', '16129')
        ->set('td1_provincial_claim', '22323')
        ->call('addRecurringItem', 'contribution')
        ->set('recurring_items.0.name', 'MSP ER')
        ->set('recurring_items.0.code', 'msp')
        ->set('recurring_items.0.amount', '75')
        ->set('recurring_items.0.annual_maximum', '900')
        ->call('save')
        ->assertHasNoErrors();

    $item = EmployeeRecurringItem::query()->firstOrFail();
    expect($item->kind)->toBe('contribution')
        ->and($item->amount_cents)->toBe(7500)
        ->and($item->annual_maximum_cents)->toBe(90000);
});
