<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\User;
use App\Services\Posting\DocumentNumberGenerator;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->where('is_active', true)->orderBy('code')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function chequeNumbered(string $number, ?Account $bank = null): Cheque
{
    return Cheque::create([
        'bank_account_id' => ($bank ?? test()->bank)->id,
        'cheque_no' => $number,
        'cheque_date' => '2026-10-01',
        'payee_name' => 'Supplier',
    ]);
}

function suggestedChequeNumber(?Account $bank = null): string
{
    return app(DocumentNumberGenerator::class)->nextChequeNumber(test()->company, ($bank ?? test()->bank)->id);
}

it('starts a fresh chequebook at 1001', function () {
    expect(suggestedChequeNumber())->toBe('1001');
});

it('counts on from the last cheque number', function () {
    chequeNumbered('10300');

    expect(suggestedChequeNumber())->toBe('10301');
});

it('skips an e-transfer or other label with no number', function () {
    chequeNumbered('10300');
    chequeNumbered('e-transfer');
    chequeNumbered('EFT');

    expect(suggestedChequeNumber())->toBe('10301');
});

it('keeps the number’s own format', function () {
    chequeNumbered('CHQ-009');

    expect(suggestedChequeNumber())->toBe('CHQ-010');
});

it('steps past a number already used on the account', function () {
    chequeNumbered('10301');
    chequeNumbered('10300');

    expect(suggestedChequeNumber())->toBe('10302');
});

it('falls back to 1001 when only labels have been used', function () {
    chequeNumbered('e-transfer');

    expect(suggestedChequeNumber())->toBe('1001');
});

it('numbers each bank account on its own', function () {
    $savings = Account::query()->where('subtype', AccountSubtype::Bank->value)->whereKeyNot($this->bank->id)->first()
        ?? Account::create(['code' => '1090', 'name' => 'Savings', 'subtype' => AccountSubtype::Bank->value, 'type' => $this->bank->type]);

    chequeNumbered('10300');

    expect(suggestedChequeNumber($savings))->toBe('1001');
});

it('suggests the next number on the cheque form after an e-transfer', function () {
    chequeNumbered('10300');
    chequeNumbered('e-transfer');

    Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->assertSet('bank_account_id', $this->bank->id)
        ->assertSet('cheque_no', '10301');
});
