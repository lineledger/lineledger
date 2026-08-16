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
use App\Models\PayrollRemittance;
use App\Models\PayrollSchedule;
use App\Models\User;
use App\Services\Payroll\CalculatePayRun;
use App\Services\Posting\PayrollRemittancePoster;
use App\Services\Posting\PayRunPoster;
use App\Services\Reporting\PayrollRemittanceCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->schedule = PayrollSchedule::factory()->create();
    $this->bank = Account::query()->where('code', '1000')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function remitEmployee(string $province): Contact
{
    $contact = Contact::create(['display_name' => 'Remy '.$province, 'is_employee' => true]);
    EmployeePayrollProfile::factory()->create([
        'contact_id' => $contact->id,
        'province_of_employment' => $province,
        'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 6000000,
        'payroll_schedule_id' => test()->schedule->id,
        'td1_federal_claim_cents' => 1612900,
        'td1_provincial_claim_cents' => 2232300,
    ]);

    return $contact;
}

function remitPostedRun(Contact $employee): void
{
    $run = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => test()->schedule->id,
        'period_start_date' => '2025-06-01',
        'period_end_date' => '2025-06-14',
        'pay_date' => '2025-06-20',
        'bank_account_id' => test()->bank->id,
        'lines' => [['contact_id' => $employee->id]],
    ]);
    app(CalculatePayRun::class)->calculate($run);
    app(PayRunPoster::class)->post($run->fresh());
}

/** Net movement (debit − credit) on an account across all of the company's posted JE lines. */
function acctNet(string $code): int
{
    $accountId = Account::query()->where('code', $code)->value('id');

    return (int) DB::table('journal_lines')
        ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
        ->where('journal_entries.company_id', test()->company->id)
        ->whereNull('journal_entries.voided_at')
        ->where('journal_lines.account_id', $accountId)
        ->selectRaw('COALESCE(SUM(debit_cents) - SUM(credit_cents), 0) AS net')
        ->value('net');
}

function recordCra(): PayrollRemittance
{
    return app(RecordPayrollRemittance::class)->handle([
        'agency' => 'cra',
        'period_start' => '2025-06-01',
        'period_end' => '2025-06-30',
        'due_date' => '2025-07-15',
        'bank_account_id' => test()->bank->id,
        'payment_date' => '2025-07-10',
        'reference' => 'WEB-PAY-001',
    ]);
}

it('records a CRA remittance, posting a balanced JE that clears the payables', function () {
    $employee = remitEmployee('AB');
    remitPostedRun($employee);

    $summary = app(PayrollRemittanceCalculator::class)->summary(
        $this->company, CarbonImmutable::parse('2025-06-01'), CarbonImmutable::parse('2025-06-30'),
    );

    // The pay run credited the payables; confirm they're outstanding before remitting.
    expect(acctNet('2400'))->toBe(-$summary['total_cpp_cents'])  // CR balance shows as negative net
        ->and(acctNet('2410'))->toBe(-$summary['total_ei_cents'])
        ->and(acctNet('2420'))->toBe(-$summary['tax_cents']);

    $remittance = recordCra();
    $je = $remittance->journalEntry;

    expect($remittance->status)->toBe(RemittanceStatus::Paid)
        ->and($remittance->journal_entry_id)->not->toBeNull()
        ->and($remittance->total_cents)->toBe($summary['remittance_due_cents'])
        // Balanced entry: DR payables / CR bank.
        ->and((int) $je->lines->sum('debit_cents'))->toBe((int) $je->lines->sum('credit_cents'))
        ->and((int) $je->lines->sum('credit_cents'))->toBe($summary['remittance_due_cents'])
        // The remittance clears each statutory payable back to zero.
        ->and(acctNet('2400'))->toBe(0)
        ->and(acctNet('2410'))->toBe(0)
        ->and(acctNet('2420'))->toBe(0);
});

it('rejects remitting the same agency-period twice', function () {
    remitPostedRun(remitEmployee('AB'));
    recordCra();

    expect(fn () => recordCra())->toThrow(RuntimeException::class);
});

it('reverses the remittance on void, re-opening the payables', function () {
    remitPostedRun(remitEmployee('AB'));
    $remittance = recordCra();

    $cppAfterRemit = acctNet('2400');
    expect($cppAfterRemit)->toBe(0);

    app(PayrollRemittancePoster::class)->void($remittance->fresh());

    // The reversing entry restores the outstanding payable (a CR balance again).
    expect($remittance->fresh()->status)->toBe(RemittanceStatus::Void)
        ->and(acctNet('2400'))->toBeLessThan(0);
});

it('records a Revenu Québec remittance against the QC payables', function () {
    remitPostedRun(remitEmployee('QC'));

    $remittance = app(RecordPayrollRemittance::class)->handle([
        'agency' => 'revenu_quebec',
        'period_start' => '2025-06-01',
        'period_end' => '2025-06-30',
        'due_date' => '2025-07-15',
        'bank_account_id' => $this->bank->id,
        'payment_date' => '2025-07-10',
    ]);

    $je = $remittance->journalEntry;

    expect($remittance->status)->toBe(RemittanceStatus::Paid)
        ->and((int) $je->lines->sum('debit_cents'))->toBe((int) $je->lines->sum('credit_cents'))
        // QPP (2422) + QPIP (2423) + Quebec tax (2421) payables are cleared; CRA CPP (2400) is untouched.
        ->and(acctNet('2422'))->toBe(0)
        ->and(acctNet('2423'))->toBe(0)
        ->and(acctNet('2421'))->toBe(0);
});
