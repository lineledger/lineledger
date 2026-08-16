<?php

use App\Actions\Accounting\EnableCompanyCurrency;
use App\Actions\Accounting\SaveAccount;
use App\Actions\Accounting\SaveJournalEntry;
use App\Enums\AccountSubtype;
use App\Enums\ChequeStatus;
use App\Enums\DepositStatus;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Deposit;
use App\Services\Posting\ChequePoster;
use App\Services\Posting\DepositPoster;
use App\Services\Posting\JournalPoster;

beforeEach(function () {
    $this->company = Company::factory()->create(['currency_code' => 'CAD']);
    app()->instance('current_company', $this->company);
    app(EnableCompanyCurrency::class)->handle($this->company, 'USD');
    $this->company->refresh();

    $this->expenseAccount = Account::query()->where('subtype', AccountSubtype::Expense->value)->first();
    $this->equity = Account::query()->where('subtype', AccountSubtype::Equity->value)->first();

    $this->usdBank = app(SaveAccount::class)->handle([
        'code' => '1015', 'name' => 'USD Chequing', 'subtype' => AccountSubtype::Bank->value,
        'currency_code' => 'USD',
    ]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('creates a foreign-denominated bank account', function () {
    expect($this->usdBank->currency_code)->toBe('USD')
        ->and($this->usdBank->subtype)->toBe(AccountSubtype::Bank);
});

it('refuses a foreign currency on a non-bank account', function () {
    $account = app(SaveAccount::class)->handle([
        'code' => '5500', 'name' => 'Foreign Expense', 'subtype' => AccountSubtype::Expense->value,
        'currency_code' => 'USD',
    ]);

    expect($account->currency_code)->toBeNull();
});

it('posts a cheque drawn on a foreign bank in home cents', function () {
    $cheque = Cheque::create([
        'bank_account_id' => $this->usdBank->id,
        'cheque_no' => 'CHQ-1',
        'cheque_date' => '2026-03-01',
        'payee_name' => 'US Supplier',
        'amount_cents' => 50_000, // 500 USD
        'fx_rate' => '1.35',
        'status' => ChequeStatus::Draft,
    ]);
    $cheque->lines()->create([
        'account_id' => $this->expenseAccount->id,
        'description' => 'Supplies',
        'amount_cents' => 50_000,
        'tax_cents' => 0,
        'line_order' => 0,
    ]);

    app(ChequePoster::class)->post($cheque);

    $entry = $cheque->refresh()->journalEntry;
    expect($entry->isBalanced())->toBeTrue();

    expect($this->usdBank->fresh()->balance_cents)->toBe(-67_500)        // credit 500 USD @1.35 home
        ->and($this->usdBank->fresh()->foreignBalanceCents())->toBe(-50_000)
        ->and($this->expenseAccount->fresh()->balance_cents)->toBe(67_500);
});

it('posts a deposit into a foreign bank in home cents', function () {
    $deposit = Deposit::create([
        'bank_account_id' => $this->usdBank->id,
        'deposit_no' => 'DEP-1',
        'deposit_date' => '2026-03-01',
        'amount_cents' => 100_000, // 1,000 USD
        'fx_rate' => '1.40',
        'status' => DepositStatus::Draft,
    ]);
    $deposit->lines()->create([
        'account_id' => $this->equity->id,
        'description' => 'Owner contribution',
        'amount_cents' => 100_000,
        'line_order' => 0,
    ]);

    app(DepositPoster::class)->post($deposit);

    $entry = $deposit->refresh()->journalEntry;
    expect($entry->isBalanced())->toBeTrue()
        ->and($this->usdBank->fresh()->balance_cents)->toBe(140_000)
        ->and($this->usdBank->fresh()->foreignBalanceCents())->toBe(100_000);
});

it('stores a foreign memo on a manual journal entry line', function () {
    $entry = app(SaveJournalEntry::class)->handle([
        'entry_date' => '2026-03-01',
        'memo' => 'Foreign cash injection',
        'lines' => [
            ['account_id' => $this->usdBank->id, 'debit_cents' => 135_000, 'credit_cents' => 0,
                'currency_code' => 'USD', 'fx_rate' => '1.35', 'foreign_debit_cents' => 100_000],
            ['account_id' => $this->equity->id, 'debit_cents' => 0, 'credit_cents' => 135_000],
        ],
    ]);

    app(JournalPoster::class)->post($entry);

    $entry->refresh();
    expect($entry->isBalanced())->toBeTrue();

    $bankLine = $entry->lines->firstWhere('account_id', $this->usdBank->id);
    expect($bankLine->currency_code)->toBe('USD')
        ->and($bankLine->foreign_debit_cents)->toBe(100_000)
        ->and($this->usdBank->fresh()->foreignBalanceCents())->toBe(100_000);
});
