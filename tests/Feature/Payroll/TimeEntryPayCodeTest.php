<?php

use App\Actions\Payroll\PullTimeEntriesIntoPayRun;
use App\Actions\Payroll\SavePayRun;
use App\Actions\Payroll\SaveTimeEntry;
use App\Actions\Portal\SaveOwnTimeEntry;
use App\Enums\AuditAction;
use App\Enums\CompanyRole;
use App\Enums\PayBasis;
use App\Models\Account;
use App\Models\AccountingAuditLog;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\EmployeeTimeOffPolicy;
use App\Models\Membership;
use App\Models\PayrollSchedule;
use App\Models\PayRun;
use App\Models\PayRunLineManualEarning;
use App\Models\TimeEntry;
use App\Models\TimeOffPolicy;
use App\Models\User;
use App\Services\Payroll\CalculatePayRun;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    Membership::create(['company_id' => $this->company->id, 'user_id' => $this->user->id, 'role' => CompanyRole::Owner]);
    actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->schedule = PayrollSchedule::factory()->create();

    $this->hourly = Contact::create(['display_name' => 'Hourly Hank', 'is_employee' => true, 'is_active' => true]);
    $this->hourlyProfile = EmployeePayrollProfile::factory()->create([
        'contact_id' => $this->hourly->id,
        'province_of_employment' => 'AB',
        'pay_basis' => PayBasis::Hourly->value,
        'hourly_rate_cents' => 3000, // $30/h
        'payroll_schedule_id' => $this->schedule->id,
        'td1_federal_claim_cents' => 1612900,
        'td1_provincial_claim_cents' => 2232300,
        'is_active' => true,
    ]);

    $this->salaried = Contact::create(['display_name' => 'Salaried Sam', 'is_employee' => true, 'is_active' => true]);
    $this->salariedProfile = EmployeePayrollProfile::factory()->create([
        'contact_id' => $this->salaried->id,
        'province_of_employment' => 'AB',
        'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 6240000, // $62,400 → $30/h at 2080
        'payroll_schedule_id' => $this->schedule->id,
        'td1_federal_claim_cents' => 1612900,
        'td1_provincial_claim_cents' => 2232300,
        'is_active' => true,
    ]);
});

afterEach(fn () => app()->forgetInstance('current_company'));

/** A paid sick policy assigned to the given profile. */
function tepcSickPolicy(EmployeePayrollProfile $profile): TimeOffPolicy
{
    $policy = TimeOffPolicy::firstOrCreate(
        ['code' => 'sick'],
        ['name' => 'Sick', 'category' => 'sick', 'unit' => 'hours', 'accrual_method' => 'manual', 'rate_hours' => 0, 'rate_bp' => 0, 'paid' => true, 'is_active' => true],
    );

    EmployeeTimeOffPolicy::firstOrCreate(
        ['employee_payroll_profile_id' => $profile->id, 'time_off_policy_id' => $policy->id],
        ['is_active' => true],
    );

    return $policy;
}

/** A Draft run over 2025-06-01..14 for the given employees. */
function tepcRun(array $contactIds): PayRun
{
    $bank = Account::query()->where('code', '1000')->firstOrFail();

    return app(SavePayRun::class)->handle([
        'payroll_schedule_id' => test()->schedule->id,
        'period_start_date' => '2025-06-01',
        'period_end_date' => '2025-06-14',
        'pay_date' => '2025-06-20',
        'bank_account_id' => $bank->id,
        'lines' => array_map(fn (int $id) => ['contact_id' => $id], $contactIds),
    ]);
}

function tepcEntry(Contact $employee, string $date, float $hours, string $payCode = 'regular'): TimeEntry
{
    return TimeEntry::create([
        'contact_id' => $employee->id,
        'date_worked' => $date,
        'hours' => $hours,
        'pay_code' => $payCode,
        'status' => 'approved',
    ]);
}

// --- pay_code on the entry -------------------------------------------------

it('defaults a staff entry to the regular pay code and audits the code', function () {
    $entry = app(SaveTimeEntry::class)->handle([
        'contact_id' => $this->hourly->id,
        'date_worked' => '2025-06-02',
        'hours' => 8,
    ]);

    expect($entry->pay_code)->toBe('regular');

    $log = AccountingAuditLog::query()
        ->where('auditable_type', (new TimeEntry)->getMorphClass())
        ->where('auditable_id', $entry->id)
        ->where('action', AuditAction::TimeEntryCreated->value)
        ->firstOrFail();

    expect(data_get($log->payload, 'attributes.pay_code'))->toBe('regular');
});

