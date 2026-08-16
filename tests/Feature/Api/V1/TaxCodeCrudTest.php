<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;

beforeEach(function () {
    $this->company = Company::factory()->create();
    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test');
    $this->h = ['Authorization' => "Bearer {$plain}"];

    app()->instance('current_company', $this->company);
    $this->payable = Account::query()->where('subtype', AccountSubtype::TaxPayable->value)->first()
        ?? Account::create(['code' => '2199', 'name' => 'Tax Payable', 'subtype' => AccountSubtype::TaxPayable, 'type' => AccountType::Liability, 'normal_balance' => NormalBalance::Credit]);
    app()->forgetInstance('current_company');
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

function agencyPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'CRA',
        'payable_account_id' => test()->payable->id,
    ], $overrides);
}

function taxCodePayload(array $overrides = []): array
{
    return array_merge([
        'code' => 'HST',
        'name' => 'Harmonized Sales Tax',
        'rate_basis_points' => 1300,
        'applies_to' => 'both',
    ], $overrides);
}

it('lists tax codes with pagination meta', function () {
    $id = $this->postJson('/api/v1/tax-codes', taxCodePayload(), $this->h)->assertStatus(201)->json('data.id');

    $this->getJson('/api/v1/tax-codes', $this->h)
        ->assertStatus(200)
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonFragment(['id' => $id, 'code' => 'HST']);
});

it('creates, shows, updates and deletes a tax code', function () {
    $id = $this->postJson('/api/v1/tax-codes', taxCodePayload(), $this->h)
        ->assertStatus(201)
        ->assertJsonPath('data.rate_basis_points', 1300)
        ->json('data.id');

    $this->getJson("/api/v1/tax-codes/{$id}", $this->h)->assertStatus(200)->assertJsonPath('data.code', 'HST');

    $this->patchJson("/api/v1/tax-codes/{$id}", taxCodePayload(['name' => 'HST 13%']), $this->h)
        ->assertStatus(200)
        ->assertJsonPath('data.name', 'HST 13%');

    $this->deleteJson("/api/v1/tax-codes/{$id}", [], $this->h)->assertStatus(204);
    $this->getJson("/api/v1/tax-codes/{$id}", $this->h)->assertStatus(404);
});

it('accepts and round-trips a fractional basis-point rate (QST 9.975%)', function () {
    $this->postJson('/api/v1/tax-codes', taxCodePayload([
        'code' => 'QST',
        'name' => 'QST 9.975%',
        'rate_basis_points' => 997.5,
    ]), $this->h)
        ->assertStatus(201)
        ->assertJsonPath('data.rate_basis_points', 997.5);
});

it('rejects a duplicate tax code', function () {
    $this->postJson('/api/v1/tax-codes', taxCodePayload(), $this->h)->assertStatus(201);
    $this->postJson('/api/v1/tax-codes', taxCodePayload(), $this->h)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code']);
});

it('returns 404 for another company\'s tax code', function () {
    $other = Company::factory()->create();
    ['plaintext' => $otherPlain] = CompanyApiKey::mint($other, 'Other');

    $id = $this->postJson('/api/v1/tax-codes', taxCodePayload(), $this->h)->json('data.id');

    $this->getJson("/api/v1/tax-codes/{$id}", ['Authorization' => "Bearer {$otherPlain}"])->assertStatus(404);
});

it('forbids tax-code writes with a tax:read key', function () {
    ['plaintext' => $ro] = CompanyApiKey::mint($this->company, 'RO', null, ['tax:read']);

    $this->getJson('/api/v1/tax-codes', ['Authorization' => "Bearer {$ro}"])->assertStatus(200);
    $this->postJson('/api/v1/tax-codes', taxCodePayload(), ['Authorization' => "Bearer {$ro}"])->assertStatus(403);
});

it('creates, lists, shows, updates and deletes a tax agency', function () {
    $id = $this->postJson('/api/v1/tax-agencies', agencyPayload(), $this->h)
        ->assertStatus(201)
        ->assertJsonPath('data.name', 'CRA')
        ->json('data.id');

    $this->getJson('/api/v1/tax-agencies', $this->h)->assertStatus(200)->assertJsonFragment(['id' => $id, 'name' => 'CRA']);
    $this->getJson("/api/v1/tax-agencies/{$id}", $this->h)->assertStatus(200)->assertJsonPath('data.id', $id);

    $this->patchJson("/api/v1/tax-agencies/{$id}", agencyPayload(['name' => 'Canada Revenue Agency']), $this->h)
        ->assertStatus(200)
        ->assertJsonPath('data.name', 'Canada Revenue Agency');

    $this->deleteJson("/api/v1/tax-agencies/{$id}", [], $this->h)->assertStatus(204);
    $this->getJson("/api/v1/tax-agencies/{$id}", $this->h)->assertStatus(404);
});

it('refuses to delete a tax agency that has codes (conflict)', function () {
    $agencyId = $this->postJson('/api/v1/tax-agencies', agencyPayload(), $this->h)->json('data.id');
    $this->postJson('/api/v1/tax-codes', taxCodePayload(['agency_id' => $agencyId]), $this->h)->assertStatus(201);

    $this->deleteJson("/api/v1/tax-agencies/{$agencyId}", [], $this->h)->assertStatus(409);
});

it('forbids tax-agency writes with a tax:read key', function () {
    ['plaintext' => $ro] = CompanyApiKey::mint($this->company, 'RO', null, ['tax:read']);

    $this->getJson('/api/v1/tax-agencies', ['Authorization' => "Bearer {$ro}"])->assertStatus(200);
    $this->postJson('/api/v1/tax-agencies', agencyPayload(), ['Authorization' => "Bearer {$ro}"])->assertStatus(403);
});
