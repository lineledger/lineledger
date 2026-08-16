<?php

use App\Actions\Payroll\PullTimeEntriesIntoPayRun;
use App\Actions\Payroll\SavePayRun;
use App\Actions\Payroll\SaveTimeEntry;
use App\Actions\Payroll\SaveTimeOffPolicy;
use App\Enums\CompanyRole;
use App\Enums\PayBasis;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\EmployeeTimeOffPolicy;
use App\Models\Membership;
use App\Models\PayrollSchedule;
use App\Models\PayRun;
use App\Models\TimeEntry;
use App\Models\TimeOffPolicy;
use App\Models\User;
use App\Services\Payroll\CalculatePayRun;
use App\Services\Payroll\Calculators\CppCalculator;
use App\Services\Payroll\Calculators\EiCalculator;
use App\Services\Payroll\Calculators\IncomeTaxCalculator;
use App\Services\Payroll\Calculators\QpipCalculator;
use App\Services\Payroll\Data\EarningsBreakdown;
use App\Services\Payroll\Data\EmployeePayrollContext;
use App\Services\Payroll\Data\YtdTotals;
use App\Services\Payroll\PayrollDeductionEngine;
use App\Services\Posting\PayRunPoster;
use App\Support\Payroll\Constants\PayrollConstantsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Regressions pinned from the deep bug hunt (2026-06)
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    Membership::create(['company_id' => $this->company->id, 'user_id' => $this->user->id, 'role' => CompanyRole::Owner]);
    actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->schedule = PayrollSchedule::factory()->create();

    $this->hourly = Contact::create(['display_name' => 'Reg Ression', 'is_employee' => true, 'is_active' => true]);
    $this->profile = EmployeePayrollProfile::factory()->create([
        'contact_id' => $this->hourly->id,
        'province_of_employment' => 'AB',
        'pay_basis' => PayBasis::Hourly->value,
        'hourly_rate_cents' => 3000,
        'payroll_schedule_id' => $this->schedule->id,
        'td1_federal_claim_cents' => 1612900,
        'td1_provincial_claim_cents' => 2232300,
        'is_active' => true,
    ]);
});

afterEach(fn () => app()->forgetInstance('current_company'));

function bhrRun(array $contactIds, string $start = '2025-06-01', string $end = '2025-06-14', ?PayRun $existing = null): PayRun
{
    $bank = Account::query()->where('code', '1000')->firstOrFail();

    return app(SavePayRun::class)->handle([
        'payroll_schedule_id' => test()->schedule->id,
        'period_start_date' => $start,
        'period_end_date' => $end,
        'pay_date' => '2025-06-20',
        'bank_account_id' => $bank->id,
        'lines' => array_map(fn (int $id) => ['contact_id' => $id], $contactIds),
    ], $existing);
}

it('releases a dropped employee\'s pulled entries instead of stranding them as consumed-but-unpaid', function () {
    $other = Contact::create(['display_name' => 'Other One', 'is_employee' => true, 'is_active' => true]);
    EmployeePayrollProfile::factory()->create([
        'contact_id' => $other->id, 'province_of_employment' => 'AB', 'pay_basis' => PayBasis::Hourly->value,
        'hourly_rate_cents' => 2500, 'payroll_schedule_id' => $this->schedule->id, 'is_active' => true,
    ]);

    $entry = TimeEntry::create(['contact_id' => $other->id, 'date_worked' => '2025-06-03', 'hours' => 8, 'status' => 'approved']);

    $run = bhrRun([$this->hourly->id, $other->id]);
    app(PullTimeEntriesIntoPayRun::class)->handle($run->fresh());
    expect($entry->fresh()->pay_run_id)->toBe($run->id);

    // Drop the employee from the run: the stamp must be released.
    bhrRun([$this->hourly->id], existing: $run->fresh());

    expect($entry->fresh()->pay_run_id)->toBeNull();
});

