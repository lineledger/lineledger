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
use App\Services\Posting\PayRunPoster;
use App\Services\Reporting\PayrollRemittanceCalculator;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->schedule = PayrollSchedule::factory()->create();
    $this->employee = Contact::create(['display_name' => 'Remi Remit', 'is_employee' => true]);
    EmployeePayrollProfile::factory()->create([
        'contact_id' => $this->employee->id,
        'province_of_employment' => 'AB',
        'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 6000000,
        'payroll_schedule_id' => $this->schedule->id,
        'td1_federal_claim_cents' => 1612900,
        'td1_provincial_claim_cents' => 2232300,
    ]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function postRunOn(string $payDate): PayRun
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

it('sums a remitting period from posted pay runs and equals CPP + EI + tax', function () {
    $run = postRunOn('2025-06-12');
    $line = $run->lines->first();

    $summary = app(PayrollRemittanceCalculator::class)->summary(
        $this->company,
        CarbonImmutable::create(2025, 6, 1),
        CarbonImmutable::create(2025, 6, 30)->endOfDay(),
    );

    $expectedCpp = ($line->cppEmployeeCents() + $line->cppEmployerCents() + $line->cpp2EmployeeCents() + $line->cpp2EmployerCents());
    $expectedEi = $line->eiEmployeeCents() + $line->eiEmployerCents();
    $expectedTax = $line->incomeTaxCents();

    expect($summary['total_cpp_cents'])->toBe($expectedCpp)
        ->and($summary['total_ei_cents'])->toBe($expectedEi)
        ->and($summary['tax_cents'])->toBe($expectedTax)
        ->and($summary['remittance_due_cents'])->toBe($expectedCpp + $expectedEi + $expectedTax)
        ->and($summary['employee_count'])->toBe(1)
        ->and($summary['last_period_employee_count'])->toBe(1);
});

it('excludes pay runs outside the remitting period', function () {
    postRunOn('2025-05-15');
    postRunOn('2025-06-12');

    $may = app(PayrollRemittanceCalculator::class)->summary($this->company, CarbonImmutable::create(2025, 5, 1), CarbonImmutable::create(2025, 5, 31)->endOfDay());
    $rows = app(PayrollRemittanceCalculator::class)->rows($this->company, CarbonImmutable::create(2025, 6, 1), CarbonImmutable::create(2025, 6, 30)->endOfDay());

    expect($may['employee_count'])->toBe(1)
        ->and($rows)->toHaveCount(1)
        ->and($rows[0]['pay_date'])->toBe('2025-06-12');
});

it('reflects a manual override in the remittance total', function () {
    $run = postRunOn('2025-06-12');
    // Override federal tax up by editing the line directly (run already posted, but the
    // report reads the persisted effective columns).
    $line = $run->lines->first();
    $baseTax = app(PayrollRemittanceCalculator::class)->summary($this->company, CarbonImmutable::create(2025, 6, 1), CarbonImmutable::create(2025, 6, 30)->endOfDay())['tax_cents'];

    $line->update(['federal_tax_override_cents' => $line->federalTaxCents() + 5000]);

    $afterTax = app(PayrollRemittanceCalculator::class)->summary($this->company, CarbonImmutable::create(2025, 6, 1), CarbonImmutable::create(2025, 6, 30)->endOfDay())['tax_cents'];

    expect($afterTax)->toBe($baseTax + 5000);
});

it('renders the PD7A report page, gated to payroll companies', function () {
    postRunOn('2025-06-12');

    $this->get(route('payroll.reports.pd7a', ['company' => $this->company, 'year' => 2025, 'month' => 6]))
        ->assertOk()
        ->assertSee('PD7A');

    $this->company->update(['features_payroll' => false]);
    $this->get(route('payroll.reports.pd7a', ['company' => $this->company]))->assertNotFound();
});
