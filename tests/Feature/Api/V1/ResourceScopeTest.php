<?php

use App\Models\Company;
use App\Models\CompanyApiKey;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

/**
 * Mint a scoped key and return its bearer header.
 *
 * @param  array<int, string>  $abilities
 * @return array<string, string>
 */
function scopedHeader(array $abilities): array
{
    ['plaintext' => $plain] = CompanyApiKey::mint(test()->company, 'Scoped', null, $abilities);

    return ['Authorization' => "Bearer {$plain}"];
}

it('grants a resource-write key access only to that resource', function () {
    $h = scopedHeader(['invoices:write']);

    // write implies read on the same resource
    $this->getJson('/api/v1/invoices', $h)->assertStatus(200);
    // a sibling resource in the same domain is NOT granted
    $this->getJson('/api/v1/receipts', $h)->assertStatus(403);
    $this->postJson('/api/v1/receipts', [], $h)->assertStatus(403);
});

it('forbids writes for a resource-read key', function () {
    $h = scopedHeader(['invoices:read']);

    $this->getJson('/api/v1/invoices', $h)->assertStatus(200);
    $this->postJson('/api/v1/invoices', ['x' => 'y'], $h)->assertStatus(403);
});

it('treats a domain scope as a superset of its resources', function () {
    $h = scopedHeader(['sales:write']);

    // sales:write covers every resource under the sales domain (read + write)
    $this->getJson('/api/v1/invoices', $h)->assertStatus(200);
    $this->getJson('/api/v1/receipts', $h)->assertStatus(200);
    $this->getJson('/api/v1/customers', $h)->assertStatus(200);
});

it('lets a domain-read scope read but not write its resources', function () {
    $h = scopedHeader(['sales:read']);

    $this->getJson('/api/v1/invoices', $h)->assertStatus(200);
    $this->postJson('/api/v1/invoices', ['x' => 'y'], $h)->assertStatus(403);
});

it('does not bleed a scope across domains', function () {
    $h = scopedHeader(['purchases:write']);

    $this->getJson('/api/v1/invoices', $h)->assertStatus(403);
});

it('resolves the ability matrix on the model', function () {
    ['key' => $resourceKey] = CompanyApiKey::mint($this->company, 'R', null, ['invoices:write']);

    expect($resourceKey->hasAbility('invoices:write'))->toBeTrue()
        ->and($resourceKey->hasAbility('invoices:read'))->toBeTrue()   // write implies read
        ->and($resourceKey->hasAbility('receipts:write'))->toBeFalse() // sibling resource
        ->and($resourceKey->hasAbility('sales:write'))->toBeFalse();   // does not imply the whole domain

    ['key' => $domainKey] = CompanyApiKey::mint($this->company, 'D', null, ['sales:write']);

    expect($domainKey->hasAbility('invoices:write'))->toBeTrue()  // domain is a superset
        ->and($domainKey->hasAbility('customers:read'))->toBeTrue()
        ->and($domainKey->hasAbility('bills:write'))->toBeFalse(); // other domain

    ['key' => $fullKey] = CompanyApiKey::mint($this->company, 'Full');

    expect($fullKey->hasAbility('invoices:write'))->toBeTrue()
        ->and($fullKey->hasAbility('bills:write'))->toBeTrue();
});
