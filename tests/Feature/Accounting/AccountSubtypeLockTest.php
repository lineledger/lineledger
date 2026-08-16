<?php

use App\Actions\Accounting\SaveAccount;
use App\Actions\Accounting\SaveJournalEntry;
use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

/**
 * A non-system account carrying a single DRAFT journal line — unposted
 * activity is enough to freeze the type.
 */
function accountWithDraftLine(): Account
{
    $account = app(SaveAccount::class)->handle([
        'code' => '1730',
        'name' => 'Workshop Equipment',
        'subtype' => AccountSubtype::FixedAsset->value,
    ]);

    $counter = Account::query()->where('subtype', AccountSubtype::Equity->value)->first();

    app(SaveJournalEntry::class)->handle([
        'entry_date' => '2026-06-01',
        'memo' => 'Draft equipment purchase',
        'lines' => [
            ['account_id' => $account->id, 'debit_cents' => 12500, 'credit_cents' => 0],
            ['account_id' => $counter->id, 'debit_cents' => 0, 'credit_cents' => 12500],
        ],
    ]);

    return $account;
}

it('keeps the subtype frozen in SaveAccount once the account has journal lines', function () {
    $account = accountWithDraftLine();

    app(SaveAccount::class)->handle([
        'code' => '1730',
        'name' => 'Workshop Equipment',
        'subtype' => AccountSubtype::Expense->value,
    ], $account);

    $fresh = $account->fresh();

    expect($fresh->subtype)->toBe(AccountSubtype::FixedAsset);
    expect($fresh->type)->toBe(AccountSubtype::FixedAsset->type());
    expect($fresh->normal_balance)->toBe(AccountSubtype::FixedAsset->type()->normalBalance());
});

it('returns 422 from the API when retyping an account with transactions', function () {
    $account = accountWithDraftLine();

    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test');
    app()->forgetInstance('current_company');

    $this->patchJson("/api/v1/accounts/{$account->id}", [
        'code' => '1730',
        'name' => 'Workshop Equipment',
        'subtype' => AccountSubtype::Expense->value,
    ], ['Authorization' => "Bearer {$plain}"])
        ->assertStatus(422)
        ->assertJsonPath('message', 'An account with transactions cannot change its type.');

    // Same subtype (a plain rename) still goes through.
    $this->patchJson("/api/v1/accounts/{$account->id}", [
        'code' => '1730',
        'name' => 'Shop Equipment',
        'subtype' => AccountSubtype::FixedAsset->value,
    ], ['Authorization' => "Bearer {$plain}"])
        ->assertStatus(200)
        ->assertJsonPath('data.name', 'Shop Equipment');
});

it('disables the type select and explains why on the edit form', function () {
    $account = accountWithDraftLine();

    $component = Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->call('openEdit', $account->id)
        ->assertSeeHtml('data-test="account-subtype-locked-note"')
        ->assertSee('This account has transactions, so its type can no longer be changed.');

    expect($component->instance()->subtypeLocked)->toBeTrue();
    expect($component->html())->toMatch('/<[^>]*data-test="account-subtype-select"[^>]*disabled|<[^>]*disabled[^>]*data-test="account-subtype-select"/');
});

it('still allows retyping an account without journal lines', function () {
    $account = app(SaveAccount::class)->handle([
        'code' => '1740',
        'name' => 'Misc Holding',
        'subtype' => AccountSubtype::CurrentAsset->value,
    ]);

    Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->call('openEdit', $account->id)
        ->assertDontSeeHtml('data-test="account-subtype-locked-note"');

    app(SaveAccount::class)->handle([
        'code' => '1740',
        'name' => 'Misc Holding',
        'subtype' => AccountSubtype::OtherAsset->value,
    ], $account);

    expect($account->fresh()->subtype)->toBe(AccountSubtype::OtherAsset);
});
