<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Deposit;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->where('is_active', true)->orderBy('code')->firstOrFail();
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('saves a deposit as an unposted draft, then posts it from the deposit page', function () {
    Livewire::test('pages::deposits.form', ['company' => $this->company])
        ->set('bank_account_id', $this->bank->id)
        ->set('otherLines', [[
            'account_id' => $this->income->id,
            'contact_id' => null,
            'description' => 'Owner contribution',
            'amount' => '500.00',
            'class_id' => null,
            'location_id' => null,
        ]])
        ->call('saveDraft')
        ->assertHasNoErrors();

    $deposit = Deposit::query()->firstOrFail();

    expect($deposit->status->value)->toBe('draft')
        ->and($deposit->journal_entry_id)->toBeNull()
        ->and($deposit->amount_cents)->toBe(50000);

    Livewire::test('pages::deposits.show', ['company' => $this->company, 'deposit' => $deposit])
        ->call('post');

    expect($deposit->fresh()->status->value)->toBe('posted')
        ->and($deposit->fresh()->journal_entry_id)->not->toBeNull();
});
