<?php

use App\Actions\Payroll\IssuePayrollCheques;
use App\Actions\Payroll\SavePayRun;
use App\Enums\PayBasis;
use App\Enums\PayrollChequeStatus;
use App\Enums\PayRunStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollSchedule;
use App\Services\Payroll\CalculatePayRun;
use App\Services\Posting\PayrollChequePoster;
use App\Services\Posting\PayRunPoster;
use App\Services\Printing\ChequePdfRenderer;

beforeEach(function () {
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    app()->instance('current_company', $this->company);

    $this->schedule = PayrollSchedule::factory()->create();
    $this->bank = Account::query()->where('code', '1000')->firstOrFail();

    $this->employee = Contact::create(['display_name' => 'Casey Cash', 'is_employee' => true]);
    EmployeePayrollProfile::factory()->create([
        'contact_id' => $this->employee->id,
        'province_of_employment' => 'AB',
        'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 6000000,
        'payroll_schedule_id' => $this->schedule->id,
        'td1_federal_claim_cents' => 1612900,
        'td1_provincial_claim_cents' => 2232300,
    ]);

    $this->run = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => $this->schedule->id,
        'period_start_date' => '2025-06-01',
        'period_end_date' => '2025-06-14',
        'pay_date' => '2025-06-20',
        'bank_account_id' => $this->bank->id,
        'lines' => [['contact_id' => $this->employee->id]],
    ]);
    app(CalculatePayRun::class)->calculate($this->run);
    $this->run->refresh()->load('lines');
    $this->line = $this->run->lines->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function payrollAccount(string $code): Account
{
    return Account::query()->where('code', $code)->firstOrFail();
}

it('posts a balanced pay-run journal entry hitting the payroll accounts', function () {
    $line = $this->line;
    $entry = app(PayRunPoster::class)->post($this->run);
    $this->run->refresh();

    expect($this->run->status)->toBe(PayRunStatus::Posted)
        ->and($entry->isBalanced())->toBeTrue();

    // Expense: wages debit = gross.
    expect(payrollAccount('6200')->fresh()->balance_cents)->toBe($line->gross_cents);

    // Liabilities credited (employee + employer where applicable).
    expect(payrollAccount('2400')->fresh()->balance_cents)
        ->toBe($line->cppEmployeeCents() * 2 + $line->cpp2EmployeeCents() * 2);
    expect(payrollAccount('2410')->fresh()->balance_cents)
        ->toBe($line->eiEmployeeCents() + $line->eiEmployerCents());
    expect(payrollAccount('2420')->fresh()->balance_cents)->toBe($line->incomeTaxCents());

    // Vacation accrued: expense debit and payable credit are equal.
    expect(payrollAccount('6230')->fresh()->balance_cents)->toBe($line->vacation_accrued_cents);
    expect(payrollAccount('2430')->fresh()->balance_cents)->toBe($line->vacation_accrued_cents);

    // Net pay parked in the clearing account.
    expect(payrollAccount('2440')->fresh()->balance_cents)->toBe($line->net_cents);
});

it('writes cheques that drain net pay clearing and credit the bank, marking the run Paid', function () {
    app(PayRunPoster::class)->post($this->run);
    $bankBefore = $this->bank->fresh()->balance_cents;

    $cheques = app(IssuePayrollCheques::class)->handle($this->run->fresh(), 2001);
    $this->run->refresh();

    expect($cheques)->toHaveCount(1)
        ->and($cheques->first()->status)->toBe(PayrollChequeStatus::Posted)
        ->and($cheques->first()->amount_cents)->toBe($this->line->net_cents)
        ->and($this->run->status)->toBe(PayRunStatus::Paid);

    // Clearing fully drained; bank reduced by net pay.
    expect(payrollAccount('2440')->fresh()->balance_cents)->toBe(0);
    expect($this->bank->fresh()->balance_cents)->toBe($bankBefore - $this->line->net_cents);
});

it('voids a cheque, reverting the run to Posted and restoring the clearing balance', function () {
    app(PayRunPoster::class)->post($this->run);
    $cheque = app(IssuePayrollCheques::class)->handle($this->run->fresh(), 3001)->first();

    app(PayrollChequePoster::class)->void($cheque->fresh());
    $this->run->refresh();

    expect($cheque->fresh()->status)->toBe(PayrollChequeStatus::Void)
        ->and($this->run->status)->toBe(PayRunStatus::Posted)
        ->and(payrollAccount('2440')->fresh()->balance_cents)->toBe($this->line->net_cents);
});

