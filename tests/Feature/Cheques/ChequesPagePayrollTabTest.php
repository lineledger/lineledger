<?php

use App\Enums\CompanyRole;
use App\Enums\PayBasis;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\Membership;
use App\Models\PayrollCheque;
use App\Models\PayrollSchedule;
use App\Models\PayRun;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| Cheques page — payroll cheques tab
|--------------------------------------------------------------------------
| Payroll cheques live in their own table (written from a pay run), so the
| cheques page exposes them on a separate tab instead of leaving them
| invisible outside the pay-run screen.
*/

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    Membership::create(['company_id' => $this->company->id, 'user_id' => $this->user->id, 'role' => CompanyRole::Owner]);
    actingAs($this->user);
    app()->instance('current_company', $this->company);
});

afterEach(fn () => app()->forgetInstance('current_company'));

function payrollTabChequeFixtures(): PayrollCheque
{
    $bank = Account::query()->where('code', '1000')->firstOrFail();
    $schedule = PayrollSchedule::factory()->create();

    $employee = Contact::create(['display_name' => 'Paye Roll', 'is_employee' => true, 'is_active' => true]);
    $profile = EmployeePayrollProfile::factory()->create([
        'contact_id' => $employee->id, 'province_of_employment' => 'AB',
        'pay_basis' => PayBasis::Hourly->value, 'hourly_rate_cents' => 3000,
        'payroll_schedule_id' => $schedule->id, 'is_active' => true,
    ]);

    $run = PayRun::create([
        'payroll_schedule_id' => $schedule->id,
        'run_no' => 'PR-000077',
        'status' => 'draft',
        'period_start_date' => '2026-05-24',
        'period_end_date' => '2026-06-06',
        'pay_date' => '2026-06-07',
        'bank_account_id' => $bank->id,
    ]);

    $line = $run->lines()->create([
        'company_id' => $run->company_id,
        'contact_id' => $employee->id,
        'employee_payroll_profile_id' => $profile->id,
        'province_of_employment' => 'AB',
        'pay_basis' => PayBasis::Hourly->value,
    ]);

    return PayrollCheque::create([
        'pay_run_id' => $run->id,
        'pay_run_line_id' => $line->id,
        'bank_account_id' => $bank->id,
        'cheque_no' => '9001',
        'cheque_date' => '2026-06-07',
        'payee_contact_id' => $employee->id,
        'payee_name' => 'Paye Roll',
        'amount_cents' => 196304,
        'status' => 'posted',
    ]);
}

it('lists payroll cheques on the payroll tab with a link to their pay run', function () {
    $cheque = payrollTabChequeFixtures();

    $response = get(route('cheques.index', ['company' => $this->company->slug, 'tab' => 'payroll']));

    $response->assertOk()
        ->assertSee('9001')
        ->assertSee('Paye Roll')
        ->assertSee('PR-000077')
        ->assertSee(route('pay-runs.show', ['company' => $this->company->slug, 'payRun' => $cheque->pay_run_id]), false);
});

it('shows both expense and payroll cheques together on the combined cheques page', function () {
    payrollTabChequeFixtures();

    $bank = Account::query()->where('code', '1000')->firstOrFail();
    Cheque::create([
        'bank_account_id' => $bank->id,
        'cheque_no' => '777',
        'cheque_date' => '2026-06-01',
        'payee_name' => 'Office Depot',
    ]);

    get(route('cheques.index', ['company' => $this->company->slug]))
        ->assertOk()
        ->assertSee('Office Depot')
        ->assertSee('Paye Roll');
});

it('hides the payroll tab for companies without payroll', function () {
    $this->company->update(['features_payroll' => false]);

    get(route('cheques.index', ['company' => $this->company->slug]))
        ->assertOk()
        ->assertDontSee('data-test="cheques-tab"', false);
});
