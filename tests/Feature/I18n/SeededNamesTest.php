<?php

use App\Actions\Fundraising\EnsureFundraisingAccounts;
use App\Enums\AccountType;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use App\Support\Locales;
use Livewire\Livewire;

afterEach(function () {
    app()->forgetInstance('current_company');
    Locales::apply('en');
});

it('seeds a charity in English under a French UI so name lookups still resolve', function () {
    $this->actingAs(User::factory()->create());
    Locales::apply('fr');

    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'Société d’entraide')
        ->set('country', 'CA')
        ->set('region', 'QC')
        ->set('orgCategory', 'nonprofit')
        ->set('legalStructure', 'registered_charity')
        ->set('charityRegistrationNumber', '123456789RR0001')
        ->set('industry', 'non_profit')
        ->call('createCompany')
        ->assertHasNoErrors();

    $company = Company::where('name', 'Société d’entraide')->firstOrFail();
    app()->instance('current_company', $company);

    $grantRevenue = Account::query()
        ->where('type', AccountType::Income->value)
        ->where('name', 'like', '%grant%')
        ->first();

    expect($grantRevenue)->not->toBeNull()
        ->and(Account::query()->where('code', '1000')->value('name'))->toBe('Chequing')
        ->and(app(EnsureFundraisingAccounts::class)->handle($company))->toBe(0);
});

it('translates seeded account names on display but keeps them stored in English', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['address_country' => 'CA']);
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($user);
    app()->instance('current_company', $company);

    Locales::apply('fr');

    Livewire::test('pages::accounts.index', ['company' => $company])
        ->assertSee('Compte de chèques');

    Livewire::test('pages::reports.account-list', ['company' => $company])
        ->assertSee('Compte de chèques');

    expect(Account::query()->where('code', '1000')->value('name'))->toBe('Chequing');
});
