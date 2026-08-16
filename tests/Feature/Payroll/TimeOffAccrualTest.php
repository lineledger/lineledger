<?php

use App\Actions\Payroll\SavePayRun;
use App\Enums\CompanyRole;
use App\Enums\PayBasis;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeeAccrualBalance;
use App\Models\EmployeePayrollProfile;
use App\Models\EmployeeTimeOffPolicy;
use App\Models\PayrollSchedule;
use App\Models\PayRun;
use App\Models\TimeOffPolicy;
use App\Models\User;
use App\Services\Payroll\CalculatePayRun;
use App\Services\Payroll\TimeOffAccrualService;
use App\Services\Posting\PayRunPoster;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->schedule = PayrollSchedule::factory()->create();
    $this->bank = Account::query()->where('code', '1000')->firstOrFail();
    $this->employee = Contact::create(['display_name' => 'Tina Mauf', 'is_employee' => true]);
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

function toPolicy(array $attrs): TimeOffPolicy
{
    return TimeOffPolicy::create(array_merge([
        'name' => 'Sick', 'code' => 'sick', 'category' => 'sick', 'unit' => 'hours',
        'accrual_method' => 'per_pay_period', 'rate_hours' => 10, 'rate_bp' => 0, 'paid' => true,
        'is_active' => true,
    ], $attrs));
}

function toAssign(TimeOffPolicy $policy): EmployeeTimeOffPolicy
{
    return EmployeeTimeOffPolicy::create([
        'employee_payroll_profile_id' => test()->profile->id,
        'time_off_policy_id' => $policy->id,
        'is_active' => true,
    ]);
}

function toBalance(string $code): ?EmployeeAccrualBalance
{
    return EmployeeAccrualBalance::query()
        ->where('employee_payroll_profile_id', test()->profile->id)
        ->where('code', $code)
        ->first();
}

function toRun(string $payDate, array $manualEarnings = []): PayRun
{
    $line = ['contact_id' => test()->employee->id];
    if ($manualEarnings !== []) {
        $line['manual_earnings'] = $manualEarnings;
    }

    $run = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => test()->schedule->id,
        'period_start_date' => $payDate,
        'period_end_date' => $payDate,
        'pay_date' => $payDate,
        'bank_account_id' => test()->bank->id,
        'lines' => [$line],
    ]);
    app(CalculatePayRun::class)->calculate($run);

    return $run->fresh();
}

it('accrues a per-period policy into the balance and respects the annual cap', function () {
    toAssign(toPolicy(['accrual_method' => 'per_pay_period', 'rate_hours' => 10, 'annual_cap_hours' => 15]));

    $r1 = toRun('2025-06-06');
    app(PayRunPoster::class)->post($r1);
    expect((float) toBalance('sick')->balance_hours)->toBe(10.0)
        ->and((float) toBalance('sick')->accrued_ytd_hours)->toBe(10.0);

    $r2 = toRun('2025-06-20'); // would accrue 10 more, but cap leaves only 5 of room
    app(PayRunPoster::class)->post($r2);
    expect((float) toBalance('sick')->balance_hours)->toBe(15.0);

    $r3 = toRun('2025-07-04'); // cap reached → accrues nothing
    app(PayRunPoster::class)->post($r3);
    expect((float) toBalance('sick')->balance_hours)->toBe(15.0);

    // Voiding the middle run reverses exactly its 5 accrued hours.
    app(PayRunPoster::class)->void($r2->fresh());
    expect((float) toBalance('sick')->balance_hours)->toBe(10.0);
});

it('draws a paid time-off use down from the balance and raises used YTD, reversing on void', function () {
    toAssign(toPolicy(['accrual_method' => 'manual'])); // manual: no per-period accrual to muddy the draw
    EmployeeAccrualBalance::create([
        'employee_payroll_profile_id' => $this->profile->id, 'code' => 'sick', 'name' => 'Sick', 'balance_hours' => 40,
    ]);

    $run = toRun('2025-06-20', [[
        'code' => 'sick', 'name' => 'Sick taken', 'calc_kind' => 'hours', 'hours' => 8, 'multiplier_bp' => 10000,
    ]]);
    $line = $run->lines->first();

    // Paid sick pays hours × the salaried hourly rate, and rides in gross.
    expect((int) $line->earnings->firstWhere('code', 'sick')?->amount_cents)->toBeGreaterThan(0)
        ->and((float) $line->earnings->firstWhere('code', 'sick')?->hours)->toBe(8.0);

    app(PayRunPoster::class)->post($run);
    expect((float) toBalance('sick')->balance_hours)->toBe(32.0)
        ->and((float) toBalance('sick')->used_ytd_hours)->toBe(8.0);

    app(PayRunPoster::class)->void($run->fresh());
    expect((float) toBalance('sick')->balance_hours)->toBe(40.0)
        ->and((float) toBalance('sick')->used_ytd_hours)->toBe(0.0);
});

