<?php

use App\Enums\ChequeStatus;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\OpeningBalanceRow;
use App\Models\OpeningBalanceState;
use App\Services\OpeningBalances\DepositInTransitSync;
use App\Services\OpeningBalances\OpeningBalanceStatusBuilder;
use App\Services\OpeningBalances\OutstandingChequeSync;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);
    $this->state = OpeningBalanceState::create([
        'company_id' => $this->company->id,
        'as_of_date' => '2026-06-30',
    ]);
    $this->bank = Account::query()->where('code', '1000')->firstOrFail();
    $this->obe = Account::query()->where('code', '3000')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function chequeSync(): OutstandingChequeSync
{
    return app(OutstandingChequeSync::class);
}

function depositSync(): DepositInTransitSync
{
    return app(DepositInTransitSync::class);
}

function bankTarget($state, Account $bank, int $debit): void
{
    OpeningBalanceRow::updateOrCreate(
        ['opening_balance_state_id' => $state->id, 'account_id' => $bank->id],
        ['company_id' => $state->company_id, 'debit_cents' => $debit, 'credit_cents' => 0],
    );
}

it('posts an outstanding cheque as DR OBE / CR Bank at its original date, uncleared', function () {
    $cheque = chequeSync()->create($this->state, [
        'bank_account_id' => $this->bank->id,
        'cheque_no' => '4021',
        'cheque_date' => CarbonImmutable::parse('2026-05-14'),
        'payee_name' => 'Acme Roofing',
        'amount_cents' => 20000,
    ]);

    expect($cheque->is_opening_balance)->toBeTrue();
    expect($cheque->journal_entry_id)->not->toBeNull();

    $entry = $cheque->journalEntry()->with('lines')->first();
    expect($entry->entry_date->toDateString())->toBe('2026-05-14');

    $bankLine = $entry->lines->firstWhere('account_id', $this->bank->id);
    $obeLine = $entry->lines->firstWhere('account_id', $this->obe->id);

    expect($bankLine->credit_cents)->toBe(20000);
    expect($bankLine->cleared_at)->toBeNull();       // a future reconciliation ticks it
    expect($obeLine->debit_cents)->toBe(20000);
});

it('folds outstanding items into the maintained entry so the bank lands on its book target', function () {
    bankTarget($this->state, $this->bank, 100000);

    chequeSync()->create($this->state, [
        'bank_account_id' => $this->bank->id,
        'cheque_no' => '4021',
        'cheque_date' => CarbonImmutable::parse('2026-05-14'),
        'payee_name' => 'Acme Roofing',
        'amount_cents' => 20000,
    ]);

    depositSync()->create($this->state, [
        'bank_account_id' => $this->bank->id,
        'deposit_date' => CarbonImmutable::parse('2026-06-29'),
        'amount_cents' => 5000,
    ]);

    $state = $this->state->refresh();
    $entry = $state->journalEntry()->with('lines')->first();
    $bankLine = $entry->lines->firstWhere('account_id', $this->bank->id);

    // Maintained line = book target + outstanding cheques − deposits in transit
    // = the statement-side balance, pre-marked cleared for the first rec.
    expect($bankLine->debit_cents)->toBe(115000);
    expect($bankLine->cleared_at)->not->toBeNull();

    // The as-of GL balance is the book target, to the penny.
    $signed = DB::table('journal_lines')
        ->where('account_id', $this->bank->id)
        ->where('is_posted', true)
        ->where('entry_date', '<=', '2026-06-30')
        ->selectRaw('COALESCE(SUM(debit_cents - credit_cents), 0) as signed')
        ->value('signed');
    expect((int) $signed)->toBe(100000);

    // And the status panel spells the same figures out.
    $status = app(OpeningBalanceStatusBuilder::class)->build($state);
    $bankRow = collect($status['banks'])->firstWhere('account_id', $this->bank->id);
    expect($bankRow['book_target'])->toBe(100000);
    expect($bankRow['outstanding_cheques'])->toBe(20000);
    expect($bankRow['deposits_in_transit'])->toBe(5000);
    expect($bankRow['statement_side'])->toBe(115000);
    expect($status['dirty'])->toBeFalse();
});

