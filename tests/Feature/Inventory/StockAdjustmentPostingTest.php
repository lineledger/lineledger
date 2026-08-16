<?php

use App\Enums\AccountSubtype;
use App\Enums\StockAdjustmentReason;
use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Services\Posting\StockAdjustmentPoster;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->company = Company::factory()->create(['costing_method' => 'weighted_average']);
    app()->instance('current_company', $this->company);

    $this->inventoryAsset = Account::query()->where('subtype', AccountSubtype::Inventory->value)->first();
    $this->cogs = Account::query()->where('subtype', AccountSubtype::CostOfGoodsSold->value)->first();
    $this->openingEquity = Account::query()->where('name', 'Opening Balance Equity')->first();

    $this->item = Item::factory()->tracked()->create([
        'inventory_asset_account_id' => $this->inventoryAsset->id,
        'cogs_account_id' => $this->cogs->id,
    ]);

    $this->poster = app(StockAdjustmentPoster::class);
    $this->today = CarbonImmutable::parse('2026-05-20');
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function makeAdjustment(StockAdjustmentReason $reason, array $lines, CarbonImmutable $date): StockAdjustment
{
    $adjustment = StockAdjustment::create([
        'adjustment_no' => 'ADJ-'.uniqid(),
        'adjustment_date' => $date,
        'reason' => $reason,
    ]);

    foreach ($lines as $i => $line) {
        $adjustment->lines()->create([
            'item_id' => $line['item_id'],
            'qty_change' => $line['qty_change'],
            'unit_cost_cents' => $line['unit_cost_cents'] ?? 0,
            'line_order' => $i,
        ]);
    }

    return $adjustment->fresh('lines.item');
}

it('posts an opening-balance adjustment with DR Inventory / CR Opening Balance Equity', function () {
    $adjustment = makeAdjustment(
        StockAdjustmentReason::OpeningBalance,
        [['item_id' => $this->item->id, 'qty_change' => '100', 'unit_cost_cents' => 500]],
        $this->today,
    );

    $entry = $this->poster->post($adjustment);

    expect($entry->isBalanced())->toBeTrue();
    expect($entry->totalDebitsCents())->toBe(50000);

    $dr = $entry->lines->firstWhere('account_id', $this->inventoryAsset->id);
    $cr = $entry->lines->firstWhere('account_id', $this->openingEquity->id);

    expect($dr->debit_cents)->toBe(50000);
    expect($cr->credit_cents)->toBe(50000);

    $this->item->refresh();
    expect((float) $this->item->qty_on_hand_cached)->toBe(100.0);
    expect($this->item->unit_cost_cents_cached)->toBe(500);
});

it('posts a positive non-opening adjustment to DR Inventory / CR COGS', function () {
    // First seed with an opening balance so we have stock to play with cost-wise (not required for receipts but realistic).
    $this->poster->post(makeAdjustment(
        StockAdjustmentReason::OpeningBalance,
        [['item_id' => $this->item->id, 'qty_change' => '100', 'unit_cost_cents' => 500]],
        $this->today,
    ));

    $adj = makeAdjustment(
        StockAdjustmentReason::Recount,
        [['item_id' => $this->item->id, 'qty_change' => '5', 'unit_cost_cents' => 500]],
        $this->today,
    );

    $entry = $this->poster->post($adj);
    $dr = $entry->lines->firstWhere('account_id', $this->inventoryAsset->id);
    $cr = $entry->lines->firstWhere('account_id', $this->cogs->id);

    expect($dr->debit_cents)->toBe(2500);
    expect($cr->credit_cents)->toBe(2500);
});

it('posts a negative adjustment (shrinkage) to DR COGS / CR Inventory', function () {
    $this->poster->post(makeAdjustment(
        StockAdjustmentReason::OpeningBalance,
        [['item_id' => $this->item->id, 'qty_change' => '100', 'unit_cost_cents' => 500]],
        $this->today,
    ));

    $adj = makeAdjustment(
        StockAdjustmentReason::Shrinkage,
        [['item_id' => $this->item->id, 'qty_change' => '-3']],
        $this->today,
    );

    $entry = $this->poster->post($adj);

    $dr = $entry->lines->firstWhere('account_id', $this->cogs->id);
    $cr = $entry->lines->firstWhere('account_id', $this->inventoryAsset->id);

    expect($dr->debit_cents)->toBe(1500); // 3 * 500
    expect($cr->credit_cents)->toBe(1500);

    $this->item->refresh();
    expect((float) $this->item->qty_on_hand_cached)->toBe(97.0);
});

it('voids a stock adjustment and reverses its movements', function () {
    $adj = makeAdjustment(
        StockAdjustmentReason::OpeningBalance,
        [['item_id' => $this->item->id, 'qty_change' => '50', 'unit_cost_cents' => 400]],
        $this->today,
    );

    $this->poster->post($adj);

    $this->item->refresh();
    expect((float) $this->item->qty_on_hand_cached)->toBe(50.0);

    $this->poster->void($adj->fresh());

    $this->item->refresh();
    expect((float) $this->item->qty_on_hand_cached)->toBe(0.0);

    // Original movement + reversal movement.
    expect(StockMovement::query()->where('item_id', $this->item->id)->count())->toBe(2);
});

it('refuses to post when an item is not tracked', function () {
    $service = Item::factory()->create(['track_inventory' => false]);

    $adj = makeAdjustment(
        StockAdjustmentReason::Recount,
        [['item_id' => $service->id, 'qty_change' => '5', 'unit_cost_cents' => 100]],
        $this->today,
    );

    expect(fn () => $this->poster->post($adj))
        ->toThrow(RuntimeException::class, 'not tracked');
});
