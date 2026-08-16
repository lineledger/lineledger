<?php

use App\Actions\Payroll\SavePayRun;
use App\Enums\CompanyRole;
use App\Enums\PayBasis;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollSchedule;
use App\Models\User;
use App\Services\Payroll\CalculatePayRun;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);
    $this->schedule = PayrollSchedule::factory()->create();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('renders the payroll settings page', function () {
    $this->get(route('settings.payroll', $this->company))->assertOk();
});

it('is 404 for a non-payroll company', function () {
    $other = Company::factory()->create(['features_payroll' => false]);
    $other->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    $this->get(route('settings.payroll', $other))->assertNotFound();
});

it('saves the payroll program identity, contact and standard hours', function () {
    Livewire::test('pages::settings.payroll', ['company' => $this->company])
        ->set('f_standard_annual_hours', 1950)
        ->set('f_business_number', '123456789')
        ->set('f_rp_account', 'RP0001')
        ->set('f_contact_name', 'Avery Admin')
        ->set('f_contact_email', 'avery@example.com')
        ->set('f_contact_phone', '604-555-0100')
        ->set('f_work_location', '123 Main St, Vancouver BC')
        ->call('save')
        ->assertHasNoErrors();

    $this->company->refresh();
    expect($this->company->payroll_standard_annual_hours)->toBe(1950)
        ->and($this->company->payroll_business_number)->toBe('123456789')
        ->and($this->company->payroll_rp_account)->toBe('RP0001')
        ->and($this->company->payroll_contact_name)->toBe('Avery Admin')
        ->and($this->company->payroll_work_location)->toBe('123 Main St, Vancouver BC');
});

it('saves the federally-regulated flag and pay-statement toggles', function () {
    Livewire::test('pages::settings.payroll', ['company' => $this->company])
        ->set('f_standard_annual_hours', 2080)
        ->set('f_federally_regulated', true)
        ->set('f_statement.ytd', false)
        ->set('f_statement.occupation', true)
        ->call('save')
        ->assertHasNoErrors();

    $this->company->refresh();

    expect($this->company->payroll_federally_regulated)->toBeTrue()
        ->and($this->company->payStatementSetting('ytd', true))->toBeFalse()    // toggled off
        ->and($this->company->payStatementSetting('occupation', false))->toBeTrue(); // toggled on
});

it('shows a federally-regulated company the Canada Labour Code statement on the settings page', function () {
    $this->company->update(['payroll_federally_regulated' => true]);

    Livewire::test('pages::settings.payroll', ['company' => $this->company])
        ->assertSee('Canada Labour Code');
});

it('uses the company standard-hours override when deriving salaried overtime', function () {
    $this->company->update(['payroll_standard_annual_hours' => 1040]); // half of 2080 → double the rate

    $contact = Contact::create(['display_name' => 'Sal Aried', 'is_employee' => true]);
    EmployeePayrollProfile::factory()->create([
        'contact_id' => $contact->id,
        'province_of_employment' => 'AB',
        'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 6000000,
        'payroll_schedule_id' => $this->schedule->id,
        'td1_federal_claim_cents' => 1612900,
        'td1_provincial_claim_cents' => 2232300,
        'vacation_policy' => 'accrue',
        'vacation_rate_bp' => 400,
    ]);

    $run = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => $this->schedule->id,
        'period_start_date' => '2025-06-01',
        'period_end_date' => '2025-06-14',
        'pay_date' => '2025-06-20',
        'lines' => [[
            'contact_id' => $contact->id,
            'manual_earnings' => [[
                'code' => 'overtime', 'name' => 'Overtime (1.5×)', 'calc_kind' => 'hours',
                'hours' => 1, 'multiplier_bp' => 15000,
            ]],
        ]],
    ]);
    app(CalculatePayRun::class)->calculate($run);
    $line = $run->fresh()->lines->first();

    // $60,000 / 1040 = $57.69/hr; 1.5× × 1h = $86.54 (vs ~$43 at the default 2080).
    expect($line->earnings->firstWhere('code', 'overtime')?->amount_cents)->toBe(8654);
});