it('records hours but pays nothing for an unpaid time-off policy', function () {
    toAssign(toPolicy(['code' => 'unpaid_leave', 'name' => 'Unpaid leave', 'category' => 'unpaid', 'accrual_method' => 'manual', 'paid' => false]));
    EmployeeAccrualBalance::create([
        'employee_payroll_profile_id' => $this->profile->id, 'code' => 'unpaid_leave', 'name' => 'Unpaid leave', 'balance_hours' => 24,
    ]);

    $run = toRun('2025-06-20', [[
        'code' => 'unpaid_leave', 'name' => 'Unpaid leave', 'calc_kind' => 'hours', 'hours' => 8, 'multiplier_bp' => 10000,
    ]]);
    $line = $run->lines->first();

    expect((int) $line->earnings->firstWhere('code', 'unpaid_leave')?->amount_cents)->toBe(0)
        ->and((float) $line->earnings->firstWhere('code', 'unpaid_leave')?->hours)->toBe(8.0);

    app(PayRunPoster::class)->post($run);
    expect((float) toBalance('unpaid_leave')->balance_hours)->toBe(16.0)
        ->and((float) toBalance('unpaid_leave')->used_ytd_hours)->toBe(8.0);
});

it('grants a beginning-of-year lump once, applying the carryover cap and resetting YTD', function () {
    $policy = toPolicy([
        'code' => 'personal', 'name' => 'Personal', 'category' => 'personal',
        'accrual_method' => 'beginning_of_year', 'rate_hours' => 80,
        'annual_cap_hours' => 80, 'carryover_max_hours' => 40,
    ]);
    $assignment = toAssign($policy);
    // The assignment lived through last year — this run IS a year boundary
    // (a fresh, never-processed assignment is enrollment instead; see below).
    $assignment->forceFill(['last_accrued_on' => '2024-01-01'])->save();

    // Last year ended with 50 h on the books and some used YTD.
    EmployeeAccrualBalance::create([
        'employee_payroll_profile_id' => $this->profile->id, 'code' => 'personal', 'name' => 'Personal',
        'balance_hours' => 50, 'accrued_ytd_hours' => 80, 'used_ytd_hours' => 30,
    ]);

    $advanced = app(TimeOffAccrualService::class)->accrueForCompany($this->company, CarbonImmutable::parse('2025-01-05'));

    // Carry over min(50, 40) = 40, reset YTD, then grant 80 → 120.
    expect($advanced)->toBe(1)
        ->and((float) toBalance('personal')->balance_hours)->toBe(120.0)
        ->and((float) toBalance('personal')->accrued_ytd_hours)->toBe(80.0)
        ->and((float) toBalance('personal')->used_ytd_hours)->toBe(0.0)
        ->and($assignment->fresh()->last_accrued_on->toDateString())->toBe('2025-01-01');

    // Idempotent: a second run in the same year grants nothing.
    $again = app(TimeOffAccrualService::class)->accrueForCompany($this->company, CarbonImmutable::parse('2025-02-01'));
    expect($again)->toBe(0)
        ->and((float) toBalance('personal')->balance_hours)->toBe(120.0);
});

