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
use App\Services\Reporting\QuebecRemittanceCalculator;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create([
        'address_country' => 'CA',
        'features_payroll' => true,
        'qhsf_rate_bp' => 192,
        'cnesst_rate_bp' => 200,
    ]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->schedule = PayrollSchedule::factory()->create();

    $this->quebecer = Contact::create(['display_name' => 'Quincy Québec', 'is_employee' => true]);
    EmployeePayrollProfile::factory()->create([
        'contact_id' => $this->quebecer->id,
        'province_of_employment' => 'QC',
        'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 6000000,
        'payroll_schedule_id' => $this->schedule->id,
        'td1_federal_claim_cents' => 1612900,
        'td1_provincial_claim_cents' => 1857100,
    ]);

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

function postQuebecRun(string $payDate): PayRun
{
    $run = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => test()->schedule->id,
        'period_start_date' => $payDate,
        'period_end_date' => $payDate,
        'pay_date' => $payDate,
        'lines' => [
            ['contact_id' => test()->quebecer->id],
            ['contact_id' => test()->albertan->id],
        ],
    ]);
    app(CalculatePayRun::class)->calculate($run);
    app(PayRunPoster::class)->post($run->fresh());

    return $run->fresh()->load('lines');
}

it('sums only Quebec lines and equals Quebec tax + QPP + QPIP + QHSF + CNESST', function () {
    $run = postQuebecRun('2025-06-12');
    $qc = $run->lines->firstWhere('province_of_employment', 'QC');

    $summary = app(QuebecRemittanceCalculator::class)->summary(
        $this->company,
        CarbonImmutable::create(2025, 6, 1),
        CarbonImmutable::create(2025, 6, 30)->endOfDay(),
    );

    $expectedQpp = $qc->qppEmployeeCents() + $qc->qppEmployerCents() + $qc->qpp2EmployeeCents() + $qc->qpp2EmployerCents();
    $expectedQpip = $qc->qpipEmployeeCents() + $qc->qpipEmployerCents();

    expect($summary['quebec_tax_cents'])->toBe($qc->quebecTaxCents())
        ->and($summary['total_qpp_cents'])->toBe($expectedQpp)
        ->and($summary['total_qpip_cents'])->toBe($expectedQpip)
        ->and($summary['qhsf_cents'])->toBe($qc->qhsfEmployerCents())
        ->and($summary['cnesst_cents'])->toBe($qc->cnesstEmployerCents())
        // Only the Quebec employee is counted, never the Albertan.
        ->and($summary['employee_count'])->toBe(1)
        ->and($summary['quebec_gross_cents'])->toBe($qc->gross_cents)
        ->and($summary['remittance_due_cents'])->toBe(
            $qc->quebecTaxCents() + $expectedQpp + $expectedQpip + $qc->qhsfEmployerCents() + $qc->cnesstEmployerCents()
        );
});

it('reflects a manual Quebec-tax override in the remittance total', function () {
    $run = postQuebecRun('2025-06-12');
    $qc = $run->lines->firstWhere('province_of_employment', 'QC');

    $base = app(QuebecRemittanceCalculator::class)->summary($this->company, CarbonImmutable::create(2025, 6, 1), CarbonImmutable::create(2025, 6, 30)->endOfDay())['quebec_tax_cents'];

    $qc->update(['quebec_tax_override_cents' => $qc->quebecTaxCents() + 5000]);

    $after = app(QuebecRemittanceCalculator::class)->summary($this->company, CarbonImmutable::create(2025, 6, 1), CarbonImmutable::create(2025, 6, 30)->endOfDay())['quebec_tax_cents'];

    expect($after)->toBe($base + 5000);
});

it('renders the Revenu Québec report page, gated to payroll companies', function () {
    postQuebecRun('2025-06-12');

    $this->get(route('payroll.reports.revenu-quebec', ['company' => $this->company, 'year' => 2025, 'month' => 6]))
        ->assertOk()
        ->assertSee('Revenu Québec');

    $this->company->update(['features_payroll' => false]);
    $this->get(route('payroll.reports.revenu-quebec', ['company' => $this->company]))->assertNotFound();
});
