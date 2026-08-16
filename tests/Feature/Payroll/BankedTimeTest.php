<?php

use App\Actions\Payroll\PullTimeEntriesIntoPayRun;
use App\Actions\Payroll\SaveEmployeePayrollProfile;
use App\Actions\Payroll\SavePayRun;
use App\Actions\Payroll\SaveTimeEntry;
use App\Enums\CompanyRole;
use App\Enums\PayBasis;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeeAccrualBalance;
use App\Models\EmployeePayrollProfile;
use App\Models\JournalLine;
use App\Models\Membership;
use App\Models\PayrollSchedule;
use App\Models\PayRun;
use App\Models\TimeEntry;
use App\Models\TimeOffPolicy;
use App\Models\User;
use App\Services\Payroll\CalculatePayRun;
use App\Services\Posting\PayRunPoster;
use App\Support\Payroll\BankedOvertimeRules;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    Membership::create(['company_id' => $this->company->id, 'user_id' => $this->user->id, 'role' => CompanyRole::Owner]);
    actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->schedule = PayrollSchedule::factory()->create();
});

afterEach(fn () => app()->forgetInstance('current_company'));

/** An hourly $30/h employee in the given province, banking enabled via the action. */
function btEmployee(string $province, array $overrides = []): Contact
{
    $employee = Contact::create(['display_name' => "Banker {$province}", 'is_employee' => true, 'is_active' => true]);

    app(SaveEmployeePayrollProfile::class)->handle(array_merge([
        'contact_id' => $employee->id,
        'province_of_employment' => $province,
        'pay_basis' => PayBasis::Hourly->value,
        'hourly_rate_cents' => 3000,
        'payroll_schedule_id' => test()->schedule->id,
        'td1_federal_claim_cents' => 1612900,
        'td1_provincial_claim_cents' => 2232300,
        'vacation_policy' => 'accrue',
        'banked_overtime_enabled' => true,
        'banked_overtime_agreement_date' => '2025-01-15',
    ], $overrides));

    return $employee;
}

function btRun(Contact $employee): PayRun
{
    $bank = Account::query()->where('code', '1000')->firstOrFail();

    return app(SavePayRun::class)->handle([
        'payroll_schedule_id' => test()->schedule->id,
        'period_start_date' => '2025-06-01',
        'period_end_date' => '2025-06-14',
        'pay_date' => '2025-06-20',
        'bank_account_id' => $bank->id,
        'lines' => [['contact_id' => $employee->id]],
    ]);
}

function btBalance(Contact $employee): ?EmployeeAccrualBalance
{
    return EmployeeAccrualBalance::query()
        ->whereHas('profile', fn ($q) => $q->where('contact_id', $employee->id))
        ->where('code', 'banked')
        ->first();
}

// --- enabling --------------------------------------------------------------

it('seeds and assigns the banked policy when banking is enabled', function () {
    $employee = btEmployee('BC');

    $policy = TimeOffPolicy::query()->where('code', 'banked')->firstOrFail();
    expect($policy->paid)->toBeTrue()
        ->and($policy->accrual_method->value)->toBe('manual');

    $profile = EmployeePayrollProfile::query()->where('contact_id', $employee->id)->firstOrFail();
    expect($profile->timeOffPolicies()->where('time_off_policy_id', $policy->id)->exists())->toBeTrue();
});

it('blocks enabling banked overtime in New Brunswick', function () {
    btEmployee('NB');
})->throws(ValidationException::class);

it('blocks enabling banked overtime without a written-agreement date', function () {
    btEmployee('ON', ['banked_overtime_agreement_date' => null]);
})->throws(ValidationException::class);

// --- earning into the bank ------------------------------------------------