it('treats a mid-year assignment as ENROLLMENT: no carryover clamp, no YTD reset, opening preserved', function () {
    // Per-period policy with a small carryover cap: the nightly run right
    // after assignment must NOT clamp the freshly-seeded 16 h opening to 8.
    $policy = toPolicy([
        'code' => 'sick_cap', 'name' => 'Sick (capped)', 'accrual_method' => 'per_pay_period',
        'rate_hours' => 1.5, 'carryover_max_hours' => 8,
    ]);
    $assignment = toAssign($policy);
    EmployeeAccrualBalance::create([
        'employee_payroll_profile_id' => $this->profile->id, 'code' => 'sick_cap', 'name' => 'Sick (capped)',
        'balance_hours' => 16, 'accrued_ytd_hours' => 2, 'used_ytd_hours' => 1,
    ]);

    app(TimeOffAccrualService::class)->accrueForCompany($this->company, CarbonImmutable::parse('2025-06-15'));

    expect((float) toBalance('sick_cap')->balance_hours)->toBe(16.0)
        ->and((float) toBalance('sick_cap')->accrued_ytd_hours)->toBe(2.0)
        ->and((float) toBalance('sick_cap')->used_ytd_hours)->toBe(1.0)
        // …but the cycle is stamped, so NEXT Jan 1 clamps and resets normally.
        ->and($assignment->fresh()->last_accrued_on->toDateString())->toBe('2025-01-01');

    // A lump policy assigned mid-year WITH an opening balance: the opening IS
    // the remaining entitlement — no lump stacked on top.
    $boy = toPolicy([
        'code' => 'boy_open', 'name' => 'Personal (opening)', 'accrual_method' => 'beginning_of_year', 'rate_hours' => 24,
    ]);
    EmployeeTimeOffPolicy::create([
        'employee_payroll_profile_id' => $this->profile->id, 'time_off_policy_id' => $boy->id,
        'opening_balance_hours' => 10, 'is_active' => true,
    ]);
    EmployeeAccrualBalance::create([
        'employee_payroll_profile_id' => $this->profile->id, 'code' => 'boy_open', 'name' => 'Personal (opening)',
        'balance_hours' => 10,
    ]);

    app(TimeOffAccrualService::class)->accrueForCompany($this->company, CarbonImmutable::parse('2025-06-16'));
    expect((float) toBalance('boy_open')->balance_hours)->toBe(10.0);

    // A lump policy assigned mid-year WITHOUT an opening: the year's allotment
    // is granted at enrollment.
    $boyFresh = toPolicy([
        'code' => 'boy_fresh', 'name' => 'Personal (fresh)', 'accrual_method' => 'beginning_of_year', 'rate_hours' => 24,
    ]);
    EmployeeTimeOffPolicy::create([
        'employee_payroll_profile_id' => $this->profile->id, 'time_off_policy_id' => $boyFresh->id, 'is_active' => true,
    ]);

    app(TimeOffAccrualService::class)->accrueForCompany($this->company, CarbonImmutable::parse('2025-06-17'));
    expect((float) toBalance('boy_fresh')->balance_hours)->toBe(24.0);
});

it('grants an anniversary lump only once the hire-date anniversary has arrived', function () {
    $this->profile->forceFill(['hire_date' => '2020-03-15'])->save();
    toAssign(toPolicy([
        'code' => 'anniv', 'name' => 'Anniversary leave', 'category' => 'personal',
        'accrual_method' => 'anniversary', 'rate_hours' => 40,
    ]));

    // Before the anniversary: nothing.
    $before = app(TimeOffAccrualService::class)->accrueForCompany($this->company, CarbonImmutable::parse('2025-03-10'));
    expect($before)->toBe(0)
        ->and(toBalance('anniv'))->toBeNull();

    // On/after the anniversary: grant 40 via the command.
    $this->artisan('payroll:accrue-time-off', ['--date' => '2025-03-20'])->assertSuccessful();
    expect((float) toBalance('anniv')->balance_hours)->toBe(40.0);
});

it('wires the vacation balance and mirrors it onto the profile, reversing on void', function () {
    $run = toRun('2025-06-20');
    $accrued = (int) $run->lines->first()->vacation_accrued_cents;

    expect($accrued)->toBeGreaterThan(0);

    app(PayRunPoster::class)->post($run);
    expect((int) toBalance('vacation')->balance_cents)->toBe($accrued)
        ->and((int) $this->profile->fresh()->vacation_balance_cents)->toBe($accrued);

    app(PayRunPoster::class)->void($run->fresh());
    expect((int) toBalance('vacation')->balance_cents)->toBe(0)
        ->and((int) $this->profile->fresh()->vacation_balance_cents)->toBe(0);
});
