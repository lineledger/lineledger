<?php

use App\Models\Company;
use App\Models\CompanyApiKey;

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

it('rejects requests without an API key', function () {
    $this->postJson('/api/v1/customers', ['display_name' => 'Acme'])
        ->assertStatus(401)
        ->assertJson(['message' => 'Missing API key']);
});

it('rejects requests with an unknown bearer token', function () {
    $this->postJson('/api/v1/customers', ['display_name' => 'Acme'], [
        'Authorization' => 'Bearer ll_live_doesnotexist',
    ])
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key']);
});

it('rejects requests using a revoked key', function () {
    $company = Company::factory()->create();
    ['plaintext' => $plain, 'key' => $key] = CompanyApiKey::mint($company, 'Test');
    $key->revoke();

    $this->postJson('/api/v1/customers', ['display_name' => 'Acme'], [
        'Authorization' => "Bearer {$plain}",
    ])->assertStatus(401);
});

it('accepts the X-Api-Key header as a fallback', function () {
    $company = Company::factory()->create();
    ['plaintext' => $plain] = CompanyApiKey::mint($company, 'Test');

    $this->postJson('/api/v1/customers', ['display_name' => 'Acme'], [
        'X-Api-Key' => $plain,
    ])->assertStatus(201);
});

it('rejects an expired key with the generic invalid message', function () {
    $company = Company::factory()->create();
    ['plaintext' => $plain] = CompanyApiKey::mint($company, 'Test', expiresAt: now()->subDay());

    $this->postJson('/api/v1/customers', ['display_name' => 'Acme'], [
        'Authorization' => "Bearer {$plain}",
    ])
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key']);
});

it('accepts a key whose expiry is in the future', function () {
    $company = Company::factory()->create();
    ['plaintext' => $plain] = CompanyApiKey::mint($company, 'Test', expiresAt: now()->addDays(30));

    $this->postJson('/api/v1/customers', ['display_name' => 'Acme'], [
        'Authorization' => "Bearer {$plain}",
    ])->assertStatus(201);
});

it('reports isActive/isExpired correctly across the matrix', function () {
    $company = Company::factory()->create();

    ['key' => $never] = CompanyApiKey::mint($company, 'Never');
    ['key' => $future] = CompanyApiKey::mint($company, 'Future', expiresAt: now()->addDay());
    ['key' => $past] = CompanyApiKey::mint($company, 'Past', expiresAt: now()->subDay());

    expect($never->isActive())->toBeTrue()->and($never->isExpired())->toBeFalse()
        ->and($future->isActive())->toBeTrue()->and($future->isExpired())->toBeFalse()
        ->and($past->isActive())->toBeFalse()->and($past->isExpired())->toBeTrue();
});

it('updates last_used_at on a successful request', function () {
    $company = Company::factory()->create();
    ['plaintext' => $plain, 'key' => $key] = CompanyApiKey::mint($company, 'Test');

    expect($key->last_used_at)->toBeNull();

    $this->postJson('/api/v1/customers', ['display_name' => 'Acme'], [
        'Authorization' => "Bearer {$plain}",
    ])->assertStatus(201);

    expect($key->fresh()->last_used_at)->not->toBeNull();
});