it('zeroes pull-produced hours when a re-pull finds no entries (period changed), but spares typed hours', function () {
    TimeEntry::create(['contact_id' => $this->hourly->id, 'date_worked' => '2025-06-03', 'hours' => 40, 'status' => 'approved']);

    $run = bhrRun([$this->hourly->id]);
    app(PullTimeEntriesIntoPayRun::class)->handle($run->fresh());
    expect((float) $run->lines()->firstOrFail()->hours_worked)->toBe(40.0);

    // Move the period off the entries and re-pull: the stale 40 must not
    // survive to be paid here AND wherever the released entry goes next.
    $run = bhrRun([$this->hourly->id], '2025-05-18', '2025-05-31', $run->fresh());
    app(PullTimeEntriesIntoPayRun::class)->handle($run->fresh());

    expect((float) $run->lines()->firstOrFail()->hours_worked)->toBe(0.0);

    // Typed hours with no pull history stay untouched.
    $typed = bhrRun([$this->hourly->id], '2025-07-01', '2025-07-14');
    $typed->lines()->firstOrFail()->update(['hours_worked' => 32]);
    app(PullTimeEntriesIntoPayRun::class)->handle($typed->fresh());
    expect((float) $typed->lines()->firstOrFail()->hours_worked)->toBe(32.0);
});

it('pulls an entry dated exactly on the period start (date-string comparison, both databases)', function () {
    $first = TimeEntry::create(['contact_id' => $this->hourly->id, 'date_worked' => '2025-06-01', 'hours' => 8, 'status' => 'approved']);

    $run = bhrRun([$this->hourly->id]);
    $summary = app(PullTimeEntriesIntoPayRun::class)->handle($run->fresh());

    expect($first->fresh()->pay_run_id)->toBe($run->id)
        ->and($summary['outside_period'])->toBe(0);
});

it('applies the pre-tax deduction (T4127 F) to income tax exactly once', function () {
    $engine = new PayrollDeductionEngine(
        new PayrollConstantsRepository,
        new CppCalculator,
        new EiCalculator,
        new IncomeTaxCalculator,
        new QpipCalculator,
    );
    $context = new EmployeePayrollContext(
        province: 'AB', payPeriodsPerYear: 26, payDate: CarbonImmutable::parse('2025-06-15'),
        federalClaimCents: 1612900, provincialClaimCents: 2232300,
        cppExempt: false, eiExempt: false, additionalTaxPerPeriodCents: 0, annualDeductionsCents: 0,
    );

    // $2,500 wages with a $100 pre-tax RRSP must tax the same annualized
    // income as $2,400 wages with no deduction (A = P × (I − F)).
    $withF = $engine->compute($context, new EarningsBreakdown(
        grossCents: 250000, pensionableCents: 250000, insurableCents: 250000,
        taxableCents: 250000, deductionsPerPeriodCents: 10000,
    ), YtdTotals::none());

    $without = $engine->compute($context, new EarningsBreakdown(
        grossCents: 240000, pensionableCents: 250000, insurableCents: 250000,
        taxableCents: 240000, deductionsPerPeriodCents: 0,
    ), YtdTotals::none());

    expect($withF->federalTaxCents)->toBe($without->federalTaxCents)
        ->and($withF->provincialTaxCents)->toBe($without->provincialTaxCents);
});

it('pays an operator-entered flat-amount leave payout instead of zeroing it', function () {
    $policy = TimeOffPolicy::create([
        'name' => 'Sick', 'code' => 'sick', 'category' => 'sick', 'unit' => 'hours',
        'accrual_method' => 'manual', 'rate_hours' => 0, 'rate_bp' => 0, 'paid' => true, 'is_active' => true,
    ]);
    EmployeeTimeOffPolicy::create(['employee_payroll_profile_id' => $this->profile->id, 'time_off_policy_id' => $policy->id, 'is_active' => true]);

    $run = bhrRun([$this->hourly->id]);
    $run->lines()->firstOrFail()->manualEarnings()->create([
        'code' => 'sick', 'name' => 'Sick payout', 'calc_kind' => 'amount', 'amount_cents' => 50000, 'line_order' => 0,
    ]);

    app(CalculatePayRun::class)->calculate($run->fresh());

    expect((int) $run->lines()->firstOrFail()->earnings->firstWhere('code', 'sick')?->amount_cents)->toBe(50000);
});

