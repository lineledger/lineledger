<?php

use App\Actions\Payroll\IssuePayrollCheques;
use App\Actions\Payroll\RecordPayrollRemittance;
use App\Actions\Payroll\SavePayRun;
use App\Enums\CompanyRole;
use App\Enums\PayBasis;
use App\Enums\PayRunStatus;
use App\Enums\PredefinedRole;
use App\Exceptions\Deletion\DeletionBlockedException;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\DeletedTransaction;
use App\Models\EmployeeAccrualBalance;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollCheque;
use App\Models\PayrollRemittance;
use App\Models\PayrollSchedule;
use App\Models\PayRun;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Deletion\TransactionDeleter;
use App\Services\Payroll\CalculatePayRun;
use App\Services\Posting\PayrollChequePoster;
use App\Services\Posting\PayRunPoster;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    $this->company->members()->attach($this->owner, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->owner);
    app()->instance('current_company', $this->company);

    $this->schedule = PayrollSchedule::factory()->create();
    $this->bank = Account::query()->where('code', '1000')->firstOrFail();
    $this->employee = payrollDeleteEmployee('Reggie Register');
    $this->deleter = app(TransactionDeleter::class);
});

afterEach(fn () => app()->forgetInstance('current_company'));

function payrollDeleteEmployee(string $name): Contact
{
    $employee = Contact::create(['display_name' => $name, 'is_employee' => true]);
    EmployeePayrollProfile::factory()->create([
        'contact_id' => $employee->id,
        'province_of_employment' => 'AB',
        'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 6000000,
        'payroll_schedule_id' => test()->schedule->id,
        'td1_federal_claim_cents' => 1612900,
        'td1_provincial_claim_cents' => 2232300,
        'vacation_policy' => 'accrue',
    ]);

    return $employee;
}

function payrollDeleteRun(string $start, string $end, string $payDate, bool $post = true): PayRun
{
    $run = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => test()->schedule->id,
        'period_start_date' => $start,
        'period_end_date' => $end,
        'pay_date' => $payDate,
        'bank_account_id' => test()->bank->id,
        'lines' => [['contact_id' => test()->employee->id]],
    ]);
    app(CalculatePayRun::class)->calculate($run);

    if ($post) {
        app(PayRunPoster::class)->post($run->fresh());
    }

    return $run->fresh();
}

function payrollDeleteBalances(): array
{
    return EmployeeAccrualBalance::query()->orderBy('id')->get(['code', 'balance_cents', 'accrued_ytd_cents'])->map(fn ($b) => [$b->code, (int) $b->balance_cents, (int) $b->accrued_ytd_cents])->all();
}

function payrollDeleteMessages(callable $call): string
{
    try {
        $call();
    } catch (DeletionBlockedException $e) {
        return implode(' | ', array_map(fn ($b) => $b->message, $e->blockers));
    }

    return '';
}

it('deletes a posted pay run, reversing its accruals and releasing its time entries', function () {
    $before = deletionLedgerState($this->company);
    $balances = payrollDeleteBalances();
    $run = payrollDeleteRun('2025-06-01', '2025-06-14', '2025-06-20');
    $entry = TimeEntry::factory()->create(['company_id' => $this->company->id, 'contact_id' => $this->employee->id, 'pay_run_id' => $run->id]);

    $record = $this->deleter->delete($run);

    expect(deletionLedgerState($this->company))->toBe($before)
        ->and(collect(payrollDeleteBalances())->map(fn ($b) => [$b[1], $b[2]])->unique()->values()->all())->toBe(collect($balances)->isEmpty() ? [[0, 0]] : collect($balances)->map(fn ($b) => [$b[1], $b[2]])->unique()->values()->all())
        ->and(PayRun::withTrashed()->whereKey($run->id)->exists())->toBeFalse()
        ->and(DB::table('pay_run_lines')->where('pay_run_id', $run->id)->exists())->toBeFalse()
        ->and($entry->fresh()->pay_run_id)->toBeNull()
        ->and($record->snapshot['document']['employees'][0]['name'])->toBe('Reggie Register')
        ->and(Artisan::call('audit:verify', ['company' => $this->company->id, '--full' => true, '--no-alert' => true]))->toBe(0);
});

it('deletes a calculated pay run that never posted', function () {
    $run = payrollDeleteRun('2025-06-01', '2025-06-14', '2025-06-20', post: false);

    $record = $this->deleter->delete($run);

    expect($record->state_at_deletion->value)->toBe('draft')
        ->and(PayRun::withTrashed()->whereKey($run->id)->exists())->toBeFalse();
});

it('refuses while a payroll cheque is posted, and deleting the cheque reopens the run', function () {
    $before = deletionLedgerState($this->company);
    $run = payrollDeleteRun('2025-06-01', '2025-06-14', '2025-06-20');
    $cheque = app(IssuePayrollCheques::class)->handle($run, 5001)->first();

    expect($run->fresh()->status)->toBe(PayRunStatus::Paid)
        ->and(payrollDeleteMessages(fn () => $this->deleter->delete($run->fresh())))->toContain('Payroll cheque 5001');

    $this->deleter->delete($cheque);

    expect($run->fresh()->status)->toBe(PayRunStatus::Posted)
        ->and(DeletedTransaction::query()->where('document_table', 'payroll_cheques')->value('document_number'))->toBe('5001');

    $this->deleter->delete($run->fresh());

    expect(deletionLedgerState($this->company))->toBe($before);
});

