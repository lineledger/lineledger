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

it('creates, lists, shows, updates and deletes a payment term', function () {
    $id = $this->postJson('/api/v1/payment-terms', ['name' => 'Net 45', 'days' => 45], $this->h)
        ->assertStatus(201)
        ->assertJsonPath('data.days', 45)
        ->json('data.id');

    $this->getJson('/api/v1/payment-terms', $this->h)
        ->assertStatus(200)
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonFragment(['id' => $id, 'name' => 'Net 45']);

    $this->getJson("/api/v1/payment-terms/{$id}", $this->h)->assertStatus(200)->assertJsonPath('data.name', 'Net 45');

    $this->patchJson("/api/v1/payment-terms/{$id}", ['name' => 'Net 60', 'days' => 60], $this->h)
        ->assertStatus(200)
        ->assertJsonPath('data.days', 60);

    $this->deleteJson("/api/v1/payment-terms/{$id}", [], $this->h)->assertStatus(204);
    $this->getJson("/api/v1/payment-terms/{$id}", $this->h)->assertStatus(404);
});

it('returns 404 for another company\'s payment term', function () {
    $other = Company::factory()->create();
    ['plaintext' => $otherPlain] = CompanyApiKey::mint($other, 'Other');

    $id = $this->postJson('/api/v1/payment-terms', ['name' => 'Net 45', 'days' => 45], $this->h)->json('data.id');

    $this->getJson("/api/v1/payment-terms/{$id}", ['Authorization' => "Bearer {$otherPlain}"])->assertStatus(404);
});

it('forbids writes with a settings:read key', function () {
    ['plaintext' => $ro] = CompanyApiKey::mint($this->company, 'RO', null, ['settings:read']);

    $this->getJson('/api/v1/payment-terms', ['Authorization' => "Bearer {$ro}"])->assertStatus(200);
    $this->postJson('/api/v1/payment-terms', ['name' => 'X', 'days' => 10], ['Authorization' => "Bearer {$ro}"])->assertStatus(403);
});
