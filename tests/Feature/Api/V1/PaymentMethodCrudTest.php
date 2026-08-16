<?php

use App\Models\Company;
use App\Models\CompanyApiKey;

beforeEach(function () {
    $this->company = Company::factory()->create();
    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test');
    $this->h = ['Authorization' => "Bearer {$plain}"];
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

it('creates, lists, shows, updates and deletes a payment method', function () {
    $id = $this->postJson('/api/v1/payment-methods', ['name' => 'Cheque', 'is_cheque' => true], $this->h)
        ->assertStatus(201)
        ->assertJsonPath('data.is_cheque', true)
        ->json('data.id');

    $this->getJson('/api/v1/payment-methods', $this->h)
        ->assertStatus(200)
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonFragment(['id' => $id, 'name' => 'Cheque']);

    $this->getJson("/api/v1/payment-methods/{$id}", $this->h)->assertStatus(200)->assertJsonPath('data.name', 'Cheque');

    $this->patchJson("/api/v1/payment-methods/{$id}", ['name' => 'Wire', 'is_cheque' => false], $this->h)
        ->assertStatus(200)
        ->assertJsonPath('data.is_cheque', false);

    $this->deleteJson("/api/v1/payment-methods/{$id}", [], $this->h)->assertStatus(204);
    $this->getJson("/api/v1/payment-methods/{$id}", $this->h)->assertStatus(404);
});

it('returns 404 for another company\'s payment method', function () {
    $other = Company::factory()->create();
    ['plaintext' => $otherPlain] = CompanyApiKey::mint($other, 'Other');

    $id = $this->postJson('/api/v1/payment-methods', ['name' => 'Cash'], $this->h)->json('data.id');

    $this->getJson("/api/v1/payment-methods/{$id}", ['Authorization' => "Bearer {$otherPlain}"])->assertStatus(404);
});

it('forbids writes with a settings:read key', function () {
    ['plaintext' => $ro] = CompanyApiKey::mint($this->company, 'RO', null, ['settings:read']);

    $this->getJson('/api/v1/payment-methods', ['Authorization' => "Bearer {$ro}"])->assertStatus(200);
    $this->postJson('/api/v1/payment-methods', ['name' => 'X'], ['Authorization' => "Bearer {$ro}"])->assertStatus(403);
});
