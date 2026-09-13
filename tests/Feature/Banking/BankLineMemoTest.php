<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Deposit;
use App\Models\Expense;
use App\Models\JournalLine;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Audit\PostedMutationGate;
use App\Services\Banking\BankLineMemoBackfiller;
use App\Services\Posting\ChequePoster;
use App\Services\Posting\DepositPoster;
use App\Services\Posting\ExpensePoster;
use App\Services\Posting\TransferPoster;
use App\Support\Banking\BankLineMemo;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();
    $this->expenseAccount = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function bankLineMemoDeposit(?string $memo, string $no = 'DEP-M-1'): Deposit
{
    $deposit = Deposit::create([
        'bank_account_id' => test()->bank->id,
        'deposit_no' => $no,
        'deposit_date' => now()->toDateString(),
        'memo' => $memo,
    ]);

    $deposit->lines()->create([
        'account_id' => test()->income->id,
        'description' => 'Interac',
        'amount_cents' => 161815,
        'line_order' => 0,
    ]);

    app(DepositPoster::class)->post($deposit->fresh('lines'));

    return $deposit->fresh();
}

function bankLineMemoOnBankLeg(Deposit|Cheque|Expense|Transfer $document): ?string
{
    return JournalLine::query()
        ->where('journal_entry_id', $document->journal_entry_id)
        ->where('account_id', test()->bank->id)
        ->value('memo');
}

it('puts the deposit memo on the bank line so the register identifies the row', function () {
    $deposit = bankLineMemoDeposit('August 2026 Interac Deposits');

    expect(bankLineMemoOnBankLeg($deposit))->toBe('Deposit: August 2026 Interac Deposits');
});

it('leaves the bare label when the deposit has no memo', function () {
    expect(bankLineMemoOnBankLeg(bankLineMemoDeposit(null)))->toBe('Deposit');
    expect(bankLineMemoOnBankLeg(bankLineMemoDeposit('   ', 'DEP-M-2')))->toBe('Deposit');
});

it('keeps the memo in step when a posted deposit is edited', function () {
    $deposit = bankLineMemoDeposit('First wording');

    $deposit->update(['memo' => 'Second wording']);
    app(DepositPoster::class)->repost($deposit->fresh('lines'));

    expect(bankLineMemoOnBankLeg($deposit->fresh()))->toBe('Deposit: Second wording');
});

it('carries the document memo onto every other subledger that hits a bank account', function () {
    $cheque = Cheque::create([
        'bank_account_id' => $this->bank->id,
        'cheque_no' => '2001',
        'cheque_date' => now()->toDateString(),
        'payee_name' => 'Hydro',
        'memo' => 'September hydro',
    ]);
    $cheque->lines()->create([
        'account_id' => $this->expenseAccount->id,
        'description' => 'Hydro',
        'amount_cents' => 5000,
        'line_order' => 0,
    ]);
    app(ChequePoster::class)->post($cheque);

    expect(bankLineMemoOnBankLeg($cheque->fresh()))->toBe('Cheque 2001: September hydro');

    $expense = Expense::create([
        'payment_account_id' => $this->bank->id,
        'expense_date' => now()->toDateString(),
        'payee_name' => 'Staples',
        'reference' => 'DEBIT-77',
        'memo' => 'Office chairs',
    ]);
    $expense->lines()->create([
        'account_id' => $this->expenseAccount->id,
        'description' => 'Chairs',
        'amount_cents' => 9900,
        'line_order' => 0,
    ]);
    app(ExpensePoster::class)->post($expense);

    expect(bankLineMemoOnBankLeg($expense->fresh()))->toBe('Expense DEBIT-77: Office chairs');

    $other = Account::query()
        ->where('subtype', AccountSubtype::Bank->value)
        ->where('id', '!=', $this->bank->id)
        ->orderBy('code')
        ->first() ?? Account::create([
            'code' => '1099',
            'name' => 'Second Chequing',
            'type' => $this->bank->type,
            'subtype' => AccountSubtype::Bank,
        ]);

    $transfer = Transfer::create([
        'from_account_id' => $this->bank->id,
        'to_account_id' => $other->id,
        'transfer_no' => 'TR-M-1',
        'transfer_date' => now()->toDateString(),
        'from_amount_cents' => 2500,
        'to_amount_cents' => 2500,
        'memo' => 'Sweep to savings',
    ]);
    app(TransferPoster::class)->post($transfer);

    // Both sides of a transfer are register rows, so both carry the memo.
    $memos = JournalLine::query()
        ->where('journal_entry_id', $transfer->fresh()->journal_entry_id)
        ->whereIn('account_id', [$this->bank->id, $other->id])
        ->pluck('memo')
        ->unique()
        ->values();

    expect($memos->all())->toBe(['Transfer TR-M-1: Sweep to savings']);
});