it('takes a voided payroll cheque, and its reversal, with the run', function () {
    $before = deletionLedgerState($this->company);
    $run = payrollDeleteRun('2025-06-01', '2025-06-14', '2025-06-20');
    $cheque = app(IssuePayrollCheques::class)->handle($run, 7001)->first();
    app(PayrollChequePoster::class)->void($cheque->fresh());

    $record = $this->deleter->delete($run->fresh());

    expect(deletionLedgerState($this->company))->toBe($before)
        ->and(PayrollCheque::withTrashed()->whereKey($cheque->id)->exists())->toBeFalse()
        ->and($record->snapshot['journal_entries'])->toHaveCount(3);
});

it('refuses while a later posted run in the year pays the same employee: newest first', function () {
    $first = payrollDeleteRun('2025-06-01', '2025-06-14', '2025-06-20');
    $second = payrollDeleteRun('2025-06-15', '2025-06-28', '2025-07-04');

    expect(payrollDeleteMessages(fn () => $this->deleter->delete($first->fresh())))->toContain($second->run_no);

    $this->deleter->delete($second->fresh());
    $this->deleter->delete($first->fresh());

    expect(PayRun::withTrashed()->count())->toBe(0);
});

it('refuses while a remittance covers the pay date, until the remittance is deleted', function () {
    $run = payrollDeleteRun('2025-06-01', '2025-06-14', '2025-06-20');
    $remittance = app(RecordPayrollRemittance::class)->handle([
        'agency' => 'cra', 'period_start' => '2025-06-01', 'period_end' => '2025-06-30', 'due_date' => '2025-07-15',
        'bank_account_id' => $this->bank->id, 'payment_date' => '2025-07-10', 'reference' => 'WEB-PAY-001',
    ]);

    expect(payrollDeleteMessages(fn () => $this->deleter->delete($run->fresh())))->toContain('remittance');

    $this->deleter->delete($remittance->fresh());
    $this->deleter->delete($run->fresh());

    expect(PayrollRemittance::query()->count())->toBe(0)
        ->and(PayRun::withTrashed()->count())->toBe(0);
});

it('refuses once the year\'s T4 slips are finalized', function () {
    $run = payrollDeleteRun('2025-06-01', '2025-06-14', '2025-06-20');
    DB::table('payroll_slip_filings')->insert([
        'company_id' => $this->company->id, 'slip_type' => 't4', 'year' => 2025, 'finalized_at' => now(), 'summary' => '{}',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(payrollDeleteMessages(fn () => $this->deleter->delete($run->fresh())))->toContain('T4');
});

it('deletes from the pay run and remittance pages', function () {
    $run = payrollDeleteRun('2025-06-01', '2025-06-14', '2025-06-20');
    $cheque = app(IssuePayrollCheques::class)->handle($run, 6001)->first();

    Livewire::actingAs($this->owner)
        ->test('pages::payroll.pay-runs.show', ['company' => $this->company, 'payRun' => $run->fresh()])
        ->assertSeeHtml('data-test="delete-payroll-cheque-button"')
        ->call('prepareDeleteCheque', $cheque->id)
        ->assertSet('deletingChequeId', $cheque->id)
        ->call('confirmDelete')
        ->assertRedirect(route('pay-runs.show', ['company' => $this->company->slug, 'payRun' => $run->id]));

    Livewire::actingAs($this->owner)
        ->test('pages::payroll.pay-runs.show', ['company' => $this->company, 'payRun' => $run->fresh()])
        ->call('prepareDeleteRun')
        ->call('confirmDelete')
        ->assertRedirect(route('pay-runs.index', ['company' => $this->company->slug]));

    expect(PayRun::withTrashed()->whereKey($run->id)->exists())->toBeFalse();

    payrollDeleteRun('2025-08-01', '2025-08-14', '2025-08-20');
    $remittance = app(RecordPayrollRemittance::class)->handle([
        'agency' => 'cra', 'period_start' => '2025-08-01', 'period_end' => '2025-08-31', 'due_date' => '2025-09-15',
        'bank_account_id' => $this->bank->id, 'payment_date' => '2025-09-10',
    ]);

    Livewire::actingAs($this->owner)
        ->test('pages::payroll.reports.remittances', ['company' => $this->company])
        ->call('prepareDeleteRemittance', $remittance->id)
        ->call('confirmDelete');

    expect(PayrollRemittance::query()->whereKey($remittance->id)->exists())->toBeFalse();
});

it('keeps a deleted pay run\'s record from members who can\'t open pay runs', function () {
    $run = payrollDeleteRun('2025-06-01', '2025-06-14', '2025-06-20', post: false);
    $record = $this->deleter->delete($run);
    $bookkeeper = predefinedRoleMember($this->company, PredefinedRole::Bookkeeper);

    expect($bookkeeper->permissions($this->company)->seesPayrollDetail())->toBeFalse();

    $this->actingAs($bookkeeper)
        ->get(route('deleted-transactions.show', ['company' => $this->company->slug, 'deletedTransaction' => $record->id]))
        ->assertNotFound();

    $this->actingAs($this->owner)
        ->get(route('deleted-transactions.show', ['company' => $this->company->slug, 'deletedTransaction' => $record->id]))
        ->assertOk()
        ->assertSee($run->run_no);
});
