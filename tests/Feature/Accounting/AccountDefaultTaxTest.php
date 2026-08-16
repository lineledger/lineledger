<?php

use App\Actions\Accounting\SaveAccount;
use App\Enums\AccountSubtype;
use App\Enums\TaxAppliesTo;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\TaxCode;
use Livewire\Livewire;

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

function accountTaxCode(Company $company, string $code, TaxAppliesTo $appliesTo = TaxAppliesTo::Both): TaxCode
{
    return TaxCode::withoutGlobalScopes()->create([
        'company_id' => $company->id,
        'code' => $code,
        'name' => $code.' Tax',
        'rate_basis_points' => 500,
        'applies_to' => $appliesTo,
        'is_active' => true,
    ]);
}

it('persists, keeps, and clears a default tax code via SaveAccount', function () {
    $company = Company::factory()->create();
    app()->instance('current_company', $company);

    $taxCode = accountTaxCode($company, 'TST1');
    $expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->firstOrFail();

    $base = [
        'code' => $expense->code,
        'name' => $expense->name,
        'subtype' => $expense->subtype->value,
    ];

    app(SaveAccount::class)->handle($base + ['default_tax_code_id' => $taxCode->id], $expense);
    expect($expense->fresh()->default_tax_code_id)->toBe($taxCode->id);

    // Key absent: the existing value is untouched.
    app(SaveAccount::class)->handle($base, $expense);
    expect($expense->fresh()->default_tax_code_id)->toBe($taxCode->id);

    // Explicit null clears it.
    app(SaveAccount::class)->handle($base + ['default_tax_code_id' => null], $expense);
    expect($expense->fresh()->default_tax_code_id)->toBeNull();
});

it('round-trips a default tax code through the COA modal for an expense account', function () {
    $company = Company::factory()->create();
    app()->instance('current_company', $company);

    $taxCode = accountTaxCode($company, 'PTAX', TaxAppliesTo::PurchaseOnly);

    Livewire::test('pages::accounts.index', ['company' => $company])
        ->call('openCreate')
        ->set('form_code', '69001')
        ->set('form_name', 'Office Supplies')
        ->set('form_subtype', AccountSubtype::Expense->value)
        ->assertSeeHtml('data-test="account-default-tax-code-select"')
        ->set('form_default_tax_code_id', $taxCode->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Account::query()->where('code', '69001')->firstOrFail()->default_tax_code_id)
        ->toBe($taxCode->id);
});

it('drops the default tax code when the subtype is not an income or expense type', function () {
    $company = Company::factory()->create();
    app()->instance('current_company', $company);

    $taxCode = accountTaxCode($company, 'PTAX2', TaxAppliesTo::PurchaseOnly);

    $component = Livewire::test('pages::accounts.index', ['company' => $company])
        ->call('openCreate')
        ->set('form_subtype', AccountSubtype::Expense->value)
        ->set('form_default_tax_code_id', $taxCode->id)
        // Switching to a Bank subtype clears the now-inapplicable value...
        ->set('form_subtype', AccountSubtype::Bank->value)
        ->assertSet('form_default_tax_code_id', null)
        // ...and even a value sneaked in afterwards is not persisted by save().
        ->set('form_default_tax_code_id', $taxCode->id)
        ->set('form_code', '10901')
        ->set('form_name', 'Petty Cash Bank')
        ->call('save')
        ->assertHasNoErrors();

    expect(Account::query()->where('code', '10901')->firstOrFail()->default_tax_code_id)->toBeNull();
});

