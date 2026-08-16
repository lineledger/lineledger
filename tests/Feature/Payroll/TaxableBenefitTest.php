<?php

use App\Actions\Payroll\SaveEmployeePayrollProfile;
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
use App\Services\Reporting\T4SlipCalculator;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->schedule = PayrollSchedule::factory()->create();
    $this->bank = Account::query()->where('code', '1000')->firstOrFail();
    $this->employee = Contact::create(['display_name' => 'Bennie Fitt', 'is_employee' => true]);
    $this->profile = EmployeePayrollProfile::factory()->create([
        'contact_id' => $this->employee->id,
        'province_of_employment' => 'AB',
        'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 6000000,
        'payroll_schedule_id' => $this->schedule->id,
        'td1_federal_claim_cents' => 1612900,
        'td1_provincial_claim_cents' => 2232300,
        'vacation_policy' => 'accrue',
        'vacation_rate_bp' => 400,
    ]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function tbAddRecurring(array $item): void
{
    $profile = test()->profile;

    app(SaveEmployeePayrollProfile::class)->handle([
        'contact_id' => $profile->contact_id,
        'province_of_employment' => $profile->province_of_employment,
        'pay_basis' => $profile->pay_basis->value,
        'annual_salary_cents' => $profile->annual_salary_cents,
        'payroll_schedule_id' => $profile->payroll_schedule_id,
        'td1_federal_claim_cents' => $profile->td1_federal_claim_cents,
        'td1_provincial_claim_cents' => $profile->td1_provincial_claim_cents,
        'vacation_policy' => $profile->vacation_policy->value,
        'vacation_rate_bp' => $profile->vacation_rate_bp,
        'recurring_items' => [$item],
    ], $profile->fresh());
}

function tbRun(): PayRun
{
    return app(SavePayRun::class)->handle([
        'payroll_schedule_id' => test()->schedule->id,
        'period_start_date' => '2025-06-01',
        'period_end_date' => '2025-06-14',
        'pay_date' => '2025-06-20',
        'bank_account_id' => test()->bank->id,
        'lines' => [['contact_id' => test()->employee->id]],
    ]);
}

function tbJeSide(PayRun $run, string $code, string $side): int
{
    $accountId = Account::query()->where('code', $code)->value('id');

    return (int) $run->journalEntry->lines->where('account_id', $accountId)->sum($side.'_cents');
}

/** A CPP-pensionable, taxable, non-EI-insurable employer benefit (e.g. group life). */
function taxableBenefit(array $overrides = []): array
{
    return array_merge([
        'kind' => 'contribution', 'code' => 'group_life', 'name' => 'Group life (taxable)',
        'calc_type' => 'fixed', 'amount_cents' => 10000, 't4_box' => '40',
        'taxable_federal' => true, 'taxable_provincial' => true, 'cpp_qpp' => true,
        'ei_insurable_earnings' => false,
    ], $overrides);
}

it('takes the tax on a taxable benefit out of net pay without paying it as cash', function () {
    // Baseline: identical run with no benefit (left as a draft so it never adds YTD).
    $base = tbRun();
    app(CalculatePayRun::class)->calculate($base);
    $baseLine = $base->fresh()->lines->first();

    tbAddRecurring(taxableBenefit());

    $run = tbRun();
    app(CalculatePayRun::class)->calculate($run);
    $line = $run->fresh()->lines->first();

    expect($line->gross_cents)->toBe($baseLine->gross_cents)                                  // cash gross unchanged — benefit is non-cash
        ->and($line->cpp_pensionable_cents)->toBe($baseLine->cpp_pensionable_cents + 10000)    // benefit lifts the pensionable base
        ->and($line->cppEmployeeCents())->toBeGreaterThan($baseLine->cppEmployeeCents())        // …so CPP rises
        ->and($line->incomeTaxCents())->toBeGreaterThan($baseLine->incomeTaxCents())            // …and income tax rises
        ->and($line->net_cents)->toBeLessThan($baseLine->net_cents)                             // the extra withholding comes out of net
        ->and($line->net_cents)->toBe($line->gross_cents - $line->totalEmployeeDeductionsCents()); // net still ties to cash gross − deductions

    // Persisted as a non-cash, bases-only earning carrying the benefit's own flags.
    $notional = $line->earnings->firstWhere('add_to_bases_only', true);
    expect($notional)->not->toBeNull()
        ->and((int) $notional->amount_cents)->toBe(10000)
        ->and((bool) $notional->is_pensionable)->toBeTrue()
        ->and((bool) $notional->is_insurable)->toBeFalse()
        ->and((bool) $notional->is_taxable)->toBeTrue();
});

it('raises EI insurable earnings for an EI-insurable taxable benefit', function () {
    $base = tbRun();
    app(CalculatePayRun::class)->calculate($base);
    $baseLine = $base->fresh()->lines->first();

    tbAddRecurring(taxableBenefit([
        'code' => 'taxable_ei_benefit', 'ei_insurable_earnings' => true,
    ]));

    $run = tbRun();
    app(CalculatePayRun::class)->calculate($run);
    $line = $run->fresh()->lines->first();

    expect($line->ei_insurable_cents)->toBe($baseLine->ei_insurable_cents + 10000)
        ->and($line->eiEmployeeCents())->toBeGreaterThan($baseLine->eiEmployeeCents())
        ->and($line->gross_cents)->toBe($baseLine->gross_cents);
});

it('does not create a notional earning for a non-taxable employer benefit', function () {
    // Private health (no tax impact) posts only its employer cost; no bases-only earning.
    tbAddRecurring([
        'kind' => 'contribution', 'code' => 'private_health', 'name' => 'Extended Health ER',
        'calc_type' => 'fixed', 'amount_cents' => 10000,
        'taxable_federal' => false, 'taxable_provincial' => false, 'cpp_qpp' => false, 'ei_insurable_earnings' => false,
    ]);

    $run = tbRun();
    app(CalculatePayRun::class)->calculate($run);
    $line = $run->fresh()->lines->first();

    expect($line->earnings->firstWhere('add_to_bases_only', true))->toBeNull()
        ->and((int) $line->contributions->firstWhere('code', 'private_health')?->amount_cents)->toBe(10000);
});

it('posts a taxable benefit via the contribution leg only, keeping the entry balanced', function () {
    tbAddRecurring(taxableBenefit());

    $run = tbRun();
    app(CalculatePayRun::class)->calculate($run);
    $gross = (int) $run->fresh()->lines->first()->gross_cents;

    app(PayRunPoster::class)->post($run->fresh());
    $run = $run->fresh()->load('journalEntry.lines', 'lines');

    expect($run->journalEntry->lines->sum('debit_cents'))->toBe($run->journalEntry->lines->sum('credit_cents'))
        ->and(tbJeSide($run, '6260', 'debit'))->toBe(10000)   // employer benefit expense
        ->and(tbJeSide($run, '2470', 'credit'))->toBe(10000)  // employee benefits payable
        ->and(tbJeSide($run, '6200', 'debit'))->toBe($gross); // wages == cash gross — no phantom wage for the non-cash benefit
});

it('includes a taxable benefit in T4 box 14 and box 40 exactly once', function () {
    tbAddRecurring(taxableBenefit());

    $run = tbRun();
    app(CalculatePayRun::class)->calculate($run);
    $gross = (int) $run->fresh()->lines->first()->gross_cents;

    app(PayRunPoster::class)->post($run->fresh());

    $slips = app(T4SlipCalculator::class)->slipsForYear($this->company, 2025);

    expect($slips[0]['box14'])->toBe($gross + 10000)      // employment income includes the benefit
        ->and($slips[0]['other']['40'] ?? 0)->toBe(10000); // box 40 once, from the contribution row
});

it('does not inflate the payroll register gross with a taxable benefit', function () {
    tbAddRecurring(taxableBenefit());

    $run = tbRun();
    app(CalculatePayRun::class)->calculate($run);
    $gross = (int) $run->fresh()->lines->first()->gross_cents;

    app(PayRunPoster::class)->post($run->fresh());

    $summary = app(PayrollRegisterCalculator::class)->summary(
        $this->company,
        CarbonImmutable::parse('2025-06-01'),
        CarbonImmutable::parse('2025-06-30'),
    );

    // The register's gross is cash gross — the non-cash benefit is excluded.
    expect($summary['gross_cents'])->toBe($gross);
});
