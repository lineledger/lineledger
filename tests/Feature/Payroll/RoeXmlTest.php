<?php

use App\Actions\Payroll\SavePayRun;
use App\Enums\CompanyRole;
use App\Enums\PayBasis;
use App\Enums\RoeReason;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollSchedule;
use App\Models\User;
use App\Services\Payroll\CalculatePayRun;
use App\Services\Posting\PayRunPoster;
use App\Services\Reporting\RoeCalculator;
use App\Services\Reporting\RoeXmlGenerator;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create([
        'address_country' => 'CA', 'features_payroll' => true,
        'tax_number' => '123456789RP0001', 'address_region' => 'AB',
    ]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->schedule = PayrollSchedule::factory()->create();
    $this->bank = Account::query()->where('code', '1000')->firstOrFail();
    $this->employee = Contact::create(['display_name' => 'Wanda Leaver', 'first_name' => 'Wanda', 'last_name' => 'Leaver', 'is_employee' => true]);
    $this->profile = EmployeePayrollProfile::factory()->create([
        'contact_id' => $this->employee->id,
        'province_of_employment' => 'AB',
        'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 6000000,
        'default_hours_per_period' => 80,
        'payroll_schedule_id' => $this->schedule->id,
        'td1_federal_claim_cents' => 1612900,
        'td1_provincial_claim_cents' => 2232300,
        'hire_date' => '2023-01-09',
    ]);
    $this->profile->setSin('123456789');
    $this->profile->save();

    $run = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => $this->schedule->id,
        'period_start_date' => '2025-03-01',
        'period_end_date' => '2025-03-14',
        'pay_date' => '2025-03-20',
        'bank_account_id' => $this->bank->id,
        'lines' => [['contact_id' => $this->employee->id]],
    ]);
    app(CalculatePayRun::class)->calculate($run);
    app(PayRunPoster::class)->post($run->fresh());
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('generates well-formed ROE Web XML with the blocks and per-period earnings', function () {
    $roe = app(RoeCalculator::class)->build($this->company, $this->employee, RoeReason::ShortageOfWork, '2025-03-20');
    $xml = app(RoeXmlGenerator::class)->generate($this->company, $this->employee, $roe);

    $doc = new DOMDocument;
    expect($doc->loadXML($xml))->toBeTrue();

    expect($xml)->toContain('<ROEWEB_BULK>')
        ->and($xml)->toContain('<ROE>')
        ->and($xml)->toContain('<BLK14_SIN>123456789</BLK14_SIN>')
        ->and($xml)->toContain('<BLK16_REASON_CD>A</BLK16_REASON_CD>')   // shortage of work
        ->and($xml)->toContain('<snm>Leaver</snm>')
        ->and($xml)->toContain('<gvn_nm>Wanda</gvn_nm>')
        ->and($xml)->toContain('<BLK10_FIRST_DAY>2023-01-09</BLK10_FIRST_DAY>')
        ->and($xml)->toContain('<BLK11_LAST_DAY_PAID>2025-03-20</BLK11_LAST_DAY_PAID>')
        ->and($xml)->toContain('<BLK15A_TOTAL_INS_HOURS>80.00</BLK15A_TOTAL_INS_HOURS>')
        ->and($xml)->toContain('<BLK15C_PAY_PERIODS>')
        ->and($xml)->toContain('<period_end>2025-03-14</period_end>')
        ->and($xml)->toContain('<ins_earnings>'.number_format($roe['total_insurable_earnings_cents'] / 100, 2, '.', '').'</ins_earnings>');
});

it('downloads the ROE Web XML from the report page', function () {
    Livewire\Livewire::test('pages::payroll.reports.roe', ['company' => $this->company])
        ->set('contactId', $this->employee->id)
        ->set('reason', 'A')
        ->set('lastDay', '2025-03-20')
        ->call('downloadXml')
        ->assertFileDownloaded('roe-'.$this->employee->id.'.xml');
});
