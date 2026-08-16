<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Item;
use App\Models\TaxCode;

beforeEach(function () {
    $this->company = Company::factory()->create();
    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test');
    $this->h = ['Authorization' => "Bearer {$plain}"];

    app()->instance('current_company', $this->company);
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    app()->forgetInstance('current_company');
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

function itemPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Widget',
        'income_account_id' => test()->income->id,
        'default_price_cents' => 1500,
    ], $overrides);
}

it('lists items with pagination meta', function () {
    $this->postJson('/api/v1/items', itemPayload(), $this->h)->assertStatus(201);

    $this->getJson('/api/v1/items', $this->h)
        ->assertStatus(200)
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonPath('meta.total', 1);
});

it('shows a single item', function () {
    $id = $this->postJson('/api/v1/items', itemPayload(), $this->h)->json('data.id');

    $this->getJson("/api/v1/items/{$id}", $this->h)
        ->assertStatus(200)
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.name', 'Widget');
});

it('creates a non-inventory item', function () {
    $this->postJson('/api/v1/items', itemPayload(), $this->h)
        ->assertStatus(201)
        ->assertJsonPath('data.track_inventory', false)
        ->assertJsonPath('data.default_price_cents', 1500);
});

it('requires inventory accounts when tracking inventory', function () {
    $this->postJson('/api/v1/items', itemPayload(['track_inventory' => true]), $this->h)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['inventory_asset_account_id', 'cogs_account_id']);
});

it('creates a tracked item with an opening balance', function () {
    app()->instance('current_company', $this->company);
    $asset = Account::query()->where('subtype', AccountSubtype::Inventory->value)->first()
        ?? Account::create(['code' => '1499', 'name' => 'Inventory', 'subtype' => AccountSubtype::Inventory, 'type' => AccountType::Asset, 'normal_balance' => NormalBalance::Debit]);
    $cogs = Account::query()->where('subtype', AccountSubtype::CostOfGoodsSold->value)->first()
        ?? Account::create(['code' => '5099', 'name' => 'COGS', 'subtype' => AccountSubtype::CostOfGoodsSold, 'type' => AccountType::Expense, 'normal_balance' => NormalBalance::Debit]);
    app()->forgetInstance('current_company');

    $id = $this->postJson('/api/v1/items', itemPayload([
        'track_inventory' => true,
        'inventory_asset_account_id' => $asset->id,
        'cogs_account_id' => $cogs->id,
        'opening_qty' => 10,
        'opening_cost_cents' => 400,
    ]), $this->h)
        ->assertStatus(201)
        ->assertJsonPath('data.track_inventory', true)
        ->json('data.id');

    expect((float) Item::withoutGlobalScopes()->find($id)->qty_on_hand_cached)->toBe(10.0);
});

it('round-trips two default tax codes', function () {
    app()->instance('current_company', $this->company);
    [$first, $second] = TaxCode::query()->orderBy('id')->take(2)->pluck('id')->all();
    app()->forgetInstance('current_company');

    $id = $this->postJson('/api/v1/items', itemPayload([
        'default_tax_code_id' => $first,
        'default_secondary_tax_code_id' => $second,
    ]), $this->h)
        ->assertStatus(201)
        ->assertJsonPath('data.default_tax_code_id', $first)
        ->assertJsonPath('data.default_secondary_tax_code_id', $second)
        ->json('data.id');

    $this->getJson("/api/v1/items/{$id}", $this->h)
        ->assertJsonPath('data.default_secondary_tax_code_id', $second);
});

it('updates an item', function () {
    $id = $this->postJson('/api/v1/items', itemPayload(), $this->h)->json('data.id');

    $this->patchJson("/api/v1/items/{$id}", itemPayload(['name' => 'Gadget']), $this->h)
        ->assertStatus(200)
        ->assertJsonPath('data.name', 'Gadget');
});

it('round-trips a distinct expense (purchase) account', function () {
    app()->instance('current_company', $this->company);
    $expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->first();
    app()->forgetInstance('current_company');

    $id = $this->postJson('/api/v1/items', itemPayload(['expense_account_id' => $expense->id]), $this->h)
        ->assertStatus(201)
        ->assertJsonPath('data.expense_account_id', $expense->id)
        ->json('data.id');

    $this->getJson("/api/v1/items/{$id}", $this->h)
        ->assertJsonPath('data.expense_account_id', $expense->id)
        ->assertJsonPath('data.income_account_id', test()->income->id);
});

it('deletes an item', function () {
    $id = $this->postJson('/api/v1/items', itemPayload(), $this->h)->json('data.id');

    $this->deleteJson("/api/v1/items/{$id}", [], $this->h)->assertStatus(204);
    $this->getJson("/api/v1/items/{$id}", $this->h)->assertStatus(404);
});

it('returns 404 for another company\'s item', function () {
    $other = Company::factory()->create();
    ['plaintext' => $otherPlain] = CompanyApiKey::mint($other, 'Other');

    $id = $this->postJson('/api/v1/items', itemPayload(), $this->h)->json('data.id');

    $this->getJson("/api/v1/items/{$id}", ['Authorization' => "Bearer {$otherPlain}"])->assertStatus(404);
});

it('forbids writes with an inventory:read key', function () {
    ['plaintext' => $ro] = CompanyApiKey::mint($this->company, 'RO', null, ['inventory:read']);

    $this->getJson('/api/v1/items', ['Authorization' => "Bearer {$ro}"])->assertStatus(200);
    $this->postJson('/api/v1/items', itemPayload(), ['Authorization' => "Bearer {$ro}"])->assertStatus(403);
});