it('renders a printable pay stub for a payroll cheque', function () {
    app(PayRunPoster::class)->post($this->run);
    $cheque = app(IssuePayrollCheques::class)->handle($this->run->fresh(), 7001)->first();

    $data = app(ChequePdfRenderer::class)->dataFor($cheque->fresh());

    expect($data['payee'])->toBe('Casey Cash')
        ->and($data['total_numeric'])->toBe(number_format($this->line->net_cents / 100, 2, '.', ','))
        ->and($data['lines'])->not->toBeEmpty();

    // The controller returns a PDF response.
    $pdf = app(ChequePdfRenderer::class)->render($cheque->fresh());
    expect($pdf)->toStartWith('%PDF');
});

it('posts ONE balanced JE for a mixed QC + ON run, moving both CRA and Revenu Québec payables', function () {
    $this->company->update(['qhsf_rate_bp' => 192, 'cnesst_rate_bp' => 200]);

    // Add a Quebec employee alongside the Alberta one from beforeEach.
    $quebecer = Contact::create(['display_name' => 'Quincy Québec', 'is_employee' => true]);
    EmployeePayrollProfile::factory()->create([
        'contact_id' => $quebecer->id,
        'province_of_employment' => 'QC',
        'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 6000000,
        'payroll_schedule_id' => $this->schedule->id,
        'td1_federal_claim_cents' => 1612900,
        'td1_provincial_claim_cents' => 1857100,
    ]);

    $run = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => $this->schedule->id,
        'period_start_date' => '2025-07-01',
        'period_end_date' => '2025-07-14',
        'pay_date' => '2025-07-18',
        'bank_account_id' => $this->bank->id,
        'lines' => [['contact_id' => $this->employee->id], ['contact_id' => $quebecer->id]],
    ]);
    app(CalculatePayRun::class)->calculate($run);
    $run->refresh()->load('lines');

    $entry = app(PayRunPoster::class)->post($run);
    expect($entry->isBalanced())->toBeTrue();

    $ab = $run->lines->firstWhere('province_of_employment', 'AB');
    $qc = $run->lines->firstWhere('province_of_employment', 'QC');

    // CRA payables: CPP from the ON/AB line only; EI from both (Quebec EI is CRA-remitted).
    expect(payrollAccount('2400')->fresh()->balance_cents)
        ->toBe($ab->cppEmployeeCents() * 2 + $ab->cpp2EmployeeCents() * 2);
    expect(payrollAccount('2410')->fresh()->balance_cents)
        ->toBe($ab->eiEmployeeCents() + $ab->eiEmployerCents() + $qc->eiEmployeeCents() + $qc->eiEmployerCents());
    // CRA income tax = both lines' federal + provincial + additional (QC provincial is 0).
    expect(payrollAccount('2420')->fresh()->balance_cents)->toBe(
        $ab->federalTaxCents() + $ab->provincialTaxCents() + $ab->additionalTaxCents()
        + $qc->federalTaxCents() + $qc->provincialTaxCents() + $qc->additionalTaxCents()
    );

    // Revenu Québec payables: from the QC line only.
    expect(payrollAccount('2422')->fresh()->balance_cents) // QPP payable
        ->toBe($qc->qppEmployeeCents() * 2 + $qc->qpp2EmployeeCents() * 2);
    expect(payrollAccount('2423')->fresh()->balance_cents) // QPIP payable
        ->toBe($qc->qpipEmployeeCents() + $qc->qpipEmployerCents());
    expect(payrollAccount('2421')->fresh()->balance_cents)->toBe($qc->quebecTaxCents()); // Quebec income tax
    expect(payrollAccount('2424')->fresh()->balance_cents)->toBe($qc->qhsfEmployerCents()); // QHSF
    expect(payrollAccount('2425')->fresh()->balance_cents)->toBe($qc->cnesstEmployerCents()); // CNESST

    // The QC line contributes nothing to CPP payable.
    expect($qc->cppEmployeeCents())->toBe(0)->and($qc->qppEmployeeCents())->toBeGreaterThan(0);
});

it('blocks voiding a pay run that still has posted cheques', function () {
    app(PayRunPoster::class)->post($this->run);
    app(IssuePayrollCheques::class)->handle($this->run->fresh(), 4001);

    app(PayRunPoster::class)->void($this->run->fresh());
})->throws(RuntimeException::class);
