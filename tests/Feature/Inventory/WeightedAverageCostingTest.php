<?php

use App\Enums\AccountSubtype;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Services\Inventory\MovementContext;
use App\Services\Inventory\WeightedAverageCostingService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->company = Company::factory()->create(['costing_method' => 'weighted_average']);
    app()->instance('current_company', $this->company);

    $this->inventoryAsset = Account::query()->where('subtype', AccountSubtype::Inventory->value)->first();
    $this->cogs = Account::query()->where('subtype', AccountSubtype::CostOfGoodsSold->value)->first();

    $this->item = Item::factory()->tracked()->create([
        'inventory_asset_account_id' => $this->inventoryAsset->id,
        'cogs_account_id' => $this->cogs->id,
    ]);

    $this->wa = app(WeightedAverageCostingService::class);
    $this->today = CarbonImmutable::parse('2026-05-20');
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('records a receipt and sets the average to the unit cost when starting from zero', function () {
    $movement = $this->wa->recordReceipt($this->item, '100', 500, MovementContext::for($this->today));

    expect($movement->qty_change)->toEqual('100.0000');
    expect($movement->unit_cost_cents)->toBe(500);
    expect($movement->total_cost_cents)->toBe(50000);

    $this->item->refresh();
    expect((float) $this->item->qty_on_hand_cached)->toBe(100.0);
    expect($this->item->unit_cost_cents_cached)->toBe(500);
});

it('updates the weighted average across two receipts', function () {
    $this->wa->recordReceipt($this->item, '100', 500, MovementContext::for($this->today));
    $this->wa->recordReceipt($this->item, '50', 600, MovementContext::for($this->today));

    $this->item->refresh();
    expect((float) $this->item->qty_on_hand_cached)->toBe(150.0);
    expect($this->item->unit_cost_cents_cached)->toBe(533); // (100*500 + 50*600) / 150
});

it('records an issue at the current average cost and decrements qty', function () {
    $this->wa->recordReceipt($this->item, '100', 500, MovementContext::for($this->today));
    $this->wa->recordReceipt($this->item, '50', 600, MovementContext::for($this->today));

    $result = $this->wa->recordIssue($this->item, '120', MovementContext::for($this->today));

    expect($result['cost_cents'])->toBe(63960); // 120 * 533
    expect($result['movement']->unit_cost_cents)->toBe(533);
    expect($result['movement']->total_cost_cents)->toBe(-63960);
    expect((float) $result['movement']->qty_change)->toBe(-120.0);

    $this->item->refresh();
    expect((float) $this->item->qty_on_hand_cached)->toBe(30.0);
    expect($this->item->unit_cost_cents_cached)->toBe(533); // unchanged on issue
});

it('throws when issuing more than on hand', function () {
    $this->wa->recordReceipt($this->item, '10', 500, MovementContext::for($this->today));

    expect(fn () => $this->wa->recordIssue($this->item, '20', MovementContext::for($this->today)))
        ->toThrow(InsufficientStockException::class);

    $this->item->refresh();
    expect((float) $this->item->qty_on_hand_cached)->toBe(10.0);
});

it('reverses a receipt and recomputes the average', function () {
    $receipt1 = $this->wa->recordReceipt($this->item, '100', 500, MovementContext::for($this->today));
    $this->wa->recordReceipt($this->item, '50', 600, MovementContext::for($this->today));

    $this->wa->reverse($receipt1);

    $this->item->refresh();
    expect((float) $this->item->qty_on_hand_cached)->toBe(50.0);
    expect($this->item->unit_cost_cents_cached)->toBe(600);
});

it('reverses an issue and restores qty without disturbing the average', function () {
    $this->wa->recordReceipt($this->item, '100', 500, MovementContext::for($this->today));
    $issue = $this->wa->recordIssue($this->item, '40', MovementContext::for($this->today))['movement'];

    $this->wa->reverse($issue);

    $this->item->refresh();
    expect((float) $this->item->qty_on_hand_cached)->toBe(100.0);
    expect($this->item->unit_cost_cents_cached)->toBe(500);
});