it('counts paid leave and overtime hours in insurable_hours for hourly employees (ROE 15A)', function () {
    $policy = TimeOffPolicy::create([
        'name' => 'Sick', 'code' => 'sick', 'category' => 'sick', 'unit' => 'hours',
        'accrual_method' => 'manual', 'rate_hours' => 0, 'rate_bp' => 0, 'paid' => true, 'is_active' => true,
    ]);
    EmployeeTimeOffPolicy::create(['employee_payroll_profile_id' => $this->profile->id, 'time_off_policy_id' => $policy->id, 'is_active' => true]);

    TimeEntry::create(['contact_id' => $this->hourly->id, 'date_worked' => '2025-06-02', 'hours' => 32, 'status' => 'approved']);
    TimeEntry::create(['contact_id' => $this->hourly->id, 'date_worked' => '2025-06-09', 'hours' => 8, 'pay_code' => 'sick', 'status' => 'approved']);
    TimeEntry::create(['contact_id' => $this->hourly->id, 'date_worked' => '2025-06-10', 'hours' => 4, 'pay_code' => 'overtime', 'status' => 'approved']);

    $run = bhrRun([$this->hourly->id]);
    app(PullTimeEntriesIntoPayRun::class)->handle($run->fresh());
    app(CalculatePayRun::class)->calculate($run->fresh());

    // 32 worked + 8 paid sick + 4 overtime = 44 insurable hours.
    expect((float) $run->lines()->firstOrFail()->insurable_hours)->toBe(44.0);
});

it('refuses to post a run that is not in the Calculated state', function () {
    TimeEntry::create(['contact_id' => $this->hourly->id, 'date_worked' => '2025-06-03', 'hours' => 8, 'status' => 'approved']);
    $run = bhrRun([$this->hourly->id]);

    expect(fn () => app(PayRunPoster::class)->post($run->fresh()))
        ->toThrow(RuntimeException::class, 'calculated');
});

it('prices a PULLED stat holiday at $0 for salaried employees but pays operator-entered stat rows', function () {
    $salaried = Contact::create(['display_name' => 'Sal A. Reed', 'is_employee' => true, 'is_active' => true]);
    EmployeePayrollProfile::factory()->create([
        'contact_id' => $salaried->id, 'province_of_employment' => 'AB', 'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 6240000, 'payroll_schedule_id' => $this->schedule->id, 'is_active' => true,
    ]);

    TimeEntry::create(['contact_id' => $salaried->id, 'date_worked' => '2025-06-02', 'hours' => 8, 'pay_code' => 'stat_holiday', 'status' => 'approved']);

    $run = bhrRun([$salaried->id]);
    app(PullTimeEntriesIntoPayRun::class)->handle($run->fresh());

    $line = $run->fresh()->lines()->firstOrFail();
    $line->manualEarnings()->create([
        'code' => 'stat_holiday', 'name' => 'Stat premium', 'calc_kind' => 'hours', 'hours' => 8, 'multiplier_bp' => 10000, 'line_order' => 5,
    ]);

    app(CalculatePayRun::class)->calculate($run->fresh());

    $earnings = $run->fresh()->lines()->firstOrFail()->earnings->where('code', 'stat_holiday');
    expect($earnings->where('amount_cents', 0)->count())->toBe(1)      // pulled day: salary already covers it
        ->and($earnings->where('amount_cents', '>', 0)->count())->toBe(1); // operator premium row pays
});

it('rejects a time-off policy that would hijack a reserved engine wage code', function () {
    app(SaveTimeOffPolicy::class)->handle([
        'name' => 'Overtime', 'category' => 'other', 'unit' => 'hours', 'accrual_method' => 'manual',
    ]);
})->throws(ValidationException::class);

it('locks a consumed time entry against content edits', function () {
    $entry = TimeEntry::create(['contact_id' => $this->hourly->id, 'date_worked' => '2025-06-03', 'hours' => 8, 'status' => 'approved']);
    $run = bhrRun([$this->hourly->id]);
    $entry->update(['pay_run_id' => $run->id]);

    expect(fn () => app(SaveTimeEntry::class)->handle([
        'contact_id' => $this->hourly->id, 'date_worked' => '2025-06-03', 'hours' => 12,
    ], $entry->fresh()))->toThrow(ValidationException::class);
});
