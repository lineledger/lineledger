<?php

use App\Enums\CompanyRole;
use App\Enums\PayBasis;
use App\Enums\PayFrequency;
use App\Enums\Section;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollSchedule;

beforeEach(function () {
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('seeds the system payroll accounts for a new Canadian company', function () {
    foreach (['2400', '2410', '2420', '2430', '2440', '6200', '6210', '6220', '6230'] as $code) {
        $account = Account::query()->where('code', $code)->first();

        expect($account)->not->toBeNull("account {$code} should be seeded")
            ->and($account->is_system)->toBeTrue("account {$code} should be a system account");
    }
});

it('exposes payroll to Owner and Accountant roles but not custom by default', function () {
    expect(in_array(Section::Payroll, CompanyRole::Owner->sections(), true))->toBeTrue();
    expect(in_array(Section::Payroll, CompanyRole::Accountant->sections(), true))->toBeTrue();
    expect(CompanyRole::Custom->sections())->toBe([]);

    expect(Section::forRouteName('pay-runs.index'))->toBe([Section::Payroll]);
});

it('gates payroll on the flag and Canadian jurisdiction', function () {
    expect($this->company->usesPayroll())->toBeTrue();

    $us = Company::factory()->create(['address_country' => 'US', 'features_payroll' => true]);
    expect($us->usesPayroll())->toBeFalse();

    $this->company->features_payroll = false;
    expect($this->company->usesPayroll())->toBeFalse();
});

it('backfills payroll accounts onto a company missing them', function () {
    // Simulate a pre-payroll company by removing the seeded payroll accounts.
    Account::query()->whereIn('code', ['2400', '2410', '2420', '2430', '2440', '6200', '6210', '6220', '6230'])->forceDelete();
    expect(Account::query()->where('code', '2400')->exists())->toBeFalse();

    $this->artisan('payroll:backfill-accounts', ['company' => $this->company->id])->assertSuccessful();

    foreach (['2400', '2440', '6200', '6230'] as $code) {
        $account = Account::query()->where('code', $code)->first();
        expect($account)->not->toBeNull()->and($account->is_system)->toBeTrue();
    }
});

it('creates an employee payroll profile with an encrypted SIN', function () {
    $schedule = PayrollSchedule::factory()->frequency(PayFrequency::Biweekly)->create();
    $employee = Contact::create(['display_name' => 'Dana Worker', 'is_employee' => true]);

    $profile = new EmployeePayrollProfile([
        'contact_id' => $employee->id,
        'province_of_employment' => 'ON',
        'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 6500000,
        'payroll_schedule_id' => $schedule->id,
        'td1_federal_claim_cents' => 1659900,
        'td1_provincial_claim_cents' => 1240000,
    ]);
    $profile->setSin('123-456-789');
    $profile->save();

    expect($profile->sin_last4)->toBe('6789');

    // Stored value is ciphertext, not the raw SIN.
    $raw = DB::table('employee_payroll_profiles')->where('id', $profile->id)->value('sin_encrypted');
    expect($raw)->not->toBe('123456789');

    // The cast decrypts transparently on reload.
    expect($employee->fresh()->payrollProfile->sin_encrypted)->toBe('123456789');
});