it('flattens and clips a memo so a register row stays one line', function () {
    expect(BankLineMemo::compose('Deposit', "Two\nlines   here"))->toBe('Deposit: Two lines here');

    $long = str_repeat('a', BankLineMemo::MAX_DETAIL + 50);

    expect(BankLineMemo::compose('Deposit', $long))
        ->toBe('Deposit: '.str_repeat('a', BankLineMemo::MAX_DETAIL).'…');
});

it('shows the composed memo in the bank register', function () {
    $user = User::factory()->create();
    $this->company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    bankLineMemoDeposit('August 2026 Interac Deposits');

    Livewire::actingAs($user)
        ->test('pages::banking.register', ['company' => $this->company])
        ->set('account_id', $this->bank->id)
        ->assertSee('Deposit: August 2026 Interac Deposits');
});

it('backfills bank line memos posted before the memo was carried across', function () {
    $deposit = bankLineMemoDeposit('August 2026 Interac Deposits');

    // Rewind to what the old poster wrote.
    PostedMutationGate::within(fn () => JournalLine::query()
        ->where('journal_entry_id', $deposit->journal_entry_id)
        ->where('account_id', $this->bank->id)
        ->update(['memo' => 'Deposit']));

    $result = app(BankLineMemoBackfiller::class)->backfill($this->company->id);

    expect($result['updated'])->toBe(1)
        ->and(bankLineMemoOnBankLeg($deposit))->toBe('Deposit: August 2026 Interac Deposits');

    // Idempotent: a second pass has nothing left to match.
    expect(app(BankLineMemoBackfiller::class)->backfill($this->company->id)['updated'])->toBe(0);
});

it('backfill leaves the other legs and hand-edited memos alone', function () {
    $deposit = bankLineMemoDeposit('August 2026 Interac Deposits');

    // The income leg happens to be worded "Deposit" too, and an operator has
    // already reworded the bank leg by hand.
    PostedMutationGate::within(function () use ($deposit) {
        JournalLine::query()
            ->where('journal_entry_id', $deposit->journal_entry_id)
            ->where('account_id', $this->income->id)
            ->update(['memo' => 'Deposit']);

        JournalLine::query()
            ->where('journal_entry_id', $deposit->journal_entry_id)
            ->where('account_id', $this->bank->id)
            ->update(['memo' => 'Hand written']);
    });

    expect(app(BankLineMemoBackfiller::class)->backfill($this->company->id)['updated'])->toBe(0)
        ->and(bankLineMemoOnBankLeg($deposit))->toBe('Hand written');

    expect(JournalLine::query()
        ->where('journal_entry_id', $deposit->journal_entry_id)
        ->where('account_id', $this->income->id)
        ->value('memo'))->toBe('Deposit');
});

it('does not backfill a document with no memo of its own', function () {
    bankLineMemoDeposit(null);

    expect(app(BankLineMemoBackfiller::class)->backfill($this->company->id)['updated'])->toBe(0);
});