it('banks overtime at 1.5× in BC: $0 cash now, hours into the balance, reversed on void', function () {
    $employee = btEmployee('BC');

    foreach (['2025-06-02', '2025-06-03', '2025-06-04', '2025-06-05'] as $date) {
        TimeEntry::create(['contact_id' => $employee->id, 'date_worked' => $date, 'hours' => 8, 'status' => 'approved']);
    }
    TimeEntry::create(['contact_id' => $employee->id, 'date_worked' => '2025-06-06', 'hours' => 4, 'pay_code' => 'overtime_banked', 'status' => 'approved']);

    $run = btRun($employee);
    app(PullTimeEntriesIntoPayRun::class)->handle($run->fresh());
    app(CalculatePayRun::class)->calculate($run->fresh());

    $line = $run->lines()->firstOrFail();

    // 32 regular hours × $30 — the banked hours add NOTHING to gross or bases.
    expect((int) $line->gross_cents)->toBe(96000)
        ->and((int) $line->earnings->firstWhere('code', 'overtime_banked')?->amount_cents)->toBe(0);

    // 4 OT hours × 1.5 = 6 banked hours on the accrual row.
    $accrual = $line->accruals->firstWhere('code', 'banked');
    expect((float) $accrual?->hours)->toBe(6.0)
        ->and((int) $accrual?->amount_cents)->toBe(0);

    app(PayRunPoster::class)->post($run->fresh());
    expect((float) btBalance($employee)->balance_hours)->toBe(6.0);

    app(PayRunPoster::class)->void($run->fresh());
    expect((float) btBalance($employee)->balance_hours)->toBe(0.0);
});

it('banks at straight time in Alberta unless the profile overrides the rate', function () {
    $ab = btEmployee('AB');
    expect(BankedOvertimeRules::multiplierBpFor(EmployeePayrollProfile::query()->where('contact_id', $ab->id)->firstOrFail()))->toBe(10000);

    TimeEntry::create(['contact_id' => $ab->id, 'date_worked' => '2025-06-03', 'hours' => 4, 'pay_code' => 'overtime_banked', 'status' => 'approved']);

    $run = btRun($ab);
    app(PullTimeEntriesIntoPayRun::class)->handle($run->fresh());
    app(CalculatePayRun::class)->calculate($run->fresh());

    expect((float) $run->lines()->firstOrFail()->accruals->firstWhere('code', 'banked')?->hours)->toBe(4.0);

    // A pre-2019 Alberta agreement keeps 1.5× via the profile override.
    $legacy = btEmployee('AB', ['banked_overtime_multiplier_bp' => 15000]);
    TimeEntry::create(['contact_id' => $legacy->id, 'date_worked' => '2025-06-03', 'hours' => 4, 'pay_code' => 'overtime_banked', 'status' => 'approved']);

    $run2 = btRun($legacy);
    app(PullTimeEntriesIntoPayRun::class)->handle($run2->fresh());
    app(CalculatePayRun::class)->calculate($run2->fresh());

    expect((float) $run2->lines()->firstOrFail()->accruals->firstWhere('code', 'banked')?->hours)->toBe(6.0);
});

it('rejects an overtime_banked time entry for an employee who cannot bank', function () {
    $plain = Contact::create(['display_name' => 'No Bank Bob', 'is_employee' => true, 'is_active' => true]);
    EmployeePayrollProfile::factory()->create([
        'contact_id' => $plain->id,
        'province_of_employment' => 'ON',
        'pay_basis' => PayBasis::Hourly->value,
        'hourly_rate_cents' => 3000,
        'payroll_schedule_id' => $this->schedule->id,
        'is_active' => true,
    ]);

    app(SaveTimeEntry::class)->handle([
        'contact_id' => $plain->id,
        'date_worked' => '2025-06-02',
        'hours' => 4,
        'pay_code' => 'overtime_banked',
    ]);
})->throws(ValidationException::class);

it('fails loud at calculation time if banking was disabled after the row was created', function () {
    $employee = btEmployee('BC');
    $run = btRun($employee);

    $run->lines()->firstOrFail()->manualEarnings()->create([
        'code' => 'overtime_banked', 'name' => 'Overtime (bank the hours)', 'calc_kind' => 'hours', 'hours' => 4, 'multiplier_bp' => 15000, 'line_order' => 0,
    ]);

    EmployeePayrollProfile::query()->where('contact_id', $employee->id)->update(['banked_overtime_enabled' => false]);

    app(CalculatePayRun::class)->calculate($run->fresh());
})->throws(RuntimeException::class);

// --- taking / paying out ---------------------------------------------------

// --- liability mode ----------------------------------------------------------

