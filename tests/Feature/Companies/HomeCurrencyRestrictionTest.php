<?php

use App\Actions\Accounting\EnableCompanyCurrency;
use App\Actions\Companies\CreateCompany;
use App\Enums\CompanyRole;
use App\Enums\Country;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\User;
use App\Services\Stripe\StripePaymentService;
use Livewire\Livewire;
use Stripe\StripeClient;

// Money stores every amount in hundredths, so a zero- or three-decimal home
// currency books amounts the currency can't hold (a ¥1 invoice at 10% posts
// ¥0.10 tax). Only the two-decimal currencies in Currency::selectable() may be
// a home currency; companies saved before the rule keep theirs.

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function homeCurrencyOwnedCompany(User $user, string $currencyCode = 'CAD'): Company
{
    $company = Company::factory()->create(['currency_code' => $currencyCode]);
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    return $company;
}

test('the model refuses to create a company with a zero-decimal home currency', function () {
    expect(fn () => Company::factory()->create(['currency_code' => 'JPY']))
        ->toThrow(DomainException::class, 'Home currency [JPY] is not supported');
});

test('the CreateCompany action refuses a zero-decimal home currency', function () {
    expect(fn () => app(CreateCompany::class)->handle(
        user: $this->user,
        name: 'Tokyo Co',
        country: Country::Canada,
        regionCode: 'BC',
        currencyCode: 'JPY',
    ))->toThrow(DomainException::class);

    expect(Company::query()->where('name', 'Tokyo Co')->exists())->toBeFalse();
});

test('the model refuses to switch a company to an unsupported home currency', function (string $code) {
    $company = Company::factory()->create(['currency_code' => 'CAD']);

    expect(fn () => $company->update(['currency_code' => $code]))->toThrow(DomainException::class);
    expect($company->fresh()->currency_code)->toBe('CAD');
})->with(['zero-decimal' => 'JPY', 'three-decimal' => 'BHD', 'unknown' => 'XYZ']);

test('the model still allows switching to another two-decimal currency', function () {
    $company = Company::factory()->create(['currency_code' => 'CAD']);

    $company->update(['currency_code' => 'EUR']);

    expect($company->fresh()->currency_code)->toBe('EUR');
});

test('a company already on an unsupported home currency can still save other fields', function () {
    $company = forceLegacyHomeCurrency(Company::factory()->create(), 'JPY');

    $company->update(['name' => 'Renamed Co']);

    expect($company->fresh())
        ->name->toBe('Renamed Co')
        ->currency_code->toBe('JPY');
});

test('the edit page offers only two-decimal currencies', function () {
    $company = homeCurrencyOwnedCompany($this->user);

    Livewire::test('pages::companies.edit', ['company' => $company])
        ->assertSee('EUR — Euro')
        ->assertDontSee('Japanese Yen');
});

test('the edit page rejects an unsupported home currency', function (string $code) {
    $company = homeCurrencyOwnedCompany($this->user);

    Livewire::test('pages::companies.edit', ['company' => $company])
        ->set('currencyCode', $code)
        ->call('updateCompany')
        ->assertHasErrors(['currencyCode']);

    expect($company->fresh()->currency_code)->toBe('CAD');
})->with(['JPY', 'XYZ']);

test('the edit page accepts a two-decimal home currency', function () {
    $company = homeCurrencyOwnedCompany($this->user);

    Livewire::test('pages::companies.edit', ['company' => $company])
        ->set('currencyCode', 'EUR')
        ->call('updateCompany')
        ->assertHasNoErrors();

    expect($company->fresh()->currency_code)->toBe('EUR');
});

test('the edit page keeps a legacy home currency but will not switch to another unsupported one', function () {
    $company = forceLegacyHomeCurrency(homeCurrencyOwnedCompany($this->user), 'JPY');

    Livewire::test('pages::companies.edit', ['company' => $company])
        ->assertSee('JPY — not supported')
        ->call('updateCompany')
        ->assertHasNoErrors()
        ->set('currencyCode', 'BHD')
        ->call('updateCompany')
        ->assertHasErrors(['currencyCode']);

    expect($company->fresh()->currency_code)->toBe('JPY');
});

test('the create-organization modal rejects a zero-decimal currency', function () {
    Livewire::test('pages::companies.index')
        ->set('name', 'Tokyo Co')
        ->set('currency', 'JPY')
        ->call('createCompany')
        ->assertHasErrors(['currency']);

    expect(Company::query()->where('name', 'Tokyo Co')->exists())->toBeFalse();
});

test('the setup wizard rejects a zero-decimal currency', function () {
    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'Tokyo Co')
        ->set('country', 'CA')
        ->set('region', 'BC')
        ->set('currency', 'JPY')
        ->call('createCompany')
        ->assertHasErrors(['currency']);

    expect(Company::query()->where('name', 'Tokyo Co')->exists())->toBeFalse();
});

test('a legacy zero-decimal company cannot take card payments', function () {
    $company = Company::factory()->create();
    $company->forceFill(['stripe_account_id' => 'acct_test123', 'stripe_connected_at' => now()])->save();

    expect($company->canAcceptCardPayments())->toBeTrue();

    forceLegacyHomeCurrency($company, 'JPY');

    expect($company->canAcceptCardPayments())->toBeFalse();
});

test('Stripe is never asked to charge a zero-decimal currency', function () {
    // Stripe reads JPY amounts in whole yen, so our 100000 "cents" (¥1,000)
    // would charge ¥100,000. The guard throws before any request is built.
    $company = forceLegacyHomeCurrency(Company::factory()->create(), 'JPY');
    $service = new StripePaymentService(new StripeClient('sk_test_dummy'));

    expect(fn () => $service->createPaymentIntent($company, 100_000, []))
        ->toThrow(DomainException::class, 'Card payments are not supported in [JPY]');
});

test('multi-currency cannot be enabled on a legacy zero-decimal home currency', function () {
    $company = forceLegacyHomeCurrency(homeCurrencyOwnedCompany($this->user), 'JPY');

    expect(fn () => app(EnableCompanyCurrency::class)->handle($company, 'USD'))
        ->toThrow(DomainException::class, 'Multi-currency is not available with home currency [JPY]');

    expect(CompanyCurrency::withoutGlobalScopes()->where('company_id', $company->id)->exists())->toBeFalse()
        ->and($company->fresh()->multicurrency_enabled)->toBeFalse();

    app()->instance('current_company', $company);

    Livewire::test('pages::settings.currencies', ['company' => $company])
        ->set('f_currency_code', 'USD')
        ->call('enable')
        ->assertHasErrors(['f_currency_code']);

    app()->forgetInstance('current_company');
});