it('rejects an unknown pay code on the staff write path', function () {
    app(SaveTimeEntry::class)->handle([
        'contact_id' => $this->hourly->id,
        'date_worked' => '2025-06-02',
        'hours' => 8,
        'pay_code' => 'no_such_code',
    ]);
})->throws(ValidationException::class);

it('accepts a time-off policy code from the portal but rejects unknown codes', function () {
    tepcSickPolicy($this->hourlyProfile);

    $entry = app(SaveOwnTimeEntry::class)->handle($this->hourly, [
        'date_worked' => '2025-06-03',
        'hours' => 7.5,
        'pay_code' => 'sick',
    ]);

    expect($entry->pay_code)->toBe('sick')
        ->and($entry->contact_id)->toBe($this->hourly->id);

    expect(fn () => app(SaveOwnTimeEntry::class)->handle($this->hourly, [
        'date_worked' => '2025-06-04',
        'hours' => 7.5,
        'pay_code' => 'no_such_code',
    ]))->toThrow(ValidationException::class);
});

// --- pull routing ----------------------------------------------------------

it('routes pulled entries by pay code: regular splits, explicit codes become their own earnings', function () {
    $this->company->update(['payroll_overtime_weekly_threshold_hours' => 44]);
    tepcSickPolicy($this->hourlyProfile);

    // One ISO week (Jun 2–6): 5 × 10h regular = 50h → 44 regular + 6 OT.
    foreach (['2025-06-02', '2025-06-03', '2025-06-04', '2025-06-05', '2025-06-06'] as $date) {
        tepcEntry($this->hourly, $date, 10);
    }
    // Explicitly coded hours in the same week never join the auto-split.
    tepcEntry($this->hourly, '2025-06-07', 3, 'overtime');
    tepcEntry($this->hourly, '2025-06-09', 8, 'sick');

    $run = tepcRun([$this->hourly->id]);
    $summary = app(PullTimeEntriesIntoPayRun::class)->handle($run->fresh());

    $line = $run->lines()->firstOrFail();
    expect((float) $line->hours_worked)->toBe(44.0)
        ->and($summary['by_code']['regular'])->toBe(44.0)
        ->and($summary['by_code']['overtime'])->toBe(9.0)
        ->and($summary['by_code']['sick'])->toBe(8.0)
        ->and($summary['entries'])->toBe(7);

    $ot = $line->manualEarnings()->where('code', 'overtime')->firstOrFail();
    expect((float) $ot->hours)->toBe(9.0) // 6 auto-split + 3 explicit
        ->and($ot->multiplier_bp)->toBe(15000)
        ->and($ot->source)->toBe(PayRunLineManualEarning::SOURCE_TIME_ENTRIES);

    $sick = $line->manualEarnings()->where('code', 'sick')->firstOrFail();
    expect((float) $sick->hours)->toBe(8.0)
        ->and($sick->multiplier_bp)->toBe(10000)
        ->and($sick->source)->toBe(PayRunLineManualEarning::SOURCE_TIME_ENTRIES);

    expect(TimeEntry::where('pay_run_id', $run->id)->count())->toBe(7);
});

it('prices pulled hourly sick at the rate and a stat holiday at 1.0×', function () {
    tepcSickPolicy($this->hourlyProfile);
    tepcEntry($this->hourly, '2025-06-02', 32);
    tepcEntry($this->hourly, '2025-06-09', 8, 'sick');
    tepcEntry($this->hourly, '2025-06-10', 8, 'stat_holiday');

    $run = tepcRun([$this->hourly->id]);
    app(PullTimeEntriesIntoPayRun::class)->handle($run->fresh());
    app(CalculatePayRun::class)->calculate($run->fresh());

    $line = $run->lines()->firstOrFail();

    // 32h regular + 8h sick + 8h stat, all at $30.
    expect((int) $line->earnings->firstWhere('code', 'sick')?->amount_cents)->toBe(24000)
        ->and((int) $line->earnings->firstWhere('code', 'stat_holiday')?->amount_cents)->toBe(24000)
        ->and((int) $line->gross_cents)->toBe(96000 + 24000 + 24000);
});