it('liability mode posts DR wages / CR Banked Time Payable on earn, both reversed on void', function () {
    $this->company->update(['payroll_banked_overtime_liability' => true]);
    $employee = btEmployee('BC');

    TimeEntry::create(['contact_id' => $employee->id, 'date_worked' => '2025-06-02', 'hours' => 32, 'status' => 'approved']);
    TimeEntry::create(['contact_id' => $employee->id, 'date_worked' => '2025-06-06', 'hours' => 4, 'pay_code' => 'overtime_banked', 'status' => 'approved']);

    $run = btRun($employee);
    app(PullTimeEntriesIntoPayRun::class)->handle($run->fresh());
    app(CalculatePayRun::class)->calculate($run->fresh());

    // 4 OT h × 1.5 = 6 banked hours valued at the regular $30 rate = $180.
    $accrual = $run->lines()->firstOrFail()->accruals->firstWhere('code', 'banked');
    expect((float) $accrual?->hours)->toBe(6.0)
        ->and((int) $accrual?->amount_cents)->toBe(18000);

    app(PayRunPoster::class)->post($run->fresh());

    $payable = Account::query()->where('code', '2435')->firstOrFail();
    $credited = (int) JournalLine::query()->where('account_id', $payable->id)->where('is_posted', true)->sum('credit_cents');
    expect($credited)->toBe(18000);

    $balance = btBalance($employee);
    expect((float) $balance->balance_hours)->toBe(6.0)
        ->and((int) $balance->balance_cents)->toBe(18000);

    app(PayRunPoster::class)->void($run->fresh());

    $balance->refresh();
    expect((float) $balance->balance_hours)->toBe(0.0)
        ->and((int) $balance->balance_cents)->toBe(0);
});

it('liability mode relieves the payable when banked time is taken, drawing hours and dollars', function () {
    $this->company->update(['payroll_banked_overtime_liability' => true]);
    $employee = btEmployee('BC');
    $profile = EmployeePayrollProfile::query()->where('contact_id', $employee->id)->firstOrFail();

    EmployeeAccrualBalance::create([
        'employee_payroll_profile_id' => $profile->id, 'code' => 'banked', 'name' => 'Banked time',
        'balance_hours' => 10, 'balance_cents' => 30000,
    ]);

    TimeEntry::create(['contact_id' => $employee->id, 'date_worked' => '2025-06-04', 'hours' => 6, 'pay_code' => 'banked', 'status' => 'approved']);

    $run = btRun($employee);
    app(PullTimeEntriesIntoPayRun::class)->handle($run->fresh());
    app(CalculatePayRun::class)->calculate($run->fresh());
    app(PayRunPoster::class)->post($run->fresh());

    // Taking 6 h ($180) debits the payable instead of re-expensing wages …
    $payable = Account::query()->where('code', '2435')->firstOrFail();
    $debited = (int) JournalLine::query()->where('account_id', $payable->id)->where('is_posted', true)->sum('debit_cents');
    expect($debited)->toBe(18000);

    // … and draws both sides of the balance.
    $balance = btBalance($employee)->refresh();
    expect((float) $balance->balance_hours)->toBe(4.0)
        ->and((int) $balance->balance_cents)->toBe(12000)
        ->and((float) $balance->used_ytd_hours)->toBe(6.0)
        ->and((int) $balance->used_ytd_cents)->toBe(18000);

    app(PayRunPoster::class)->void($run->fresh());

    $balance->refresh();
    expect((float) $balance->balance_hours)->toBe(10.0)
        ->and((int) $balance->balance_cents)->toBe(30000);
});

it('pays a banked day at the regular rate and draws the balance down', function () {
    $employee = btEmployee('BC');
    $profile = EmployeePayrollProfile::query()->where('contact_id', $employee->id)->firstOrFail();

    EmployeeAccrualBalance::create([
        'employee_payroll_profile_id' => $profile->id, 'code' => 'banked', 'name' => 'Banked time', 'balance_hours' => 10,
    ]);

    TimeEntry::create(['contact_id' => $employee->id, 'date_worked' => '2025-06-04', 'hours' => 6, 'pay_code' => 'banked', 'status' => 'approved']);

    $run = btRun($employee);
    app(PullTimeEntriesIntoPayRun::class)->handle($run->fresh());
    app(CalculatePayRun::class)->calculate($run->fresh());

    $line = $run->lines()->firstOrFail();

    // 6 banked hours taken pay 6 × $30 at the REGULAR rate.
    expect((int) $line->earnings->firstWhere('code', 'banked')?->amount_cents)->toBe(18000);

    app(PayRunPoster::class)->post($run->fresh());

    $balance = btBalance($employee);
    expect((float) $balance->balance_hours)->toBe(4.0)
        ->and((float) $balance->used_ytd_hours)->toBe(6.0);
});
