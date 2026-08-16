<?php

use App\Enums\AccountSubtype;
use App\Exceptions\Posting\AlreadyPostedException;
use App\Exceptions\Posting\PeriodLockedException;
use App\Exceptions\Posting\UnbalancedJournalException;
use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Services\Posting\JournalPoster;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function bankAccount(Company $company): Account
{
    return Account::query()->where('subtype', AccountSubtype::Bank->value)->first();
}

function incomeAccount(Company $company): Account
{
    return Account::query()->where('subtype', AccountSubtype::Income->value)->first();
}

it('posts a balanced entry and updates account balances', function () {
    $entry = JournalEntry::create([
        'entry_no' => 'JE-000001',
        'entry_date' => now()->toDateString(),
        'memo' => 'Test sale',
    ]);

    $bank = bankAccount($this->company);
    $income = incomeAccount($this->company);

    $entry->lines()->create([
        'account_id' => $bank->id,
        'debit_cents' => 10000,
        'credit_cents' => 0,
        'line_order' => 0,
    ]);
    $entry->lines()->create([
        'account_id' => $income->id,
        'debit_cents' => 0,
        'credit_cents' => 10000,
        'line_order' => 1,
    ]);

    app(JournalPoster::class)->post($entry);

    expect($entry->fresh()->is_posted)->toBeTrue();
    expect($bank->fresh()->balance_cents)->toBe(10000);
    expect($income->fresh()->balance_cents)->toBe(10000);
});

it('rejects an unbalanced entry', function () {
    $entry = JournalEntry::create([
        'entry_no' => 'JE-000002',
        'entry_date' => now()->toDateString(),
    ]);

    $entry->lines()->create([
        'account_id' => bankAccount($this->company)->id,
        'debit_cents' => 5000,
        'credit_cents' => 0,
    ]);
    $entry->lines()->create([
        'account_id' => incomeAccount($this->company)->id,
        'debit_cents' => 0,
        'credit_cents' => 4000,
    ]);

    app(JournalPoster::class)->post($entry);
})->throws(UnbalancedJournalException::class);

it('cannot post an entry twice', function () {
    $entry = JournalEntry::create([
        'entry_no' => 'JE-000003',
        'entry_date' => now()->toDateString(),
    ]);

    $entry->lines()->createMany([
        ['account_id' => bankAccount($this->company)->id, 'debit_cents' => 100, 'credit_cents' => 0],
        ['account_id' => incomeAccount($this->company)->id, 'debit_cents' => 0, 'credit_cents' => 100],
    ]);

    app(JournalPoster::class)->post($entry);
    app(JournalPoster::class)->post($entry);
})->throws(AlreadyPostedException::class);

it('refuses to post on or before the company lock date', function () {
    $this->company->update(['lock_date' => now()->toDateString()]);

    $entry = JournalEntry::create([
        'entry_no' => 'JE-000004',
        'entry_date' => now()->toDateString(),
    ]);

    $entry->lines()->createMany([
        ['account_id' => bankAccount($this->company)->id, 'debit_cents' => 100, 'credit_cents' => 0],
        ['account_id' => incomeAccount($this->company)->id, 'debit_cents' => 0, 'credit_cents' => 100],
    ]);

    app(JournalPoster::class)->post($entry);
})->throws(PeriodLockedException::class);

it('voids a posted entry by writing a reversing entry', function () {
    $entry = JournalEntry::create([
        'entry_no' => 'JE-000005',
        'entry_date' => now()->toDateString(),
    ]);

    $bank = bankAccount($this->company);
    $income = incomeAccount($this->company);

    $entry->lines()->createMany([
        ['account_id' => $bank->id, 'debit_cents' => 7500, 'credit_cents' => 0],
        ['account_id' => $income->id, 'debit_cents' => 0, 'credit_cents' => 7500],
    ]);

    $poster = app(JournalPoster::class);
    $poster->post($entry);

    $reversal = $poster->void($entry->fresh());

    $entry->refresh();

    expect($entry->voided_at)->not->toBeNull();
    expect($entry->reversed_by_entry_id)->toBe($reversal->id);
    expect($reversal->is_posted)->toBeTrue();
    expect($reversal->totalDebitsCents())->toBe(7500);
    expect($reversal->totalCreditsCents())->toBe(7500);

    // Net balance returns to zero
    expect($bank->fresh()->balance_cents)->toBe(0);
    expect($income->fresh()->balance_cents)->toBe(0);
});
