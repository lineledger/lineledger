<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;
use App\Models\PaymentMethod;
use App\Models\PaymentTerm;
use App\Models\TaxAgency;
use App\Models\TaxCode;

/*
 * A list record something still points at must not be deleted through the
 * API: tax codes, payment methods and terms are hard-deleted and their FKs
 * SET NULL, which silently strips them off posted history; contacts and
 * accounts are soft-deleted, which leaves documents pointing at a record no
 * page can load. Unused records still delete.
 */

beforeEach(function () {
    $this->company = Company::factory()->create();
    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test');
    $this->h = ['Authorization' => "Bearer {$plain}"];

    app()->instance('current_company', $this->company);
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $this->customer = Contact::create(['display_name' => 'Acme', 'is_customer' => true]);
    $agency = TaxAgency::query()->first();
    $this->taxCode = TaxCode::create([
        'code' => 'ZZ', 'name' => 'Test tax', 'rate_basis_points' => 500, 'applies_to' => 'both',
        'agency_id' => $agency?->id,
    ]);
    app()->forgetInstance('current_company');
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

function listGuardInvoice(array $line = [], array $header = []): int
{
    return test()->postJson('/api/v1/invoices', array_merge([
        'contact_id' => test()->customer->id,
        'invoice_date' => '2026-05-20',
        'post' => false,
        'lines' => [array_merge([
            'description' => 'Work', 'quantity' => '1', 'unit_price_cents' => 5000,
            'account_id' => test()->income->id,
        ], $line)],
    ], $header), test()->h)->assertStatus(201)->json('data.id');
}

it('refuses to delete a tax code a document line still uses, and deletes an unused one', function () {
    listGuardInvoice(['tax_code_id' => $this->taxCode->id]);

    $this->deleteJson("/api/v1/tax-codes/{$this->taxCode->id}", [], $this->h)
        ->assertStatus(409)
        ->assertJsonFragment(['message' => __('This :record is still used (:where), so it can\'t be deleted. Make it inactive instead.', ['record' => __('tax code'), 'where' => 'invoice lines'])]);

    expect(TaxCode::withoutGlobalScopes()->whereKey($this->taxCode->id)->exists())->toBeTrue();

    $unused = TaxCode::withoutGlobalScopes()->create([
        'company_id' => $this->company->id, 'code' => 'YY', 'name' => 'Unused', 'rate_basis_points' => 100, 'applies_to' => 'both',
        'agency_id' => $this->taxCode->agency_id,
    ]);

    $this->deleteJson("/api/v1/tax-codes/{$unused->id}", [], $this->h)->assertStatus(204);
});

it('refuses to delete a payment method or terms a contact still names', function () {
    app()->instance('current_company', $this->company);
    $method = PaymentMethod::create(['name' => 'Wire', 'is_active' => true]);
    $terms = PaymentTerm::create(['name' => 'Net 45', 'days' => 45, 'is_active' => true]);
    $this->customer->forceFill(['preferred_payment_method_id' => $method->id, 'default_terms_id' => $terms->id])->save();
    app()->forgetInstance('current_company');

    $this->deleteJson("/api/v1/payment-methods/{$method->id}", [], $this->h)->assertStatus(409);
    $this->deleteJson("/api/v1/payment-terms/{$terms->id}", [], $this->h)->assertStatus(409);

    expect(PaymentMethod::withoutGlobalScopes()->whereKey($method->id)->exists())->toBeTrue()
        ->and(PaymentTerm::withoutGlobalScopes()->whereKey($terms->id)->exists())->toBeTrue();

    $this->customer->forceFill(['preferred_payment_method_id' => null, 'default_terms_id' => null])->save();

    $this->deleteJson("/api/v1/payment-methods/{$method->id}", [], $this->h)->assertStatus(204);
    $this->deleteJson("/api/v1/payment-terms/{$terms->id}", [], $this->h)->assertStatus(204);
});

it('refuses to delete a customer a document still names, and deletes one nothing uses', function () {
    listGuardInvoice();

    $this->deleteJson("/api/v1/customers/{$this->customer->id}", [], $this->h)->assertStatus(409);
    expect(Contact::withoutGlobalScopes()->whereKey($this->customer->id)->whereNull('deleted_at')->exists())->toBeTrue();

    app()->instance('current_company', $this->company);
    $unused = Contact::create(['display_name' => 'Nobody', 'is_customer' => true]);
    app()->forgetInstance('current_company');

    $this->deleteJson("/api/v1/customers/{$unused->id}", [], $this->h)->assertStatus(204);
});

it('refuses to delete a system account or one a sub-account still sits under', function () {
    app()->instance('current_company', $this->company);
    $system = Account::query()->where('is_system', true)->firstOrFail();
    $parent = Account::create(['code' => '5990', 'name' => 'Parent', 'type' => $this->income->type, 'subtype' => $this->income->subtype, 'normal_balance' => $this->income->normal_balance]);
    Account::create(['code' => '5991', 'name' => 'Child', 'type' => $this->income->type, 'subtype' => $this->income->subtype, 'normal_balance' => $this->income->normal_balance, 'parent_id' => $parent->id]);
    $lonely = Account::create(['code' => '5992', 'name' => 'Lonely', 'type' => $this->income->type, 'subtype' => $this->income->subtype, 'normal_balance' => $this->income->normal_balance]);
    app()->forgetInstance('current_company');

    $this->deleteJson("/api/v1/accounts/{$system->id}", [], $this->h)->assertStatus(409);
    $this->deleteJson("/api/v1/accounts/{$parent->id}", [], $this->h)->assertStatus(409);
    $this->deleteJson("/api/v1/accounts/{$lonely->id}", [], $this->h)->assertStatus(204);
});