it('filters tax code options by applies_to for the chosen account type', function () {
    $company = Company::factory()->create();
    app()->instance('current_company', $company);

    $sale = accountTaxCode($company, 'SALE1', TaxAppliesTo::SaleOnly);
    $purchase = accountTaxCode($company, 'PURCH1', TaxAppliesTo::PurchaseOnly);
    $both = accountTaxCode($company, 'BOTH1', TaxAppliesTo::Both);

    // Income accounts: sales-applicable codes only.
    $incomeIds = Livewire::test('pages::accounts.index', ['company' => $company])
        ->set('form_subtype', AccountSubtype::Income->value)
        ->instance()->taxCodeOptions->pluck('id');

    expect($incomeIds)->toContain($sale->id)
        ->toContain($both->id)
        ->not->toContain($purchase->id);

    // Expense accounts: purchase-applicable codes only.
    $expenseIds = Livewire::test('pages::accounts.index', ['company' => $company])
        ->set('form_subtype', AccountSubtype::Expense->value)
        ->instance()->taxCodeOptions->pluck('id');

    expect($expenseIds)->toContain($purchase->id)
        ->toContain($both->id)
        ->not->toContain($sale->id);
});

it('rejects another company\'s tax code in the COA modal', function () {
    $company = Company::factory()->create();
    $other = Company::factory()->create();

    // Created BEFORE current_company is bound — BelongsToCompany::creating()
    // would otherwise force the tax code onto the bound company.
    $foreign = accountTaxCode($other, 'FRGN1');

    app()->instance('current_company', $company);

    Livewire::test('pages::accounts.index', ['company' => $company])
        ->call('openCreate')
        ->set('form_code', '69002')
        ->set('form_name', 'Supplies')
        ->set('form_subtype', AccountSubtype::Expense->value)
        ->set('form_default_tax_code_id', $foreign->id)
        ->call('save')
        ->assertHasErrors('form_default_tax_code_id');
});

it('accepts a default tax code on API store and exposes it in the resource', function () {
    $company = Company::factory()->create();
    ['plaintext' => $plain] = CompanyApiKey::mint($company, 'Test');
    $h = ['Authorization' => "Bearer {$plain}"];

    $taxCode = accountTaxCode($company, 'API1');

    $id = $this->postJson('/api/v1/accounts', [
        'code' => '7811',
        'name' => 'Consulting Income',
        'subtype' => AccountSubtype::Income->value,
        'default_tax_code_id' => $taxCode->id,
    ], $h)
        ->assertStatus(201)
        ->assertJsonPath('data.default_tax_code_id', $taxCode->id)
        ->json('data.id');

    $this->getJson("/api/v1/accounts/{$id}", $h)
        ->assertStatus(200)
        ->assertJsonPath('data.default_tax_code_id', $taxCode->id);
});

it('accepts and clears a default tax code on API update', function () {
    $company = Company::factory()->create();
    ['plaintext' => $plain] = CompanyApiKey::mint($company, 'Test');
    $h = ['Authorization' => "Bearer {$plain}"];

    $taxCode = accountTaxCode($company, 'API2');

    $payload = [
        'code' => '7812',
        'name' => 'Service Income',
        'subtype' => AccountSubtype::Income->value,
    ];

    $id = $this->postJson('/api/v1/accounts', $payload, $h)
        ->assertStatus(201)
        ->assertJsonPath('data.default_tax_code_id', null)
        ->json('data.id');

    $this->patchJson("/api/v1/accounts/{$id}", $payload + ['default_tax_code_id' => $taxCode->id], $h)
        ->assertStatus(200)
        ->assertJsonPath('data.default_tax_code_id', $taxCode->id);

    $this->patchJson("/api/v1/accounts/{$id}", $payload + ['default_tax_code_id' => null], $h)
        ->assertStatus(200)
        ->assertJsonPath('data.default_tax_code_id', null);
});

it('rejects another company\'s tax code over the API with 422', function () {
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    ['plaintext' => $plain] = CompanyApiKey::mint($company, 'Test');
    $h = ['Authorization' => "Bearer {$plain}"];

    $foreign = accountTaxCode($other, 'FRGN2');

    $this->postJson('/api/v1/accounts', [
        'code' => '7813',
        'name' => 'Consulting Income',
        'subtype' => AccountSubtype::Income->value,
        'default_tax_code_id' => $foreign->id,
    ], $h)
        ->assertStatus(422)
        ->assertJsonValidationErrors('default_tax_code_id');
});
