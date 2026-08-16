<?php

use App\Actions\Payroll\SavePayRun;
use App\Enums\PayBasis;
use App\Enums\PayRunStatus;
use App\Enums\VacationPolicy;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollSchedule;
use App\Models\PayRun;
use App\Services\Payroll\CalculatePayRun;

beforeEach(function () {
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    app()->instance('current_company', $this->company);

    $this->schedule = PayrollSchedule::factory()->create(); // biweekly, 26/yr

    $this->employee = Contact::create(['display_name' => 'Pat Payee', 'is_employee' => true]);
    $this->profile = EmployeePayrollProfile::factory()->create([
        'contact_id' => $this->employee->id,
        'province_of_employment' => 'AB',
        'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 6000000, // $60,000 → $2,307.69 biweekly
        'payroll_schedule_id' => $this->schedule->id,
        'td1_federal_claim_cents' => 1612900,
        'td1_provincial_claim_cents' => 2232300,
        'vacation_policy' => VacationPolicy::Accrue->value,
        'vacation_rate_bp' => 400,
    ]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function buildRun(?array $lines = null): PayRun
{
    return app(SavePayRun::class)->handle([
        'payroll_schedule_id' => test()->schedule->id,
        'period_start_date' => '2025-06-01',
        'period_end_date' => '2025-06-14',
        'pay_date' => '2025-06-20',
        'lines' => $lines ?? [['contact_id' => test()->employee->id]],
    ]);
}

it('calculates a salaried pay run: gross, statutory deductions, vacation accrual and net', function () {
    $run = buildRun();
    app(CalculatePayRun::class)->calculate($run);

    $line = $run->fresh()->lines->first();

    // $60,000 / 26 = $2,307.69 gross.
    expect($line->gross_cents)->toBe(230769)
        ->and($line->cpp_employee_computed_cents)->toBeGreaterThan(0)
        ->and($line->ei_employee_computed_cents)->toBe(3785) // 230769 × 1.64% = 3784.6 → 3785
        ->and($line->federal_tax_computed_cents)->toBeGreaterThan(0)
        ->and($line->provincial_tax_computed_cents)->toBeGreaterThan(0)
        // 4% vacation accrued on $2,307.69, not added to gross.
        ->and($line->vacation_accrued_cents)->toBe(9231)
        ->and($line->net_cents)->toBe($line->gross_cents - $line->totalEmployeeDeductionsCents());

    expect($run->fresh()->status)->toBe(PayRunStatus::Calculated);
    expect($run->fresh()->gross_cents)->toBe(230769);
});

it('pays vacation each cheque when the policy says so, increasing gross', function () {
    $this->profile->update(['vacation_policy' => VacationPolicy::PayEachCheque->value]);

    $run = buildRun();
    app(CalculatePayRun::class)->calculate($run);
    $line = $run->fresh()->lines->first();

    // Regular $2,307.69 + 4% vacation $92.31 = $2,400.00 gross.
    expect($line->vacation_paid_cents)->toBe(9231)
        ->and($line->vacation_accrued_cents)->toBe(0)
        ->and($line->gross_cents)->toBe(240000);
});

it('preserves a manual statutory override across recalculation', function () {
    $run = buildRun();
    app(CalculatePayRun::class)->calculate($run);
    $line = $run->fresh()->lines->first();

    // Operator overrides federal tax.
    $line->update(['federal_tax_override_cents' => 10000]);
    expect($line->fresh()->federalTaxCents())->toBe(10000);

    // Recalculate: computed is refreshed, but the override still wins.
    $run->fresh()->forceFill(['status' => PayRunStatus::Draft])->save();
    app(CalculatePayRun::class)->calculate($run->fresh());

    $line = $run->fresh()->lines->first();
    expect($line->federal_tax_override_cents)->toBe(10000)
        ->and($line->federalTaxCents())->toBe(10000)
        ->and($line->federal_tax_computed_cents)->toBeGreaterThan(0);
});

it('calculates a Quebec pay run into the QPP/QPIP/Quebec-tax columns with 0 CPP/provincial, plus employer levies', function () {
    $this->company->update(['qhsf_rate_bp' => 192, 'cnesst_rate_bp' => 200]); // 1.92% QHSF, 2.00% CNESST
    $this->profile->update([
        'province_of_employment' => 'QC',
        'td1_provincial_claim_cents' => 1857100, // Quebec TP-1015.3 basic amount
    ]);

    $run = buildRun();
    app(CalculatePayRun::class)->calculate($run);
    $line = $run->fresh()->lines->first();

    expect($line->gross_cents)->toBe(230769)
        // Quebec statutory populated…
        ->and($line->qpp_employee_computed_cents)->toBeGreaterThan(0)
        ->and($line->qpip_employee_computed_cents)->toBeGreaterThan(0)
        ->and($line->quebec_tax_computed_cents)->toBeGreaterThan(0)
        // …while the rest-of-Canada columns stay 0.
        ->and($line->cpp_employee_computed_cents)->toBe(0)
        ->and($line->provincial_tax_computed_cents)->toBe(0)
        // Federal tax still withheld (already abated) and EI at the Quebec reduced rate.
        ->and($line->federal_tax_computed_cents)->toBeGreaterThan(0)
        ->and($line->ei_employee_computed_cents)->toBe(3023) // 230769 × 1.31% = 3023.07 → 3023
        // Employer levies from company settings.
        ->and($line->qhsf_employer_computed_cents)->toBe(4431)   // round(230769 × 1.92%)
        ->and($line->cnesst_employer_computed_cents)->toBe(4615) // round(230769 × 2.00%)
        ->and($line->qpip_insurable_cents)->toBe(230769)
        ->and($line->net_cents)->toBe($line->gross_cents - $line->totalEmployeeDeductionsCents());
});

it('leaves Quebec columns and levies at 0 for a rest-of-Canada employee', function () {
    $this->company->update(['qhsf_rate_bp' => 192, 'cnesst_rate_bp' => 200]);

    $run = buildRun(); // AB employee from beforeEach
    app(CalculatePayRun::class)->calculate($run);
    $line = $run->fresh()->lines->first();

    expect($line->qpp_employee_computed_cents)->toBe(0)
        ->and($line->qpip_employee_computed_cents)->toBe(0)
        ->and($line->quebec_tax_computed_cents)->toBe(0)
        ->and($line->qhsf_employer_computed_cents)->toBe(0)
        ->and($line->cnesst_employer_computed_cents)->toBe(0);
});

it('lowers provincial withholding when the provincial TD1 claim exceeds the basic amount (ROC)', function () {
    // Baseline withholding at the basic provincial claim (AB employee from beforeEach).
    $run = buildRun();
    app(CalculatePayRun::class)->calculate($run);
    $line = $run->fresh()->lines->first();
    $baseProvincial = $line->provincial_tax_computed_cents;
    $baseFederal = $line->federal_tax_computed_cents;

    // Raise the provincial claim $10,000 above the basic amount, then recalculate.
    $this->profile->update(['td1_provincial_claim_cents' => 2232300 + 1000000]);
    $run->fresh()->forceFill(['status' => PayRunStatus::Draft])->save();
    app(CalculatePayRun::class)->calculate($run->fresh());
    $line = $run->fresh()->lines->first();

    // The extra claim credits more provincial tax; federal is untouched.
    expect($line->provincial_tax_computed_cents)->toBeLessThan($baseProvincial)
        ->and($line->federal_tax_computed_cents)->toBe($baseFederal);
});

it('lowers Quebec withholding when the TP-1015.3 personal claim exceeds the basic amount', function () {
    $this->company->update(['qhsf_rate_bp' => 192, 'cnesst_rate_bp' => 200]);
    $this->profile->update([
        'province_of_employment' => 'QC',
        'td1_provincial_claim_cents' => 1857100, // Quebec basic amount
    ]);

    $run = buildRun();
    app(CalculatePayRun::class)->calculate($run);
    $line = $run->fresh()->lines->first();
    $baseQuebec = $line->quebec_tax_computed_cents;

    // Raise the Quebec personal claim $10,000 above the basic amount, then recalculate.
    $this->profile->update(['td1_provincial_claim_cents' => 1857100 + 1000000]);
    $run->fresh()->forceFill(['status' => PayRunStatus::Draft])->save();
    app(CalculatePayRun::class)->calculate($run->fresh());
    $line = $run->fresh()->lines->first();

    expect($line->quebec_tax_computed_cents)->toBeLessThan($baseQuebec)
        ->and($line->provincial_tax_computed_cents)->toBe(0);
});

it('does not deduct CPP for an employee under 18 (birth-date age rule)', function () {
    // buildRun() pays on 2025-06-20; born 2008-01-01 → still 17 that day.
    $this->profile->update(['date_of_birth' => '2008-01-01']);

    $run = buildRun();
    app(CalculatePayRun::class)->calculate($run);
    $line = $run->fresh()->lines->first();

    expect($line->cpp_employee_computed_cents)->toBe(0)
        ->and($line->ei_employee_computed_cents)->toBeGreaterThan(0); // EI unaffected by age
});

it('withholds no income tax for an income-tax-exempt employee but still deducts CPP and EI', function () {
    $this->profile->update(['income_tax_exempt' => true]);

    $run = buildRun();
    app(CalculatePayRun::class)->calculate($run);
    $line = $run->fresh()->lines->first();

    expect($line->federal_tax_computed_cents)->toBe(0)
        ->and($line->provincial_tax_computed_cents)->toBe(0)
        ->and($line->cpp_employee_computed_cents)->toBeGreaterThan(0)
        ->and($line->ei_employee_computed_cents)->toBeGreaterThan(0);
});

it('counts mid-year opening YTD toward the annual EI maximum', function () {
    // 2025 max EI premium is 107748. Seed an opening just $7.48 below it, dated in
    // the pay year, so only the remaining room can be withheld this period.
    $this->profile->update([
        'opening_insurable_cents' => 6500000,
        'opening_ei_employee_cents' => 107000,
        'opening_balances_as_of' => '2025-01-15',
    ]);

    $run = buildRun(); // pays 2025-06-20
    app(CalculatePayRun::class)->calculate($run);
    $line = $run->fresh()->lines->first();

    expect($line->ei_employee_computed_cents)->toBe(748); // 107748 − 107000 room
});

it('ignores opening YTD balances from a different tax year', function () {
    // Same opening, but dated in 2024 → must not apply to a 2025 pay run.
    $this->profile->update([
        'opening_insurable_cents' => 6500000,
        'opening_ei_employee_cents' => 107000,
        'opening_balances_as_of' => '2024-01-15',
    ]);

    $run = buildRun(); // pays 2025-06-20
    app(CalculatePayRun::class)->calculate($run);
    $line = $run->fresh()->lines->first();

    expect($line->ei_employee_computed_cents)->toBe(3785); // full period premium
});

it('stops CPP and EI once the employee reaches the annual maximums via prior posted runs', function () {
    // A prior POSTED run that already hit the annual maximums.
    $prior = PayRun::factory()->create([
        'payroll_schedule_id' => $this->schedule->id,
        'pay_date' => '2025-06-06',
        'status' => PayRunStatus::Posted,
    ]);
    $prior->lines()->create([
        'contact_id' => $this->employee->id,
        'employee_payroll_profile_id' => $this->profile->id,
        'province_of_employment' => 'AB',
        'pay_basis' => PayBasis::Salary->value,
        'cpp_pensionable_cents' => 7130000,
        'ei_insurable_cents' => 6570000,
        'cpp_employee_computed_cents' => 403410,
        'ei_employee_computed_cents' => 107748,
    ]);

    $run = buildRun();
    app(CalculatePayRun::class)->calculate($run);
    $line = $run->fresh()->lines->first();

    expect($line->cpp_employee_computed_cents)->toBe(0)
        ->and($line->ei_employee_computed_cents)->toBe(0);
});
