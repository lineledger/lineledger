<?php

use App\Actions\Accounting\SaveAccount;
use App\Enums\AccountSubtype;
use App\Enums\ChequeStatus;
use App\Exceptions\Posting\ReconciliationLockedException;
use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\Cheque;
use App\Models\Company;
use App\Services\Posting\ChequePoster;
use App\Services\Reconciliation\BankReconciliationService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
    $this->poster = app(ChequePoster::class);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function lockTestCheque(Account $bank, Account $expense, string $no, string $date): Cheque
{
    $cheque = Cheque::create([
        'bank_account_id' => $bank->id,
        'cheque_no' => $no,
        'cheque_date' => $date,
        'payee_name' => 'Vendor',
    ]);

    $cheque->lines()->create([
        'account_id' => $expense->id,
        'amount_cents' => 5000,
        'tax_cents' => 0,
        'line_order' => 0,
    ]);

    return $cheque;
}

function completeRecThrough(Account $bank, string $statementDate): BankReconciliation
{
    return BankReconciliation::factory()->completed()->create([
        'company_id' => $bank->company_id,
        'account_id' => $bank->id,
        'statement_date' => $statementDate,
    ]);
}

it('blocks posting a cheque dated within a reconciled period', function () {
    completeRecThrough($this->bank, '2026-04-30');

    $cheque = lockTestCheque($this->bank, $this->expense, '1001', '2026-04-15');

    expect(fn () => $this->poster->post($cheque))
        ->toThrow(ReconciliationLockedException::class);
});

it('blocks voiding a cheque whose date is within a reconciled period', function () {
    $cheque = lockTestCheque($this->bank, $this->expense, '1002', '2026-04-15');
    $this->poster->post($cheque);

    completeRecThrough($this->bank, '2026-04-30');

    expect(fn () => $this->poster->void($cheque->fresh()))
        ->toThrow(ReconciliationLockedException::class);
});

it('allows voiding once the reconciliation is undone', function () {
    $cheque = lockTestCheque($this->bank, $this->expense, '1003', '2026-04-15');
    $this->poster->post($cheque);

    $rec = completeRecThrough($this->bank, '2026-04-30');

    expect(fn () => $this->poster->void($cheque->fresh()))
        ->toThrow(ReconciliationLockedException::class);

    app(BankReconciliationService::class)->undo($rec);

    $this->poster->void($cheque->fresh());

    expect($cheque->fresh()->status)->toBe(ChequeStatus::Void);
});

it('allows posting a cheque dated after the statement date', function () {
    completeRecThrough($this->bank, '2026-04-30');

    $cheque = lockTestCheque($this->bank, $this->expense, '1004', '2026-05-15');

    $this->poster->post($cheque);

    expect($cheque->fresh()->status)->toBe(ChequeStatus::Posted);
});

it('lets the reconciliation service undo its own service-charge entry on the statement date', function () {
    $charges = Account::query()->where('code', '6010')->first(); // Bank Charges
    $service = app(BankReconciliationService::class);

    $rec = $service->begin(
        $this->bank,
        Carbon::parse('2026-04-30'),
        endingBalanceCents: -1500,
        serviceCharge: ['cents' => 1500, 'date' => Carbon::parse('2026-04-30'), 'account_id' => $charges->id],
    );

    $completed = $service->complete($rec);

    // The service-charge entry is dated on the statement date of a completed rec;
    // undo must bypass the lock to reverse it.
    $service->undo($completed);

    expect(BankReconciliation::find($completed->id))->toBeNull();
});

it('leaves a non-reconciled account unaffected', function () {
    completeRecThrough($this->bank, '2026-04-30');

    $otherBank = app(SaveAccount::class)->handle([
        'code' => '1011', 'name' => 'Savings', 'subtype' => AccountSubtype::Bank->value,
    ]);

    $cheque = lockTestCheque($otherBank, $this->expense, '2001', '2026-04-15');

    $this->poster->post($cheque);

    expect($cheque->fresh()->status)->toBe(ChequeStatus::Posted);
});
