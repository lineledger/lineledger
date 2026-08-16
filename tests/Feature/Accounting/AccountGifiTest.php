<?php

use App\Actions\Accounting\SaveAccount;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\OrganizationType;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create(['organization_type' => OrganizationType::Corporation->value]);
    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    app()->instance('current_company', $this->company);
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('backfills a GIFI line from the subtype when an account is created', function () {
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();

    expect($bank->gifi_code)->toBe('1001');
});

it('saves the GIFI line chosen on the account form', function () {
    $account = Account::query()->where('subtype', AccountSubtype::CurrentAsset->value)->orderBy('code')->first();

    Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->call('openEdit', $account->id)
        ->set('form_gifi_code', '1484')
        ->call('save')
        ->assertHasNoErrors();

    expect($account->fresh()->gifi_code)->toBe('1484');
});

it('clears an unrecognised GIFI code rather than storing it', function () {
    $account = Account::query()->where('subtype', AccountSubtype::CurrentAsset->value)->orderBy('code')->first();

    app(SaveAccount::class)->handle([
        'code' => $account->code,
        'name' => $account->name,
        'subtype' => $account->subtype->value,
        'gifi_code' => '9999',
    ], $account);

    expect($account->fresh()->gifi_code)->toBeNull();
});

it('shows the GIFI field only for Canadian companies', function () {
    Livewire::test('pages::accounts.index', ['company' => $this->company])
        ->assertSeeHtml('account-gifi-select');

    $us = Company::factory()->create(['address_country' => 'US']);
    $us->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $us);

    Livewire::test('pages::accounts.index', ['company' => $us])
        ->assertDontSeeHtml('account-gifi-select');
});
