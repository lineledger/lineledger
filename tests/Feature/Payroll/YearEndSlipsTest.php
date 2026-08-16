<?php

use App\Actions\Payroll\SavePayRun;
use App\Enums\CompanyRole;
use App\Enums\PayBasis;
use App\Enums\RoeReason;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollSchedule;
use App\Models\PayRun;
use App\Models\User;
use App\Services\Payroll\CalculatePayRun;
use App\Services\Posting\PayRunPoster;
use App\Services\Reporting\PayrollRemittanceCalculator;
use App\Services\Reporting\RoeCalculator;
use App\Services\Reporting\T4SlipCalculator;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->schedule = PayrollSchedule::factory()->create();
    $this->employee = Contact::create(['display_name' => 'Yana Yearend', 'is_employee' => true]);
    EmployeePayrollProfile::factory()->create([
        'contact_id' => $this->employee->id,
        'province_of_employment' => 'AB',
        'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 6000000,
        'default_hours_per_period' => 80,
        'hire_date' => '2024-01-08',
        'payroll_schedule_id' => $this->schedule->id,
        'td1_federal_claim_cents' => 1612900,
        'td1_provincial_claim_cents' => 2232300,
    ]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function postYearEndRun(string $payDate): PayRun
{
    $run = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => test()->schedule->id,
        'period_start_date' => $payDate,
        'period_end_date' => $payDate,
        'pay_date' => $payDate,
        'lines' => [['contact_id' => test()->employee->id]],
    ]);
    app(CalculatePayRun::class)->calculate($run);
    app(PayRunPoster::class)->post($run->fresh());

    return $run->fresh();
}

it('aggregates a calendar year into a T4 slip with the right boxes', function () {
    $r1 = postYearEndRun('2025-01-10');
    $r2 = postYearEndRun('2025-01-24');

    $slips = app(T4SlipCalculator::class)->slipsForYear($this->company, 2025);
    expect($slips)->toHaveCount(1);

    $slip = $slips[0];
    $l1 = $r1->lines->first();
    $l2 = $r2->lines->first();

    expect($slip['box14'])->toBe($l1->gross_cents + $l2->gross_cents)
        ->and($slip['box16'])->toBe($l1->cppEmployeeCents() + $l2->cppEmployeeCents())
        ->and($slip['box18'])->toBe($l1->eiEmployeeCents() + $l2->eiEmployeeCents())
        ->and($slip['box22'])->toBe($l1->incomeTaxCents() + $l2->incomeTaxCents())
        ->and($slip['province'])->toBe('AB');
});

it('reconciles T4 box 22 (income tax) to the PD7A income-tax remittances for the year', function () {
    postYearEndRun('2025-03-14');
    postYearEndRun('2025-09-12');

    $summary = app(T4SlipCalculator::class)->summary($this->company, 2025);

    // Sum the PD7A income tax across every month of the year.
    $pd7aTax = 0;
    foreach (range(1, 12) as $month) {
        $start = CarbonImmutable::create(2025, $month, 1);
        $pd7aTax += app(PayrollRemittanceCalculator::class)->summary($this->company, $start, $start->endOfMonth())['tax_cents'];
    }

    expect($summary['box22'])->toBe($pd7aTax)->and($summary['box22'])->toBeGreaterThan(0);
});

it('builds an ROE worksheet from posted insurable hours and earnings', function () {
    postYearEndRun('2025-05-09');
    $last = postYearEndRun('2025-05-23');

    $roe = app(RoeCalculator::class)->build($this->company, $this->employee, RoeReason::ShortageOfWork, '2025-05-23');

    expect($roe['employee'])->toBe('Yana Yearend')
        ->and($roe['first_day'])->toBe('2024-01-08')
        ->and($roe['periods'])->toHaveCount(2)
        ->and($roe['periods'][0]['period_end'])->toBe('2025-05-23') // most recent first
        ->and((float) $roe['total_insurable_hours'])->toBe(160.0) // 80 + 80
        ->and($roe['total_insurable_earnings_cents'])->toBe($last->lines->first()->ei_insurable_cents * 2);
});

it('renders the T4 and ROE report pages, gated to payroll companies', function () {
    postYearEndRun('2025-06-13');

    $this->get(route('payroll.reports.t4', ['company' => $this->company, 'year' => 2025]))->assertOk()->assertSee('T4');
    $this->get(route('payroll.reports.roe', ['company' => $this->company]))->assertOk()->assertSee('Record of Employment');

    $this->company->update(['features_payroll' => false]);
    $this->get(route('payroll.reports.t4', ['company' => $this->company]))->assertNotFound();
});
