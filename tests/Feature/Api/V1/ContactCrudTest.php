<?php

use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;

beforeEach(function () {
    $this->company = Company::factory()->create();
    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test');
    $this->plain = $plain;
    $this->h = ['Authorization' => "Bearer {$plain}"];
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

it('creates, lists, shows, updates and deletes a vendor', function () {
    $id = $this->postJson('/api/v1/vendors', ['display_name' => 'Acme Supply'], $this->h)
        ->assertStatus(201)
        ->assertJsonPath('data.is_vendor', true)
        ->json('data.id');

    $this->getJson('/api/v1/vendors', $this->h)->assertStatus(200)->assertJsonPath('meta.total', 1);
    $this->getJson("/api/v1/vendors/{$id}", $this->h)->assertStatus(200)->assertJsonPath('data.display_name', 'Acme Supply');

    $this->patchJson("/api/v1/vendors/{$id}", ['display_name' => 'Acme Supplies Inc'], $this->h)
        ->assertStatus(200)
        ->assertJsonPath('data.display_name', 'Acme Supplies Inc');

    $this->deleteJson("/api/v1/vendors/{$id}", [], $this->h)->assertStatus(204);
    $this->getJson("/api/v1/vendors/{$id}", $this->h)->assertStatus(404);
});

it('does not surface a customer under the vendors role', function () {
    app()->instance('current_company', $this->company);
    $customer = Contact::create(['display_name' => 'Buyer', 'is_customer' => true]);
    app()->forgetInstance('current_company');

    $this->getJson("/api/v1/vendors/{$customer->id}", $this->h)->assertStatus(404);
    $this->getJson("/api/v1/customers/{$customer->id}", $this->h)->assertStatus(200);
});

it('forbids vendor writes with a purchases:read key', function () {
    ['plaintext' => $ro] = CompanyApiKey::mint($this->company, 'RO', null, ['purchases:read']);

    $this->getJson('/api/v1/vendors', ['Authorization' => "Bearer {$ro}"])->assertStatus(200);
    $this->postJson('/api/v1/vendors', ['display_name' => 'X'], ['Authorization' => "Bearer {$ro}"])->assertStatus(403);
});