it('reposts a cheque edit on the same journal entry and re-nets the bank', function () {
    bankTarget($this->state, $this->bank, 100000);

    $cheque = chequeSync()->create($this->state, [
        'bank_account_id' => $this->bank->id,
        'cheque_no' => '4021',
        'cheque_date' => CarbonImmutable::parse('2026-05-14'),
        'payee_name' => 'Acme Roofing',
        'amount_cents' => 20000,
    ]);

    $entryId = $cheque->journal_entry_id;

    $cheque = chequeSync()->update($this->state, $cheque, ['amount_cents' => 25000]);

    expect($cheque->journal_entry_id)->toBe($entryId);
    expect((int) $cheque->amount_cents)->toBe(25000);

    $maintained = $this->state->refresh()->journalEntry()->with('lines')->first();
    expect($maintained->lines->firstWhere('account_id', $this->bank->id)->debit_cents)->toBe(125000);
});

it('voids a removed cheque at its original date and re-nets the bank', function () {
    bankTarget($this->state, $this->bank, 100000);

    $cheque = chequeSync()->create($this->state, [
        'bank_account_id' => $this->bank->id,
        'cheque_no' => '4021',
        'cheque_date' => CarbonImmutable::parse('2026-05-14'),
        'payee_name' => 'Acme Roofing',
        'amount_cents' => 20000,
    ]);

    chequeSync()->remove($this->state, $cheque);

    expect($cheque->fresh()->status)->toBe(ChequeStatus::Void);

    $maintained = $this->state->refresh()->journalEntry()->with('lines')->first();
    expect($maintained->lines->firstWhere('account_id', $this->bank->id)->debit_cents)->toBe(100000);
});

it('creates a deposit in transit as DR Bank / CR OBE with an OBD number', function () {
    $deposit = depositSync()->create($this->state, [
        'bank_account_id' => $this->bank->id,
        'deposit_date' => CarbonImmutable::parse('2026-06-29'),
        'description' => 'June 29 daily takings',
        'amount_cents' => 5000,
    ]);

    expect(str_starts_with($deposit->deposit_no, 'OBD-'))->toBeTrue();
    expect($deposit->is_opening_balance)->toBeTrue();

    $entry = $deposit->journalEntry()->with('lines')->first();
    expect($entry->lines->firstWhere('account_id', $this->bank->id)->debit_cents)->toBe(5000);
    expect($entry->lines->firstWhere('account_id', $this->bank->id)->cleared_at)->toBeNull();
    expect($entry->lines->firstWhere('account_id', $this->obe->id)->credit_cents)->toBe(5000);
});

it('rejects a duplicate cheque number on the same bank account', function () {
    $data = [
        'bank_account_id' => $this->bank->id,
        'cheque_no' => '4021',
        'cheque_date' => CarbonImmutable::parse('2026-05-14'),
        'payee_name' => 'Acme Roofing',
        'amount_cents' => 20000,
    ];

    chequeSync()->create($this->state, $data);

    expect(fn () => chequeSync()->create($this->state, $data))
        ->toThrow(RuntimeException::class, 'already used');
});

it('rejects a non-opening cheque and a non-bank account', function () {
    expect(fn () => chequeSync()->create($this->state, [
        'bank_account_id' => $this->obe->id,
        'cheque_no' => '1',
        'cheque_date' => CarbonImmutable::parse('2026-05-14'),
        'payee_name' => 'X',
        'amount_cents' => 100,
    ]))->toThrow(RuntimeException::class, 'bank account');

    $foreign = Cheque::create([
        'company_id' => $this->company->id,
        'bank_account_id' => $this->bank->id,
        'cheque_no' => '9999',
        'cheque_date' => '2026-05-14',
        'payee_name' => 'Ordinary',
        'status' => ChequeStatus::Draft,
    ]);

    expect(fn () => chequeSync()->update($this->state, $foreign, ['amount_cents' => 100]))
        ->toThrow(RuntimeException::class, 'Not an opening-balance cheque');
});
