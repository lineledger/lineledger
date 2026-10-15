<?php

use App\Actions\Accounting\SaveAccount;
use App\Enums\AccountSubtype;
use App\Enums\ChequeStatus;
use App\Exceptions\Posting\ReconciliationLockedException;
use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\JournalEntry;
use App\Services\Posting\ChequePoster;
use App\Services\Posting\JournalPoster;
use App\Services\Posting\ReceiptPoster;
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

it('allows posting a cheque dated within a reconciled period, leaving it for the next reconciliation', function () {
    completeRecThrough($this->bank, '2026-04-30');

    $cheque = lockTestCheque($this->bank, $this->expense, '1001', '2026-04-15');

    $this->poster->post($cheque);

    $bankLine = $cheque->fresh()->journalEntry->lines->firstWhere('account_id', $this->bank->id);

    expect($cheque->fresh()->status)->toBe(ChequeStatus::Posted)
        ->and($bankLine->bank_reconciliation_id)->toBeNull()
        ->and($bankLine->cleared_at)->toBeNull();
});

it('keeps a completed reconciliation balanced when a back-dated entry posts into its period', function () {
    $service = app(BankReconciliationService::class);
    $poster = app(JournalPoster::class);
    $equity = Account::query()->where('subtype', AccountSubtype::Equity->value)->orderBy('code')->first();

    $opening = JournalEntry::create(['entry_no' => 'JE-OPEN', 'entry_date' => '2026-07-10']);
    $opening->lines()->createMany([
        ['account_id' => $this->bank->id, 'debit_cents' => 100000, 'credit_cents' => 0, 'line_order' => 0],
        ['account_id' => $equity->id, 'debit_cents' => 0, 'credit_cents' => 100000, 'line_order' => 1],
    ]);
    $poster->post($opening);

    $rec = $service->begin($this->bank, Carbon::parse('2026-09-30'), endingBalanceCents: 100000);
    $rec = $service->toggleMark($rec, $opening->lines()->where('account_id', $this->bank->id)->value('id'));
    $rec = $service->complete($rec);

    // The accountant's year-end adjusting entry, dated inside the reconciled period.
    $adjustment = JournalEntry::create(['entry_no' => 'AJE-1', 'entry_date' => '2026-07-31', 'memo' => 'Year-end adjustment']);
    $adjustment->lines()->createMany([
        ['account_id' => $this->expense->id, 'debit_cents' => 2500, 'credit_cents' => 0, 'line_order' => 0],
        ['account_id' => $this->bank->id, 'debit_cents' => 0, 'credit_cents' => 2500, 'line_order' => 1],
    ]);
    $poster->post($adjustment);

    $adjustmentBankLine = $adjustment->lines()->where('account_id', $this->bank->id)->first();

    expect($service->differenceCents($rec->fresh()))->toBe(0)
        ->and($adjustmentBankLine->bank_reconciliation_id)->toBeNull();

    // It is waiting on the next reconciliation.
    $next = $service->begin($this->bank, Carbon::parse('2026-10-31'), endingBalanceCents: 97500);
    $next = $service->toggleMark($next, $adjustmentBankLine->id);

    expect($service->differenceCents($next))->toBe(0);
});

it('blocks voiding a cheque whose bank line a completed reconciliation ticked', function () {
    $cheque = lockTestCheque($this->bank, $this->expense, '1002', '2026-04-15');
    $this->poster->post($cheque);

    claimLinesForReconciliation(completeRecThrough($this->bank, '2026-04-30'), $cheque->fresh()->journalEntry);

    expect(fn () => $this->poster->void($cheque->fresh()))
        ->toThrow(ReconciliationLockedException::class, 'Undo that reconciliation');
});

it('allows voiding an uncleared cheque dated within a reconciled period', function () {
    $cheque = lockTestCheque($this->bank, $this->expense, '1005', '2026-04-15');
    $this->poster->post($cheque);

    completeRecThrough($this->bank, '2026-04-30');

    $this->poster->void($cheque->fresh());

    expect($cheque->fresh()->status)->toBe(ChequeStatus::Void);
});

it('allows voiding once the reconciliation is undone', function () {
    $cheque = lockTestCheque($this->bank, $this->expense, '1003', '2026-04-15');
    $this->poster->post($cheque);

    $rec = completeRecThrough($this->bank, '2026-04-30');
    claimLinesForReconciliation($rec, $cheque->fresh()->journalEntry);

    expect(fn () => $this->poster->void($cheque->fresh()))
        ->toThrow(ReconciliationLockedException::class);

    app(BankReconciliationService::class)->undo($rec);

    $this->poster->void($cheque->fresh());

    expect($cheque->fresh()->status)->toBe(ChequeStatus::Void);
});

it('blocks editing a cheque whose bank line a completed reconciliation ticked', function () {
    $cheque = lockTestCheque($this->bank, $this->expense, '1006', '2026-04-15');
    $this->poster->post($cheque);

    claimLinesForReconciliation(completeRecThrough($this->bank, '2026-04-30'), $cheque->fresh()->journalEntry);

    $cheque = $cheque->fresh();
    $cheque->update(['payee_name' => 'Someone else']);

    expect(fn () => $this->poster->repost($cheque->fresh()))
        ->toThrow(ReconciliationLockedException::class);
});

it('allows editing an uncleared cheque dated within a reconciled period', function () {
    $cheque = lockTestCheque($this->bank, $this->expense, '1007', '2026-04-15');
    $this->poster->post($cheque);

    completeRecThrough($this->bank, '2026-04-30');

    $cheque = $cheque->fresh();
    $cheque->update(['payee_name' => 'Someone else']);
    $this->poster->repost($cheque->fresh());

    expect($cheque->fresh()->journalEntry->memo)->toContain('Someone else');
});

it('blocks editing a customer payment deposited to a reconciled bank line', function () {
    $customer = Contact::factory()->create();

    $receipt = CustomerReceipt::create([
        'contact_id' => $customer->id,
        'receipt_no' => 'REC-LOCK-1',
        'receipt_date' => '2026-04-10',
        'deposit_to_account_id' => $this->bank->id,
        'amount_cents' => 7500,
    ]);

    $receiptPoster = app(ReceiptPoster::class);
    $receiptPoster->post($receipt->fresh());

    claimLinesForReconciliation(completeRecThrough($this->bank, '2026-04-30'), $receipt->fresh()->journalEntry);

    $receipt = $receipt->fresh();
    $receipt->update(['memo' => 'changed']);

    expect(fn () => $receiptPoster->repost($receipt->fresh()))
        ->toThrow(ReconciliationLockedException::class);
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

    // The service-charge line is ticked by this still-completed rec; undo must
    // bypass the lock to reverse it.
    $service->undo($completed);

    expect(BankReconciliation::find($completed->id))->toBeNull();
});

it('leaves a non-reconciled account unaffected', function () {
    $rec = completeRecThrough($this->bank, '2026-04-30');

    $otherBank = app(SaveAccount::class)->handle([
        'code' => '1011', 'name' => 'Savings', 'subtype' => AccountSubtype::Bank->value,
    ]);

    $cheque = lockTestCheque($otherBank, $this->expense, '2001', '2026-04-15');
    $this->poster->post($cheque);

    // Claiming against a different account's rec stamps nothing on this one.
    claimLinesForReconciliation($rec, $cheque->fresh()->journalEntry);

    $this->poster->void($cheque->fresh());

    expect($cheque->fresh()->status)->toBe(ChequeStatus::Void);
});
