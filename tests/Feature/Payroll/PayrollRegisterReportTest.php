<?php

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
use App\Services\Reporting\PayrollRegisterCalculator;
use App\Services\Reporting\XlsxExporter;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->schedule = PayrollSchedule::factory()->create();
    $this->bank = Account::query()->where('code', '1000')->firstOrFail();
    $this->employee = Contact::create(['display_name' => 'Reggie Register', 'is_employee' => true]);
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

function postedRegisterRun(): PayRun
{
    $run = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => test()->schedule->id,
        'period_start_date' => '2025-06-01',
        'period_end_date' => '2025-06-14',
        'pay_date' => '2025-06-20',
        'bank_account_id' => test()->bank->id,
        'lines' => [['contact_id' => test()->employee->id]],
    ]);
    app(CalculatePayRun::class)->calculate($run);
    app(PayRunPoster::class)->post($run->fresh());

    return $run->fresh();
}

it('renders the payroll register page', function () {
    $this->get(route('payroll.reports.register', $this->company))->assertOk();
});

it('is 404 for a non-payroll company', function () {
    $other = Company::factory()->create(['features_payroll' => false]);
    $other->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    $this->get(route('payroll.reports.register', $other))->assertNotFound();
});

it('ties the register totals to the pay run header', function () {
    $run = postedRegisterRun();

    $summary = app(PayrollRegisterCalculator::class)->summary(
        $this->company,
        CarbonImmutable::parse('2025-06-01'),
        CarbonImmutable::parse('2025-06-30'),
    );

    expect($summary['line_count'])->toBe(1)
        ->and($summary['gross_cents'])->toBe((int) $run->gross_cents)
        ->and($summary['net_cents'])->toBe((int) $run->net_cents);
});

it('builds an XLSX payroll register', function () {
    postedRegisterRun();

    $rows = app(PayrollRegisterCalculator::class)->rows(
        $this->company,
        CarbonImmutable::parse('2025-06-01'),
        CarbonImmutable::parse('2025-06-30'),
    );

    $response = app(XlsxExporter::class)->payrollRegister('register.xlsx', $this->company, $rows, '2025-06-01', '2025-06-30');

    expect($response)->toBeInstanceOf(BinaryFileResponse::class);
});

it('exports the register via the page actions without error', function () {
    postedRegisterRun();

    $page = Livewire::test('pages::payroll.reports.register', ['company' => $this->company])
        ->set('startDate', '2025-06-01')
        ->set('endDate', '2025-06-30');

    $page->call('exportCsv')->assertHasNoErrors();
    $page->call('exportXlsx')->assertHasNoErrors();
    $page->call('exportPdf')->assertHasNoErrors();
});
