<?php

use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;
use App\Models\TaxCode;

beforeEach(function () {
    $this->company = Company::factory()->create();
    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test');
    $this->plain = $plain;
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

it('creates a customer scoped to the key\'s company', function () {
    $response = $this->postJson('/api/v1/customers', [
        'display_name' => 'Acme Corp',
        'email' => 'a@a.com',
        'billing_address' => [
            'line1' => '1 Main St',
            'city' => 'Toronto',
            'country' => 'CA',
        ],
    ], ['Authorization' => "Bearer {$this->plain}"]);

    $response->assertStatus(201)
        ->assertJsonPath('data.display_name', 'Acme Corp')
        ->assertJsonPath('data.email', 'a@a.com')
        ->assertJsonPath('data.is_customer', true)
        ->assertJsonPath('data.billing_address.city', 'Toronto');

    expect(Contact::query()->withoutGlobalScopes()->count())->toBe(1);
    $contact = Contact::query()->withoutGlobalScopes()->first();
    expect($contact->company_id)->toBe($this->company->id);
    expect($contact->is_customer)->toBeTrue();
});

it('rejects payloads missing display_name', function () {
    $this->postJson('/api/v1/customers', [
        'email' => 'a@a.com',
    ], ['Authorization' => "Bearer {$this->plain}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['display_name']);
});

it('rejects FKs from another company', function () {
    $otherCompany = Company::factory()->create();
    app()->instance('current_company', $otherCompany);
    $foreignContact = Contact::create(['display_name' => 'X', 'is_customer' => true]);
    app()->forgetInstance('current_company');

    // tax_codes are seeded per company; pick one from the foreign company
    $foreignTaxCode = TaxCode::query()
        ->withoutGlobalScopes()
        ->where('company_id', $otherCompany->id)
        ->first();

    expect($foreignTaxCode)->not->toBeNull();

    $this->postJson('/api/v1/customers', [
        'display_name' => 'Acme',
        'default_tax_code_id' => $foreignTaxCode->id,
    ], ['Authorization' => "Bearer {$this->plain}"])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['default_tax_code_id']);
});

it('creates a customer opted out of email unless the preferences are passed', function () {
    $this->postJson('/api/v1/customers', [
        'display_name' => 'Silent Partner',
    ], ['Authorization' => "Bearer {$this->plain}"])
        ->assertStatus(201)
        ->assertJsonPath('data.invoice_emails_enabled', false)
        ->assertJsonPath('data.reminder_emails_enabled', false);

    $this->postJson('/api/v1/customers', [
        'display_name' => 'Loud Partner',
        'invoice_emails_enabled' => true,
        'reminder_emails_enabled' => true,
    ], ['Authorization' => "Bearer {$this->plain}"])
        ->assertStatus(201)
        ->assertJsonPath('data.invoice_emails_enabled', true)
        ->assertJsonPath('data.reminder_emails_enabled', true);
});
