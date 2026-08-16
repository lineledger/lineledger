<?php

use App\Actions\Payroll\SaveEmployeePayrollProfile;
use App\Actions\Payroll\SavePayRun;
use App\Enums\CompanyRole;
use App\Enums\PayBasis;
use App\Http\Controllers\Payroll\PrintPayStubController;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollSchedule;
use App\Models\PayRunLine;
use App\Models\User;
use App\Services\Payroll\CalculatePayRun;
use App\Services\Posting\PayRunPoster;
use App\Services\Reporting\PayStatementYtdCalculator;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);
    $this->schedule = PayrollSchedule::factory()->create();
    $this->bank = Account::query()->where('code', '1000')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * Build a calculated pay-run line for an employee in a province, optionally with a
 * taxable benefit, and return its rendered pay-statement HTML.
 */
function psrRender(string $province, array $opts = []): string
{
    $contact = Contact::create([
        'display_name' => $opts['name'] ?? 'Statement Subject',
        'is_employee' => true,
        'job_title' => $opts['job_title'] ?? null,
        'employee_id' => $opts['employee_id'] ?? null,
    ]);

    EmployeePayrollProfile::factory()->create([
        'contact_id' => $contact->id,
        'province_of_employment' => $province,
        'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 6000000,
        'payroll_schedule_id' => test()->schedule->id,
        'td1_federal_claim_cents' => 1612900,
        'td1_provincial_claim_cents' => 2232300,
        'vacation_policy' => 'accrue',
        'vacation_rate_bp' => 400,
    ]);

    if (! empty($opts['benefit'])) {
        app(SaveEmployeePayrollProfile::class)->handle([
            'contact_id' => $contact->id,
            'province_of_employment' => $province,
            'pay_basis' => 'salary',
            'annual_salary_cents' => 6000000,
            'payroll_schedule_id' => test()->schedule->id,
            'td1_federal_claim_cents' => 1612900,
            'td1_provincial_claim_cents' => 2232300,
            'vacation_policy' => 'accrue',
            'vacation_rate_bp' => 400,
            'recurring_items' => [[
                'kind' => 'contribution', 'code' => 'group_life', 'name' => 'Group life (taxable)',
                'calc_type' => 'fixed', 'amount_cents' => 10000, 't4_box' => '40',
                'taxable_federal' => true, 'taxable_provincial' => true, 'cpp_qpp' => true, 'ei_insurable_earnings' => false,
            ]],
        ], $contact->fresh()->payrollProfile);
    }

    $run = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => test()->schedule->id,
        'period_start_date' => '2025-06-01',
        'period_end_date' => '2025-06-14',
        'pay_date' => '2025-06-20',
        'bank_account_id' => test()->bank->id,
        'lines' => [['contact_id' => $contact->id]],
    ]);
    app(CalculatePayRun::class)->calculate($run);

    if (! empty($opts['post'])) {
        app(PayRunPoster::class)->post($run->fresh());
    }

    /** @var PayRunLine $line */
    $line = $run->fresh()->lines->first();

    $data = app(PrintPayStubController::class)->viewData(test()->company->fresh(), $line, app(PayStatementYtdCalculator::class));

    return view('pdf.reports.pay-stub', $data)->render();
}

it('titles the statement and cites the legislation for the province of employment', function () {
    $ab = psrRender('AB');
    expect($ab)->toContain('Pay Statement')
        ->and($ab)->toContain('Employment Standards Code')
        ->and($ab)->toContain('Retain for');

    expect(psrRender('BC'))->toContain('Wage Statement');
    expect(psrRender('QC'))->toContain('Pay Sheet');
});

it('uses the Canada Labour Code statement for a federally regulated employer', function () {
    $this->company->update(['payroll_federally_regulated' => true]);

    $html = psrRender('BC'); // even a BC employee follows the CLC when federally regulated

    expect($html)->toContain('Canada Labour Code');
});

it('shows YTD columns by default and hides them when the employer opts out', function () {
    expect(psrRender('AB'))->toContain('YTD');

    $this->company->update(['settings' => ['pay_statement' => ['ytd' => false]]]);

    expect(psrRender('AB'))->not->toContain('>YTD<');
});

it('keeps a legislatively required item even when the employer toggles it off', function () {
    // Quebec requires the employee's occupation — toggling it off must not hide it.
    $this->company->update(['settings' => ['pay_statement' => ['occupation' => false]]]);

    $html = psrRender('QC', ['job_title' => 'Machiniste']);

    expect($html)->toContain('Machiniste');
});

it('omits an optional item that is off and not required in the province', function () {
    // Alberta does not require occupation; with the toggle off it should not appear.
    $this->company->update(['settings' => ['pay_statement' => ['occupation' => false]]]);

    $html = psrRender('AB', ['job_title' => 'Welder']);

    expect($html)->not->toContain('Welder');
});

it('reports a taxable benefit in the employer-paid section, not in cash earnings or net', function () {
    $html = psrRender('AB', ['benefit' => true, 'post' => true]);

    // The benefit shows under employer-paid benefits…
    expect($html)->toContain('Employer-paid benefits &amp; accruals')
        ->and($html)->toContain('Group life (taxable)')
        ->and($html)->toContain('Employer-paid amounts are not deducted from your pay.');
});
