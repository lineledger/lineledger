<?php

use App\Models\Company;
use App\Models\CompanyApiKey;

beforeEach(function () {
    $this->company = Company::factory()->create();
    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test');
    $this->plain = $plain;
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

function assetCategoryAuthHeader(): array
{
    return ['Authorization' => 'Bearer '.test()->plain];
}

it('lists asset categories with pagination meta', function () {
    $this->postJson('/api/v1/asset-categories', ['name' => 'Vehicles'], assetCategoryAuthHeader())->assertStatus(201);

    $this->getJson('/api/v1/asset-categories', assetCategoryAuthHeader())
        ->assertStatus(200)
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonPath('meta.total', 1);
});

it('creates an asset category', function () {
    $this->postJson('/api/v1/asset-categories', [
        'name' => 'Equipment',
        'default_useful_life_months' => 60,
    ], assetCategoryAuthHeader())
        ->assertStatus(201)
        ->assertJsonPath('data.name', 'Equipment')
        ->assertJsonPath('data.default_useful_life_months', 60)
        ->assertJsonPath('data.is_active', true);
});

it('shows a single asset category', function () {
    $id = $this->postJson('/api/v1/asset-categories', ['name' => 'Furniture'], assetCategoryAuthHeader())->json('data.id');

    $this->getJson("/api/v1/asset-categories/{$id}", assetCategoryAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.id', $id);
});

it('updates an asset category', function () {
    $id = $this->postJson('/api/v1/asset-categories', ['name' => 'Tools'], assetCategoryAuthHeader())->json('data.id');

    $this->patchJson("/api/v1/asset-categories/{$id}", [
        'name' => 'Hand tools',
        'is_active' => false,
    ], assetCategoryAuthHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.name', 'Hand tools')
        ->assertJsonPath('data.is_active', false);
});

it('rejects a duplicate name', function () {
    $this->postJson('/api/v1/asset-categories', ['name' => 'Vehicles'], assetCategoryAuthHeader())->assertStatus(201);

    $this->postJson('/api/v1/asset-categories', ['name' => 'Vehicles'], assetCategoryAuthHeader())
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
});

it('deletes an asset category', function () {
    $id = $this->postJson('/api/v1/asset-categories', ['name' => 'Misc'], assetCategoryAuthHeader())->json('data.id');

    $this->deleteJson("/api/v1/asset-categories/{$id}", [], assetCategoryAuthHeader())->assertStatus(204);

    $this->getJson("/api/v1/asset-categories/{$id}", assetCategoryAuthHeader())->assertStatus(404);
});

it('returns 404 for another company\'s asset category', function () {
    $other = Company::factory()->create();
    ['plaintext' => $otherPlain] = CompanyApiKey::mint($other, 'Other');

    $id = $this->postJson('/api/v1/asset-categories', ['name' => 'Vehicles'], assetCategoryAuthHeader())->json('data.id');

    $this->getJson("/api/v1/asset-categories/{$id}", ['Authorization' => "Bearer {$otherPlain}"])
        ->assertStatus(404);
});

it('forbids writes with a read-only key', function () {
    ['plaintext' => $readPlain] = CompanyApiKey::mint($this->company, 'Read', null, ['accounting:read']);

    $this->getJson('/api/v1/asset-categories', ['Authorization' => "Bearer {$readPlain}"])->assertStatus(200);

    $this->postJson('/api/v1/asset-categories', ['name' => 'Nope'], ['Authorization' => "Bearer {$readPlain}"])
        ->assertStatus(403);
});
