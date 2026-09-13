<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Deposit;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Posting\ChequePoster;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $this->company);
    $this->actingAs($this->user);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('sorts the bank register by date or entry number in either direction', function () {
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();

    $post = function (string $number, string $date) use ($bank, $expense): void {
        $cheque = Cheque::create([
            'bank_account_id' => $bank->id,
            'cheque_no' => $number,
            'cheque_date' => $date,
            'payee_name' => 'Test',
        ]);
        $cheque->lines()->create(['account_id' => $expense->id, 'description' => 'X', 'amount_cents' => 1000, 'line_order' => 0]);
        app(ChequePoster::class)->post($cheque);
    };

    $post('4001', '2026-08-01');
    $post('4002', '2026-08-15');

    $register = Livewire::test('pages::banking.register', ['company' => $this->company])
        ->set('account_id', $bank->id);

    expect($register->instance()->lines->pluck('journalEntry.entry_date')->map->toDateString()->all())
        ->toBe(['2026-08-01', '2026-08-15']);

    $register->call('sortBy', 'entry_date');

    expect($register->instance()->sortDir)->toBe('desc')
        ->and($register->instance()->lines->pluck('journalEntry.entry_date')->map->toDateString()->all())
        ->toBe(['2026-08-15', '2026-08-01']);

    $register->assertSeeHtml('data-test="sort-entry_date"')
        ->assertSeeHtml('data-test="sort-entry_no"');
});

it('sorts the cheques list by date in either direction', function () {
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();

    foreach ([['5001', '2026-08-01'], ['5002', '2026-08-15']] as [$number, $date]) {
        $cheque = Cheque::create([
            'bank_account_id' => $bank->id,
            'cheque_no' => $number,
            'cheque_date' => $date,
            'payee_name' => 'Test',
        ]);
        $cheque->lines()->create(['account_id' => $expense->id, 'description' => 'X', 'amount_cents' => 1000, 'line_order' => 0]);
    }

    $page = Livewire::test('pages::cheques.index', ['company' => $this->company]);

    expect($page->instance()->allCheques->pluck('cheque_date')->all())->toBe(['2026-08-15', '2026-08-01']);

    $page->call('sortBy', 'date');

    expect($page->instance()->sortDir)->toBe('asc')
        ->and($page->instance()->allCheques->pluck('cheque_date')->all())->toBe(['2026-08-01', '2026-08-15']);

    $page->assertSeeHtml('data-test="sort-date"');
});

it('sorts the deposits list by date in either direction', function () {
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();

    Deposit::create(['bank_account_id' => $bank->id, 'deposit_no' => 'DEP-101', 'deposit_date' => '2026-08-01']);
    Deposit::create(['bank_account_id' => $bank->id, 'deposit_no' => 'DEP-102', 'deposit_date' => '2026-08-15']);

    $page = Livewire::test('pages::deposits.index', ['company' => $this->company]);

    expect($page->instance()->deposits->pluck('deposit_no')->all())->toBe(['DEP-102', 'DEP-101']);

    $page->call('sortBy', 'date');

    expect($page->instance()->sortDir)->toBe('asc')
        ->and($page->instance()->deposits->pluck('deposit_no')->all())->toBe(['DEP-101', 'DEP-102']);

    $page->assertSeeHtml('data-test="sort-date"');
});

it('sorts the transfers list by date in either direction', function () {
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $savings = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->skip(1)->first()
        ?? Account::factory()->create(['subtype' => AccountSubtype::Bank->value]);

    Transfer::create([
        'from_account_id' => $bank->id,
        'to_account_id' => $savings->id,
        'transfer_no' => 'XFR-101',
        'transfer_date' => '2026-08-01',
        'from_amount_cents' => 500,
        'to_amount_cents' => 500,
    ]);
    Transfer::create([
        'from_account_id' => $bank->id,
        'to_account_id' => $savings->id,
        'transfer_no' => 'XFR-102',
        'transfer_date' => '2026-08-15',
        'from_amount_cents' => 500,
        'to_amount_cents' => 500,
    ]);

    $page = Livewire::test('pages::transfers.index', ['company' => $this->company]);

    expect($page->instance()->transfers->pluck('transfer_no')->all())->toBe(['XFR-102', 'XFR-101']);

    $page->call('sortBy', 'date');

    expect($page->instance()->sortDir)->toBe('asc')
        ->and($page->instance()->transfers->pluck('transfer_no')->all())->toBe(['XFR-101', 'XFR-102']);

    $page->assertSeeHtml('data-test="sort-date"');
});