it('re-pull replaces generated rows but never operator-entered ones', function () {
    tepcSickPolicy($this->hourlyProfile);
    tepcEntry($this->hourly, '2025-06-02', 8, 'sick');

    $run = tepcRun([$this->hourly->id]);
    $line = $run->lines()->firstOrFail();

    // An operator-entered bonus (no source) must survive every re-pull.
    $line->manualEarnings()->create([
        'code' => 'bonus', 'name' => 'Bonus', 'calc_kind' => 'amount', 'amount_cents' => 50000, 'line_order' => 5,
    ]);

    app(PullTimeEntriesIntoPayRun::class)->handle($run->fresh());
    app(PullTimeEntriesIntoPayRun::class)->handle($run->fresh());

    expect($line->manualEarnings()->where('code', 'sick')->count())->toBe(1)
        ->and($line->manualEarnings()->where('code', 'bonus')->count())->toBe(1);
});

it('saving the run keeps pull-generated rows but replaces operator rows', function () {
    tepcSickPolicy($this->hourlyProfile);
    tepcEntry($this->hourly, '2025-06-02', 8, 'sick');

    $run = tepcRun([$this->hourly->id]);
    app(PullTimeEntriesIntoPayRun::class)->handle($run->fresh());

    // Re-save the run sending only an operator bonus row.
    $run = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => $this->schedule->id,
        'period_start_date' => '2025-06-01',
        'period_end_date' => '2025-06-14',
        'pay_date' => '2025-06-20',
        'bank_account_id' => Account::query()->where('code', '1000')->firstOrFail()->id,
        'lines' => [[
            'contact_id' => $this->hourly->id,
            'manual_earnings' => [['code' => 'bonus', 'name' => 'Bonus', 'calc_kind' => 'amount', 'amount_cents' => 10000]],
        ]],
    ], $run->fresh());

    $line = $run->lines()->firstOrFail();
    expect($line->manualEarnings()->where('code', 'sick')->where('source', PayRunLineManualEarning::SOURCE_TIME_ENTRIES)->count())->toBe(1)
        ->and($line->manualEarnings()->where('code', 'bonus')->count())->toBe(1);
});

// --- salaried employees ----------------------------------------------------

it('pulls time-off codes for salaried employees at $0 but leaves regular hours alone', function () {
    tepcSickPolicy($this->salariedProfile);
    $regular = tepcEntry($this->salaried, '2025-06-02', 8);
    $sick = tepcEntry($this->salaried, '2025-06-03', 8, 'sick');

    $run = tepcRun([$this->salaried->id]);
    $summary = app(PullTimeEntriesIntoPayRun::class)->handle($run->fresh());

    expect($summary['salaried_regular'])->toBe(1)
        ->and($summary['by_code'])->toBe(['sick' => 8.0])
        ->and($regular->fresh()->pay_run_id)->toBeNull()
        ->and($sick->fresh()->pay_run_id)->toBe($run->id);

    app(CalculatePayRun::class)->calculate($run->fresh());

    $line = $run->lines()->firstOrFail();
    $sickEarning = $line->earnings->firstWhere('code', 'sick');

    // Salary keeps paying through the sick day: $0 earning, hours carried for
    // the balance draw-down, full period salary unchanged.
    expect((int) $sickEarning?->amount_cents)->toBe(0)
        ->and((float) $sickEarning?->hours)->toBe(8.0)
        ->and((int) $line->earnings->firstWhere('code', 'regular')?->amount_cents)->toBeGreaterThan(0);
});

it('still pays an operator-entered paid leave row for a salaried employee', function () {
    tepcSickPolicy($this->salariedProfile);

    $run = tepcRun([$this->salaried->id]);
    $run->lines()->firstOrFail()->manualEarnings()->create([
        'code' => 'sick', 'name' => 'Sick payout', 'calc_kind' => 'hours', 'hours' => 8, 'multiplier_bp' => 10000, 'line_order' => 0,
    ]);

    app(CalculatePayRun::class)->calculate($run->fresh());

    // Explicit operator rows are payouts: 8h × ($62,400 / 2080) = $240.
    expect((int) $run->lines()->firstOrFail()->earnings->firstWhere('code', 'sick')?->amount_cents)->toBe(24000);
});
