<?php

use App\Actions\Payroll\SavePayRun;
use App\Enums\CompanyRole;
use App\Enums\PayBasis;
use App\Enums\RemittanceFrequency;
use App\Enums\RemittanceStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollRemittance;
use App\Models\PayrollSchedule;
use App\Models\User;
use App\Services\Payroll\CalculatePayRun;
use App\Services\Posting\PayRunPoster;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->schedule = PayrollSchedule::factory()->create();
    $this->bank = Account::query()->where('code', '1000')->firstOrFail();
    $this->employee = Contact::create(['display_name' => 'Remmy Tance', 'is_employee' => true]);
    EmployeePayrollProfile::factory()->create([
        'contact_id' => $this->employee->id,
        'province_of_employment' => 'AB',
        'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 6000000,
        'payroll_schedule_id' => $this->schedule->id,
        'td1_federal_claim_cents' => 1612900,
        'td1_provincial_claim_cents' => 2232300,
    ]);

    $run = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => $this->schedule->id,
        'period_start_date' => '2025-06-01',
        'period_end_date' => '2025-06-14',
        'pay_date' => '2025-06-20',
        'bank_account_id' => $this->bank->id,
        'lines' => [['contact_id' => $this->employee->id]],
    ]);
    app(CalculatePayRun::class)->calculate($run);
    app(PayRunPoster::class)->post($run->fresh());
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('renders the remittance history page', function () {
    $this->get(route('payroll.reports.remittances', $this->company))->assertOk();
});

it('records a CRA remittance from the PD7A page and shows it as remitted', function () {
    $this->travelTo('2025-07-10');

    Livewire::test('pages::payroll.reports.pd7a', ['company' => $this->company])
        ->assertSet('periodKey', '2025-06-01') // most recent closed monthly period
        ->call('openRecord')
        ->set('f_bank_account_id', $this->bank->id)
        ->set('f_payment_date', '2025-07-10')
        ->set('f_reference', 'WEB-001')
        ->call('record')
        ->assertHasNoErrors();

    $remittance = PayrollRemittance::query()->where('agency', 'cra')->firstOrFail();
    expect($remittance->status)->toBe(RemittanceStatus::Paid)
        ->and($remittance->period_start->toDateString())->toBe('2025-06-01')
        ->and($remittance->due_date->toDateString())->toBe('2025-07-15')
        ->and($remittance->journal_entry_id)->not->toBeNull();

    $this->travelBack();
});

it('saves the remittance frequency in payroll settings', function () {
    Livewire::test('pages::settings.payroll', ['company' => $this->company])
        ->set('f_standard_annual_hours', 2080)
        ->set('f_remittance_frequency', 'accelerated_1')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->company->fresh()->payroll_remittance_frequency)->toBe(RemittanceFrequency::Accelerated1);
});

it('voids a remittance from the history page', function () {
    $this->travelTo('2025-07-10');

    Livewire::test('pages::payroll.reports.pd7a', ['company' => $this->company])
        ->call('openRecord')
        ->set('f_bank_account_id', $this->bank->id)
        ->set('f_payment_date', '2025-07-10')
        ->call('record');

    $remittance = PayrollRemittance::query()->where('agency', 'cra')->firstOrFail();

    Livewire::test('pages::payroll.reports.remittances', ['company' => $this->company])
        ->call('voidRemittance', $remittance->id)
        ->assertHasNoErrors();

    expect($remittance->fresh()->status)->toBe(RemittanceStatus::Void);

    $this->travelBack();
});
