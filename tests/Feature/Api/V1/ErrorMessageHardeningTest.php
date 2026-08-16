<?php

use App\Enums\AccountSubtype;
use App\Enums\StockAdjustmentReason;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Item;

beforeEach(function () {
    $this->company = Company::factory()->create(['costing_method' => 'weighted_average']);
    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test');
    $this->h = ['Authorization' => "Bearer {$plain}"];

    app()->instance('current_company', $this->company);
    $this->inventoryAsset = Account::query()->where('subtype', AccountSubtype::Inventory->value)->first();
    $this->cogs = Account::query()->where('subtype', AccountSubtype::CostOfGoodsSold->value)->first();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->first();
    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->first();
    app()->forgetInstance('current_company');
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

it('returns a generic, client-safe message without item details when stock is insufficient', function () {
    app()->instance('current_company', $this->company);
    $item = Item::factory()->tracked()->create([
        'name' => 'Top Secret Widget',
        'sku' => 'SECRET-001',
        'inventory_asset_account_id' => $this->inventoryAsset->id,
        'cogs_account_id' => $this->cogs->id,
    ]);
    app()->forgetInstance('current_company');

    // Stock the item, then try to remove more than is on hand.
    $this->postJson('/api/v1/stock-adjustments', [
        'adjustment_date' => '2026-05-20',
        'reason' => StockAdjustmentReason::OpeningBalance->value,
        'lines' => [['item_id' => $item->id, 'qty_change' => '100', 'unit_cost_cents' => 500]],
    ], $this->h)->assertStatus(201);

    $response = $this->postJson('/api/v1/stock-adjustments', [
        'adjustment_date' => '2026-05-21',
        'reason' => StockAdjustmentReason::Shrinkage->value,
        'lines' => [['item_id' => $item->id, 'qty_change' => '-500', 'unit_cost_cents' => 500]],
    ], $this->h);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'Insufficient stock to complete this transaction.');

    expect($response->json('message'))
        ->not->toContain('Top Secret Widget')
        ->not->toContain('SECRET-001')
        ->not->toContain('on hand');
});

it('returns a client-safe message without balances when a journal entry does not balance', function () {
    $response = $this->postJson('/api/v1/journal-entries', [
        'entry_date' => '2026-05-20',
        'memo' => 'Unbalanced',
        'lines' => [
            ['account_id' => $this->expense->id, 'debit_cents' => 5000, 'credit_cents' => 0],
            ['account_id' => $this->bank->id, 'debit_cents' => 0, 'credit_cents' => 2500],
        ],
    ], $this->h);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'The journal entry does not balance.');

    // No raw cents, debit/credit figures, or accounting jargon leaked.
    expect($response->json('message'))
        ->not->toContain('debits')
        ->not->toContain('credits')
        ->not->toContain('2500')
        ->not->toContain('25');
});
