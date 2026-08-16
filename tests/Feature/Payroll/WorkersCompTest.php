<?php

use App\Actions\Payroll\RecordPayrollRemittance;
use App\Actions\Payroll\SavePayRun;
use App\Enums\CompanyRole;
use App\Enums\PayBasis;
use App\Enums\RemittanceStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollSchedule;
use App\Models\User;
use App\Models\WorkersCompSetting;
use App\Services\Payroll\CalculatePayRun;
use App\Services\Payroll\WorkersCompCalculator;
use App\Services\Posting\PayRunPoster;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->schedule = PayrollSchedule::factory()->create();
    $this->bank = Account::query()->where('code', '1000')->firstOrFail();

    // Ontario WSIB at $2.50 per $100 = 2.5% = 250 bp.
    WorkersCompSetting::create(['province' => 'ON', 'rate_bp' => 250, 'is_active' => true]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function wcProfile(string $province, array $overrides = []): EmployeePayrollProfile
{
    $contact = Contact::create(['display_name' => 'WC '.$province.' '.fake()->unique()->word(), 'is_employee' => true]);

    return EmployeePayrollProfile::factory()->create(array_merge([
        'contact_id' => $contact->id,
        'province_of_employment' => $province,
        'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 6000000,
        'payroll_schedule_id' => test()->schedule->id,
        'td1_federal_claim_cents' => 1612900,
        'td1_provincial_claim_cents' => 2232300,
    ], $overrides));
}

function acctSum(string $code, string $side): int
{
    $accountId = Account::query()->where('code', $code)->value('id');

    return (int) DB::table('journal_lines')
        ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
        ->where('journal_entries.company_id', test()->company->id)
        ->where('journal_lines.account_id', $accountId)
        ->sum($side.'_cents');
}

it('computes the province rate, and returns 0 for Quebec, exempt, override and cap', function () {
    $calc = app(WorkersCompCalculator::class);
    $plain = wcProfile('ON');

    // ON $1,000 gross × 2.5% = $25.00.
    expect($calc->compute($this->company, $plain, 'ON', 100000, 0))->toBe(2500)
        // Quebec is CNESST, not WC.
        ->and($calc->compute($this->company, $plain, 'QC', 100000, 0))->toBe(0)
        // No setting for BC → 0.
        ->and($calc->compute($this->company, $plain, 'BC', 100000, 0))->toBe(0);

    // Exempt employee → 0.
    $exempt = wcProfile('ON', ['workers_comp_exempt' => true]);
    expect($calc->compute($this->company, $exempt, 'ON', 100000, 0))->toBe(0);

    // Per-employee rate override (1.0% = 100 bp) wins over the province's 2.5%.
    $override = wcProfile('ON', ['workers_comp_rate_bp' => 100]);
    expect($calc->compute($this->company, $override, 'ON', 100000, 0))->toBe(1000);

    // Annual max caps the assessable: max $90,000, $88,000 already YTD → only $2,000
    // assessable this period regardless of the $10,000 gross.
    WorkersCompSetting::where('province', 'ON')->update(['annual_max_assessable_cents' => 9000000]);
    // A fresh calculator instance (the per-instance cache above predates the update).
    expect((new WorkersCompCalculator)->compute($this->company, $plain, 'ON', 1000000, 8800000))->toBe(5000); // $2,000 × 2.5%
});

it('posts the WC levy as DR 6280 / CR 2426 for an Ontario run, balanced', function () {
    $profile = wcProfile('ON');

    $run = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => $this->schedule->id,
        'period_start_date' => '2025-06-01',
        'period_end_date' => '2025-06-14',
        'pay_date' => '2025-06-20',
        'bank_account_id' => $this->bank->id,
        'lines' => [['contact_id' => $profile->contact_id]],
    ]);
    app(CalculatePayRun::class)->calculate($run);
    $line = $run->fresh()->lines->first();

    // gross 230769 × 2.5% = 5769.
    $expected = (int) round((int) $line->gross_cents * 0.025);
    expect((int) $line->wc_employer_computed_cents)->toBe($expected)
        ->and($expected)->toBeGreaterThan(0);

    app(PayRunPoster::class)->post($run->fresh());

    expect(acctSum('6280', 'debit'))->toBe($expected)   // workers' comp expense
        ->and(acctSum('2426', 'credit'))->toBe($expected) // workers' comp payable
        ->and($run->fresh()->journalEntry->lines->sum('debit_cents'))->toBe($run->fresh()->journalEntry->lines->sum('credit_cents'));
});

it('records a workers comp remittance that clears the 2426 payable', function () {
    $profile = wcProfile('ON');

    $run = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => $this->schedule->id,
        'period_start_date' => '2025-06-01', 'period_end_date' => '2025-06-14', 'pay_date' => '2025-06-20',
        'bank_account_id' => $this->bank->id, 'lines' => [['contact_id' => $profile->contact_id]],
    ]);
    app(CalculatePayRun::class)->calculate($run);
    app(PayRunPoster::class)->post($run->fresh());

    $wc = (int) $run->fresh()->lines->first()->wc_employer_computed_cents;
    expect($wc)->toBeGreaterThan(0)
        ->and(acctSum('2426', 'credit') - acctSum('2426', 'debit'))->toBe($wc); // outstanding before remitting

    $remittance = app(RecordPayrollRemittance::class)->handle([
        'agency' => 'workers_comp',
        'period_start' => '2025-06-01', 'period_end' => '2025-06-30', 'due_date' => '2025-07-15',
        'bank_account_id' => $this->bank->id, 'payment_date' => '2025-07-10',
    ]);

    expect($remittance->status)->toBe(RemittanceStatus::Paid)
        ->and($remittance->total_cents)->toBe($wc)
        ->and(acctSum('2426', 'credit') - acctSum('2426', 'debit'))->toBe(0); // cleared by the remittance JE
});

it('saves per-province workers comp rates in settings', function () {
    Livewire::test('pages::settings.payroll', ['company' => $this->company])
        ->set('f_standard_annual_hours', 2080)
        ->set('f_wc', [
            ['province' => 'ON', 'rate' => '2.50', 'annual_max' => '', 'board_account' => ''],
            ['province' => 'BC', 'rate' => '1.55', 'annual_max' => '113000', 'board_account' => 'WCB-9'],
        ])
        ->call('save')
        ->assertHasNoErrors();

    $bc = WorkersCompSetting::where('province', 'BC')->firstOrFail();
    expect($bc->rate_bp)->toBe(155)
        ->and((int) $bc->annual_max_assessable_cents)->toBe(11300000)
        ->and($bc->board_account)->toBe('WCB-9');
});

it('renders the workers comp remittance page', function () {
    $this->get(route('payroll.reports.workers-comp', $this->company))->assertOk();
});

it('does not compute WC for a Quebec employee (CNESST covers QC)', function () {
    $this->company->update(['cnesst_rate_bp' => 200]);
    $profile = wcProfile('QC');

    $run = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => $this->schedule->id,
        'period_start_date' => '2025-06-01',
        'period_end_date' => '2025-06-14',
        'pay_date' => '2025-06-20',
        'bank_account_id' => $this->bank->id,
        'lines' => [['contact_id' => $profile->contact_id]],
    ]);
    app(CalculatePayRun::class)->calculate($run);
    $line = $run->fresh()->lines->first();

    expect((int) $line->wc_employer_computed_cents)->toBe(0)
        ->and((int) $line->cnesst_employer_computed_cents)->toBeGreaterThan(0);
});
