<?php

use App\Actions\Payroll\SavePayRun;
use App\Enums\CompanyRole;
use App\Enums\PayBasis;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollSchedule;
use App\Models\User;
use App\Services\Payroll\CalculatePayRun;
use App\Services\Posting\PayRunPoster;
use App\Services\Reporting\Rl1SlipCalculator;
use App\Services\Reporting\Rl1XmlGenerator;
use App\Services\Reporting\T4SlipCalculator;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create([
        'address_country' => 'CA',
        'features_payroll' => true,
        'tax_number' => '1234567890',
        'qhsf_rate_bp' => 192,
        'cnesst_rate_bp' => 200,
        'wsdrf_applicable' => true,
    ]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);
    $this->bank = Account::query()->where('code', '1000')->firstOrFail();

    $this->schedule = PayrollSchedule::factory()->create();
    $this->quebecer = Contact::create(['display_name' => 'Quincy Québec', 'first_name' => 'Quincy', 'last_name' => 'Québec', 'is_employee' => true]);
    $profile = EmployeePayrollProfile::factory()->create([
        'contact_id' => $this->quebecer->id,
        'province_of_employment' => 'QC',
        'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 6000000,
        'payroll_schedule_id' => $this->schedule->id,
        'td1_federal_claim_cents' => 1612900,
        'td1_provincial_claim_cents' => 1857100,
    ]);
    $profile->setSin('123456789');
    $profile->save();

    // An Albertan to prove the RL-1 excludes the rest of Canada.
    $this->albertan = Contact::create(['display_name' => 'Al Berta', 'is_employee' => true]);
    EmployeePayrollProfile::factory()->create([
        'contact_id' => $this->albertan->id,
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

function postQuebecPayroll(): void
{
    $run = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => test()->schedule->id,
        'period_start_date' => '2025-03-01',
        'period_end_date' => '2025-03-14',
        'pay_date' => '2025-03-20',
        'bank_account_id' => test()->bank->id,
        'lines' => [
            ['contact_id' => test()->quebecer->id],
            ['contact_id' => test()->albertan->id],
        ],
    ]);
    app(CalculatePayRun::class)->calculate($run);
    app(PayRunPoster::class)->post($run->fresh());
}

it('populates T4 boxes 17/55/56 for a Quebec employee and leaves box 16 at 0', function () {
    postQuebecPayroll();

    $slips = app(T4SlipCalculator::class)->slipsForYear($this->company, 2025);
    $qcSlip = collect($slips)->firstWhere('contact_id', $this->quebecer->id);

    expect($qcSlip['box16'])->toBe(0)       // no CPP for Quebec
        ->and($qcSlip['box17'])->toBeGreaterThan(0)  // QPP
        ->and($qcSlip['box55'])->toBeGreaterThan(0)  // QPIP premium
        ->and($qcSlip['box56'])->toBeGreaterThan(0)  // QPIP insurable
        // Box 22 collapses to abated federal only (provincial is 0; Quebec tax is on the RL-1).
        ->and($qcSlip['box22'])->toBeGreaterThan(0);
});

it('aggregates RL-1 slips for Quebec employees only and reconciles the boxes', function () {
    postQuebecPayroll();

    $slips = app(Rl1SlipCalculator::class)->slipsForYear($this->company, 2025);

    // Only the Quebec employee gets an RL-1.
    expect($slips)->toHaveCount(1);

    $slip = $slips[0];
    expect($slip['contact_id'])->toBe($this->quebecer->id)
        ->and($slip['boxA'])->toBeGreaterThan(0) // employment income
        ->and($slip['boxB'])->toBeGreaterThan(0) // QPP
        ->and($slip['boxE'])->toBeGreaterThan(0) // Quebec tax
        ->and($slip['boxG'])->toBeGreaterThan(0) // QPP pensionable
        ->and($slip['boxH'])->toBeGreaterThan(0) // QPIP premium
        ->and($slip['boxI'])->toBeGreaterThan(0); // QPIP insurable
});

it('reports the WSDRF 1% reconciliation on the RL-1 Summary when applicable', function () {
    postQuebecPayroll();

    $summary = app(Rl1SlipCalculator::class)->summary($this->company, 2025);

    expect($summary['wsdrf_applicable'])->toBeTrue()
        ->and($summary['wsdrf_payroll_cents'])->toBe($summary['boxA'])
        // 1% of Quebec payroll (no recorded training yet).
        ->and($summary['wsdrf_levy_cents'])->toBe((int) round($summary['boxA'] * 100 / 10000))
        ->and($summary['qhsf'])->toBeGreaterThan(0);
});

it('generates well-formed RL-1 XML with slips, a summary and the WSDRF block', function () {
    postQuebecPayroll();

    $slips = app(Rl1SlipCalculator::class)->slipsForYear($this->company, 2025);
    $summary = app(Rl1SlipCalculator::class)->summary($this->company, 2025);
    $xml = app(Rl1XmlGenerator::class)->generate($this->company, 2025, $slips, $summary);

    $doc = new DOMDocument;
    expect($doc->loadXML($xml))->toBeTrue();

    expect($xml)->toContain('<Releve1>')
        ->and($xml)->toContain('<Sommaire1>')
        ->and($xml)->toContain('<NomFamille>Québec</NomFamille>')
        ->and($xml)->toContain('<NoAssuranceSociale>123456789</NoAssuranceSociale>')
        ->and($xml)->toContain('<CaseA>'.number_format($slips[0]['boxA'] / 100, 2, '.', '').'</CaseA>')
        ->and($xml)->toContain('<FormationMainDoeuvre>')
        ->and($xml)->toContain('<Assujetti>O</Assujetti>');
});

it('renders the RL-1 report page gated to payroll companies and downloads XML', function () {
    postQuebecPayroll();

    $this->get(route('payroll.reports.rl1', ['company' => $this->company, 'year' => 2025]))
        ->assertOk()
        ->assertSee('RL-1');

    Livewire\Livewire::test('pages::payroll.reports.rl1', ['company' => $this->company, 'year' => 2025])
        ->call('exportXml')
        ->assertFileDownloaded('rl1-2025.xml');

    $this->company->update(['features_payroll' => false]);
    $this->get(route('payroll.reports.rl1', ['company' => $this->company]))->assertNotFound();
});
