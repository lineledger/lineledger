<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Transfer;
use App\Models\User;
use Livewire\Livewire;

/*
 * The transfer form and the API read one rule for which accounts money can move
 * between. The API half lives in tests/Feature/Api/V1/TransferLifecycleTest.php.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function transferFormAccount(string $code, AccountSubtype $subtype, bool $inTransfers = false, bool $active = true): Account
{
    return Account::create([
        'code' => $code,
        'name' => "Account {$code}",
        'subtype' => $subtype->value,
        'type' => $subtype->type()->value,
        'normal_balance' => $subtype->type()->normalBalance()->value,
        'use_in_transfers' => $inTransfers,
        'is_active' => $active,
    ]);
}

it('offers bank, card and flagged accounts, but never a flagged AR or AP account', function () {
    $card = transferFormAccount('2110', AccountSubtype::CreditCard);
    $lineOfCredit = transferFormAccount('2150', AccountSubtype::CurrentLiability, inTransfers: true);
    $unflagged = transferFormAccount('2160', AccountSubtype::CurrentLiability);
    $receivable = transferFormAccount('1201', AccountSubtype::AccountsReceivable, inTransfers: true);
    $payable = transferFormAccount('2001', AccountSubtype::AccountsPayable, inTransfers: true);
    $closed = transferFormAccount('2170', AccountSubtype::CurrentLiability, inTransfers: true, active: false);

    $offered = Livewire::test('pages::transfers.form', ['company' => $this->company])
        ->instance()->bankAccounts->pluck('id');

    expect($offered)->toContain($this->bank->id, $card->id, $lineOfCredit->id)
        ->not->toContain($unflagged->id)
        ->not->toContain($receivable->id)
        ->not->toContain($payable->id)
        ->not->toContain($closed->id);
});

it('shows the Include in transfers switch only where it has an effect', function (AccountSubtype $subtype, bool $shown) {
    $page = Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->call('openCreate')
        ->set('form_subtype', $subtype->value);

    $shown
        ? $page->assertSee(__('Include in transfers'))
        : $page->assertDontSee(__('Include in transfers'));
})->with([
    'current liability' => [AccountSubtype::CurrentLiability, true],
    'current asset' => [AccountSubtype::CurrentAsset, true],
    'bank (always offered)' => [AccountSubtype::Bank, false],
    'accounts receivable' => [AccountSubtype::AccountsReceivable, false],
    'accounts payable' => [AccountSubtype::AccountsPayable, false],
]);

it('saves a transfer to a flagged account', function () {
    $lineOfCredit = transferFormAccount('2150', AccountSubtype::CurrentLiability, inTransfers: true);

    Livewire::test('pages::transfers.form', ['company' => $this->company])
        ->set('from_account_id', $this->bank->id)
        ->set('to_account_id', $lineOfCredit->id)
        ->set('from_amount', '100.00')
        ->call('saveDraft')
        ->assertHasNoErrors();

    expect(Transfer::firstOrFail()->to_account_id)->toBe($lineOfCredit->id);
});

it('refuses a transfer to an account that is not eligible', function (AccountSubtype $subtype, bool $inTransfers) {
    $account = transferFormAccount('2998', $subtype, $inTransfers);

    Livewire::test('pages::transfers.form', ['company' => $this->company])
        ->set('from_account_id', $this->bank->id)
        ->set('to_account_id', $account->id)
        ->set('from_amount', '100.00')
        ->call('saveDraft')
        ->assertHasErrors(['to_account_id']);

    expect(Transfer::count())->toBe(0);
})->with([
    'liability left switched off' => [AccountSubtype::CurrentLiability, false],
    'accounts receivable switched on' => [AccountSubtype::AccountsReceivable, true],
    'accounts payable switched on' => [AccountSubtype::AccountsPayable, true],
]);
