<?php

use App\Actions\Payroll\SaveEmployeePayrollProfile;
use App\Actions\Payroll\SavePayRun;
use App\Enums\CompanyRole;
use App\Enums\PayBasis;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeeAccrualBalance;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollSchedule;
use App\Models\PayRun;
use App\Models\User;
use App\Services\Payroll\CalculatePayRun;
use App\Services\Posting\PayRunPoster;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->schedule = PayrollSchedule::factory()->create();
    $this->bank = Account::query()->where('code', '1000')->firstOrFail();
    $this->employee = Contact::create(['display_name' => 'Avery Accrual', 'is_employee' => true]);
    $this->profile = EmployeePayrollProfile::factory()->create([
        'contact_id' => $this->employee->id,
        'province_of_employment' => 'AB',
        'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 6000000,
        'payroll_schedule_id' => $this->schedule->id,
        'td1_federal_claim_cents' => 1612900,
        'td1_provincial_claim_cents' => 2232300,
        'vacation_policy' => 'accrue',
        'vacation_rate_bp' => 0, // isolate the accrual item from built-in vacation
    ]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/** @param array<string, mixed> $accrual */
function accrualRun(array $accrual): PayRun
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
        'vacation_rate_bp' => 0,
        'recurring_items' => [array_merge(['kind' => 'accrual'], $accrual)],
    ], test()->employee->fresh()->payrollProfile);

    $run = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => test()->schedule->id,
        'period_start_date' => '2025-06-01',
        'period_end_date' => '2025-06-14',
        'pay_date' => '2025-06-20',
        'bank_account_id' => test()->bank->id,
        'lines' => [['contact_id' => test()->employee->id]],
    ]);
    app(CalculatePayRun::class)->calculate($run);

    return $run->fresh();
}

function jeAmount(PayRun $run, string $code, string $side): int
{
    $accountId = Account::query()->where('code', $code)->value('id');

    return (int) $run->journalEntry->lines->where('account_id', $accountId)->sum($side.'_cents');
}

it('posts a dollar accrual to the GL and records a dollar balance', function () {
    $run = accrualRun([
        'code' => 'banked_pay', 'name' => 'Banked pay', 'calc_basis' => 'percent_of_earnings', 'percent_bp' => 200,
    ]);

    $line = $run->lines->first();
    // 2% of $2,307.69 = $46.15.
    expect((int) $line->accruals->firstWhere('code', 'banked_pay')?->amount_cents)->toBe(4615);

    app(PayRunPoster::class)->post($run->fresh());
    $run = $run->fresh()->load('journalEntry.lines');

    expect(jeAmount($run, '6230', 'debit'))->toBe(4615)   // default accrual expense (Vacation Pay Expense)
        ->and(jeAmount($run, '2430', 'credit'))->toBe(4615) // default accrual liability (Vacation Payable)
        ->and($run->journalEntry->lines->sum('debit_cents'))->toBe($run->journalEntry->lines->sum('credit_cents'));

    $balance = EmployeeAccrualBalance::query()->where('code', 'banked_pay')->firstOrFail();
    expect($balance->balance_cents)->toBe(4615);
});

it('records an hour accrual as a balance with no GL impact', function () {
    $run = accrualRun([
        'code' => 'sick', 'name' => 'Sick hours', 'calc_basis' => 'hours', 'amount_cents' => 400, // 4.00 hrs
    ]);

    $line = $run->lines->first();
    expect((float) $line->accruals->firstWhere('code', 'sick')?->hours)->toBe(4.0)
        ->and((int) $line->accruals->firstWhere('code', 'sick')?->amount_cents)->toBe(0);

    app(PayRunPoster::class)->post($run->fresh());

    $balance = EmployeeAccrualBalance::query()->where('code', 'sick')->firstOrFail();
    expect((float) $balance->balance_hours)->toBe(4.0)
        ->and($balance->balance_cents)->toBe(0);
});

it('reverses accrual balances when the run is voided', function () {
    $run = accrualRun([
        'code' => 'sick', 'name' => 'Sick hours', 'calc_basis' => 'hours', 'amount_cents' => 400,
    ]);
    app(PayRunPoster::class)->post($run->fresh());

    expect((float) EmployeeAccrualBalance::query()->where('code', 'sick')->firstOrFail()->balance_hours)->toBe(4.0);

    app(PayRunPoster::class)->void($run->fresh());

    expect((float) EmployeeAccrualBalance::query()->where('code', 'sick')->firstOrFail()->balance_hours)->toBe(0.0);
});
