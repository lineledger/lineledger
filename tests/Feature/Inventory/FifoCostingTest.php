<?php

use App\Enums\AccountSubtype;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Models\StockLayer;
use App\Services\Inventory\FifoCostingService;
use App\Services\Inventory\MovementContext;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->company = Company::factory()->create(['costing_method' => 'fifo']);
    app()->instance('current_company', $this->company);

    $this->inventoryAsset = Account::query()->where('subtype', AccountSubtype::Inventory->value)->first();
    $this->cogs = Account::query()->where('subtype', AccountSubtype::CostOfGoodsSold->value)->first();

    $this->item = Item::factory()->tracked()->create([
        'inventory_asset_account_id' => $this->inventoryAsset->id,
        'cogs_account_id' => $this->cogs->id,
    ]);

    $this->fifo = app(FifoCostingService::class);
    $this->today = CarbonImmutable::parse('2026-05-20');
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('creates a layer per receipt and tracks total value', function () {
    $this->fifo->recordReceipt($this->item, '100', 500, MovementContext::for($this->today));
    $this->fifo->recordReceipt($this->item, '50', 600, MovementContext::for($this->today));

    expect(StockLayer::query()->where('item_id', $this->item->id)->count())->toBe(2);

    $this->item->refresh();
    expect((float) $this->item->qty_on_hand_cached)->toBe(150.0);
    expect($this->item->unit_cost_cents_cached)->toBe(533); // weighted across layers for display
});

it('issues from oldest layers first and records consumption', function () {
    $this->fifo->recordReceipt($this->item, '100', 500, MovementContext::for($this->today));
    $this->fifo->recordReceipt($this->item, '50', 600, MovementContext::for($this->today));

    $result = $this->fifo->recordIssue($this->item, '120', MovementContext::for($this->today));

    expect($result['cost_cents'])->toBe(62000); // 100*500 + 20*600

    $movement = $result['movement'];
    expect($movement->consumed_layers)->toHaveCount(2);
    expect((int) $movement->consumed_layers[0]['cost_cents'])->toBe(50000);
    expect((int) $movement->consumed_layers[1]['cost_cents'])->toBe(12000);

    $remaining = StockLayer::query()
        ->where('item_id', $this->item->id)
        ->orderBy('id')
        ->get();

    expect((float) $remaining[0]->qty_remaining)->toBe(0.0);
    expect((float) $remaining[1]->qty_remaining)->toBe(30.0);
});

it('throws when issuing more than total layer qty', function () {
    $this->fifo->recordReceipt($this->item, '10', 500, MovementContext::for($this->today));

    expect(fn () => $this->fifo->recordIssue($this->item, '20', MovementContext::for($this->today)))
        ->toThrow(InsufficientStockException::class);
});

it('reverses an issue and restores each consumed layer', function () {
    $this->fifo->recordReceipt($this->item, '100', 500, MovementContext::for($this->today));
    $this->fifo->recordReceipt($this->item, '50', 600, MovementContext::for($this->today));

    $issue = $this->fifo->recordIssue($this->item, '120', MovementContext::for($this->today))['movement'];

    $this->fifo->reverse($issue);

    $layers = StockLayer::query()->where('item_id', $this->item->id)->orderBy('id')->get();
    expect((float) $layers[0]->qty_remaining)->toBe(100.0);
    expect((float) $layers[1]->qty_remaining)->toBe(50.0);

    $this->item->refresh();
    expect((float) $this->item->qty_on_hand_cached)->toBe(150.0);
});

it('reverses a receipt by deleting its layer when not consumed', function () {
    $receipt = $this->fifo->recordReceipt($this->item, '100', 500, MovementContext::for($this->today));

    $this->fifo->reverse($receipt);

    expect(StockLayer::query()->where('stock_movement_id', $receipt->id)->exists())->toBeFalse();

    $this->item->refresh();
    expect((float) $this->item->qty_on_hand_cached)->toBe(0.0);
});

it('refuses to reverse a receipt whose layer has been consumed', function () {
    $receipt = $this->fifo->recordReceipt($this->item, '100', 500, MovementContext::for($this->today));
    $this->fifo->recordIssue($this->item, '10', MovementContext::for($this->today));

    expect(fn () => $this->fifo->reverse($receipt))
        ->toThrow(RuntimeException::class, 'partially consumed');
});
