<?php

use App\Actions\Payroll\SaveEmployeePayrollProfile;
use App\Enums\CompanyRole;
use App\Enums\PayBasis;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeeAccrualBalance;
use App\Models\EmployeePayrollProfile;
use App\Models\EmployeeTimeOffPolicy;
use App\Models\Membership;
use App\Models\PayrollSchedule;
use App\Models\TimeOffPolicy;
use App\Models\User;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Time-off opening balances (employee setup → running balance)
|--------------------------------------------------------------------------
|
| An opening balance lands on the policy's own SIDE of the employee's
| balance row — hours policies on the hours side, dollar policies on the
| cents side — and editing it later moves the balance by the difference.
| Both sides share one row per code: a 'vacation' HOURS policy rides beside
| the built-in DOLLAR vacation-pay accrual, which posting may create first.
*/

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    Membership::create(['company_id' => $this->company->id, 'user_id' => $this->user->id, 'role' => CompanyRole::Owner]);
    actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->schedule = PayrollSchedule::factory()->create();
    $this->employee = Contact::create(['display_name' => 'Opening Olive', 'is_employee' => true, 'is_active' => true]);
});

afterEach(fn () => app()->forgetInstance('current_company'));

/** Save the employee's profile with the given policy assignment rows. */
function tobSave(array $timeOffRows): EmployeePayrollProfile
{
    return app(SaveEmployeePayrollProfile::class)->handle([
        'contact_id' => test()->employee->id,
        'province_of_employment' => 'ON',
        'pay_basis' => PayBasis::Hourly->value,
        'hourly_rate_cents' => 3000,
        'payroll_schedule_id' => test()->schedule->id,
        'td1_federal_claim_cents' => 1612900,
        'td1_provincial_claim_cents' => 1294700,
        'vacation_policy' => 'accrue',
        'time_off_policies' => $timeOffRows,
    ], EmployeePayrollProfile::query()->where('contact_id', test()->employee->id)->first());
}

function tobPolicy(array $attrs = []): TimeOffPolicy
{
    return TimeOffPolicy::create(array_merge([
        'name' => 'Vacation', 'code' => 'vacation', 'category' => 'vacation', 'unit' => 'hours',
        'accrual_method' => 'manual', 'rate_hours' => 0, 'rate_bp' => 0, 'paid' => true, 'is_active' => true,
    ], $attrs));
}

function tobBalance(string $code): ?EmployeeAccrualBalance
{
    return EmployeeAccrualBalance::query()
        ->whereHas('profile', fn ($q) => $q->where('contact_id', test()->employee->id))
        ->where('code', $code)
        ->first();
}

it('seeds an hours opening even when the dollar vacation-pay accrual already owns the row', function () {
    $policy = tobPolicy();
    $profile = tobSave([]);

    // Posting created the dollar side first (the built-in vacation-pay accrual).
    EmployeeAccrualBalance::create([
        'employee_payroll_profile_id' => $profile->id, 'code' => 'vacation', 'name' => 'Vacation',
        'balance_cents' => 10000, 'accrued_ytd_cents' => 10000,
    ]);

    tobSave([['time_off_policy_id' => $policy->id, 'opening_balance' => 80]]);

    $balance = tobBalance('vacation');
    expect((float) $balance->balance_hours)->toBe(80.0)
        // The dollar side is untouched.
        ->and((int) $balance->balance_cents)->toBe(10000)
        ->and((int) $balance->accrued_ytd_cents)->toBe(10000);
});

it('self-heals an opening an earlier save swallowed, without double-seeding', function () {
    $policy = tobPolicy();
    $profile = tobSave([]);

    // The legacy bug: the assignment stored 80 but the balance never got it.
    EmployeeTimeOffPolicy::create([
        'employee_payroll_profile_id' => $profile->id, 'time_off_policy_id' => $policy->id,
        'opening_balance_hours' => 80, 'is_active' => true,
    ]);

    tobSave([['time_off_policy_id' => $policy->id, 'opening_balance' => 80]]);
    expect((float) tobBalance('vacation')->balance_hours)->toBe(80.0);

    // Saving again unchanged must not seed twice.
    tobSave([['time_off_policy_id' => $policy->id, 'opening_balance' => 80]]);
    expect((float) tobBalance('vacation')->balance_hours)->toBe(80.0);
});

it('moves the balance by the difference when the opening is edited after activity', function () {
    $policy = tobPolicy();
    tobSave([['time_off_policy_id' => $policy->id, 'opening_balance' => 80]]);

    // Accruals and usage have since moved the balance.
    $balance = tobBalance('vacation');
    $balance->forceFill(['balance_hours' => 81.5, 'accrued_ytd_hours' => 4.0, 'used_ytd_hours' => 2.5])->save();

    // Fixing the opening 80 → 100 adds exactly the 20-hour difference.
    tobSave([['time_off_policy_id' => $policy->id, 'opening_balance' => 100]]);

    $balance->refresh();
    expect((float) $balance->balance_hours)->toBe(101.5)
        ->and((float) $balance->accrued_ytd_hours)->toBe(4.0)
        ->and((float) $balance->used_ytd_hours)->toBe(2.5);
});

it('lands a dollar policy opening on the cents side and adjusts edits in cents', function () {
    $policy = tobPolicy(['name' => 'Vacation dollars', 'code' => 'vacation_dollars', 'unit' => 'dollars', 'category' => 'vacation']);

    tobSave([['time_off_policy_id' => $policy->id, 'opening_balance' => 250]]);
    expect((int) tobBalance('vacation_dollars')->balance_cents)->toBe(25000)
        ->and((float) tobBalance('vacation_dollars')->balance_hours)->toBe(0.0);

    // The cents side is seeded now, so an edit applies as a delta.
    tobSave([['time_off_policy_id' => $policy->id, 'opening_balance' => 300]]);
    expect((int) tobBalance('vacation_dollars')->balance_cents)->toBe(30000);

    // Unchanged saves are no-ops.
    tobSave([['time_off_policy_id' => $policy->id, 'opening_balance' => 300]]);
    expect((int) tobBalance('vacation_dollars')->balance_cents)->toBe(30000);
});
