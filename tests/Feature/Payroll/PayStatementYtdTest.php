<?php

use App\Actions\Payroll\SaveEmployeePayrollProfile;
use App\Actions\Payroll\SavePayRun;
use App\Enums\CompanyRole;
use App\Enums\PayBasis;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollSchedule;
use App\Models\PayRun;
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
    $this->employee = Contact::create(['display_name' => 'Yvette Tudor', 'is_employee' => true]);
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

    // A recurring voluntary deduction and an hours accrual to exercise per-code YTD.
    app(SaveEmployeePayrollProfile::class)->handle([
        'contact_id' => $this->employee->id,
        'province_of_employment' => 'AB',
        'pay_basis' => 'salary',
        'annual_salary_cents' => 6000000,
        'payroll_schedule_id' => $this->schedule->id,
        'td1_federal_claim_cents' => 1612900,
        'td1_provincial_claim_cents' => 2232300,
        'vacation_policy' => 'accrue',
        'vacation_rate_bp' => 400,
        'recurring_items' => [
            ['kind' => 'deduction', 'code' => 'union', 'name' => 'Union dues', 'calc_type' => 'fixed', 'amount_cents' => 10000],
            ['kind' => 'accrual', 'code' => 'sick', 'name' => 'Sick time', 'calc_type' => 'fixed', 'calc_basis' => 'hours', 'amount_cents' => 400],
        ],
    ], $this->employee->fresh()->payrollProfile);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function ytdRun(string $payDate): PayRun
{
    return app(SavePayRun::class)->handle([
        'payroll_schedule_id' => test()->schedule->id,
        'period_start_date' => $payDate,
        'period_end_date' => $payDate,
        'pay_date' => $payDate,
        'bank_account_id' => test()->bank->id,
        'lines' => [['contact_id' => test()->employee->id]],
    ]);
}

it('accumulates per-code and total YTD across posted runs, with current for this run only', function () {
    // Run 1: posted earlier in the year.
    $run1 = ytdRun('2025-06-20');
    app(CalculatePayRun::class)->calculate($run1);
    app(PayRunPoster::class)->post($run1->fresh());

    // Run 2: the statement we're printing (left Calculated — YTD must still include it once).
    $run2 = ytdRun('2025-07-04');
    app(CalculatePayRun::class)->calculate($run2);
    $line = $run2->fresh()->lines->first();

    $ytd = app(PayStatementYtdCalculator::class)->forLine($line);

    // YTD is the true sum of the prior posted run plus this one (per line, robustly
    // — the two pay dates straddle Alberta's mid-year rate change, so the periods
    // are NOT identical, which is exactly why we sum actuals rather than ×2).
    $l1 = $run1->fresh()->lines->first();

    expect($ytd['gross_current'])->toBe((int) $line->gross_cents)
        ->and($ytd['gross_ytd'])->toBe((int) $l1->gross_cents + (int) $line->gross_cents)
        ->and($ytd['net_ytd'])->toBe((int) $l1->net_cents + (int) $line->net_cents)
        // Voluntary deduction by code: $100 this run, $200 YTD.
        ->and($ytd['deductions']['union']['current_cents'])->toBe(10000)
        ->and($ytd['deductions']['union']['ytd_cents'])->toBe(20000)
        // Statutory income tax: current is this line; YTD is the real two-run sum.
        ->and($ytd['statutory']['income_tax']['current'])->toBe($line->incomeTaxCents())
        ->and($ytd['statutory']['income_tax']['ytd'])->toBe($l1->incomeTaxCents() + $line->incomeTaxCents())
        // Accrual hours by code: 4h this run, 8h YTD.
        ->and($ytd['accruals']['sick']['current_hours'])->toBe(4.0)
        ->and($ytd['accruals']['sick']['ytd_hours'])->toBe(8.0);
});

it('shows only the current run when nothing else is posted in the year', function () {
    $run = ytdRun('2025-06-20');
    app(CalculatePayRun::class)->calculate($run);
    $line = $run->fresh()->lines->first();

    $ytd = app(PayStatementYtdCalculator::class)->forLine($line);

    expect($ytd['gross_ytd'])->toBe($ytd['gross_current'])
        ->and($ytd['deductions']['union']['ytd_cents'])->toBe($ytd['deductions']['union']['current_cents']);
});
