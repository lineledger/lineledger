<?php

use App\Enums\AccountSubtype;
use App\Enums\BillStatus;
use App\Enums\BillType;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Item;
use App\Models\StockMovement;
use App\Services\Posting\BillPoster;

beforeEach(function () {
    $this->company = Company::factory()->create(['costing_method' => 'weighted_average']);
    app()->instance('current_company', $this->company);

    $this->vendor = Contact::create(['display_name' => 'Widget Supply Co', 'is_vendor' => true]);
    $this->inventoryAsset = Account::query()->where('subtype', AccountSubtype::Inventory->value)->first();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
    $this->cogs = Account::query()->where('subtype', AccountSubtype::CostOfGoodsSold->value)->first();
    $this->ap = Account::query()->where('subtype', AccountSubtype::AccountsPayable->value)->where('is_system', true)->first();

    $this->tracked = Item::factory()->tracked()->create([
        'inventory_asset_account_id' => $this->inventoryAsset->id,
        'cogs_account_id' => $this->cogs->id,
    ]);
    $this->service = Item::factory()->create();

    $this->poster = app(BillPoster::class);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function makeInventoryBill(array $lines): Bill
{
    $bill = Bill::create([
        'contact_id' => test()->vendor->id,
        'bill_type' => BillType::Vendor,
        'bill_no' => 'BILL-'.uniqid(),
        'bill_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);

    foreach ($lines as $i => $line) {
        $bill->lines()->create([
            'item_id' => $line['item_id'] ?? null,
            'account_id' => $line['account_id'],
            'description' => $line['description'] ?? 'line',
            'quantity' => $line['quantity'],
            'unit_price_cents' => $line['unit_price_cents'],
            'tax_code_id' => null,
            'line_subtotal_cents' => $line['line_subtotal_cents'],
            'line_tax_cents' => 0,
            'line_total_cents' => $line['line_subtotal_cents'],
            'line_order' => $i,
        ]);
    }

    return $bill->fresh('lines.item');
}

it('debits the inventory asset account (not expense) for tracked items, and creates a stock receipt', function () {
    $bill = makeInventoryBill([
        [
            'item_id' => $this->tracked->id,
            'account_id' => $this->expense->id, // user-selected, should be overridden
            'quantity' => '50',
            'unit_price_cents' => 600,
            'line_subtotal_cents' => 30000,
        ],
    ]);

    $this->poster->post($bill);
    $bill->refresh();

    expect($bill->status)->toBe(BillStatus::Posted);

    // Inventory Asset got the DR, not the expense account.
    expect($this->inventoryAsset->fresh()->balance_cents)->toBe(30000);
    expect($this->expense->fresh()->balance_cents)->toBe(0);
    expect($this->ap->fresh()->balance_cents)->toBe(30000);

    $this->tracked->refresh();
    expect((float) $this->tracked->qty_on_hand_cached)->toBe(50.0);
    expect($this->tracked->unit_cost_cents_cached)->toBe(600);

    expect(StockMovement::query()->where('item_id', $this->tracked->id)->count())->toBe(1);
});

it('keeps a non-tracked item line on its original expense account', function () {
    $bill = makeInventoryBill([
        [
            'item_id' => $this->service->id,
            'account_id' => $this->expense->id,
            'quantity' => '1',
            'unit_price_cents' => 10000,
            'line_subtotal_cents' => 10000,
        ],
    ]);

    $this->poster->post($bill);

    expect($this->expense->fresh()->balance_cents)->toBe(10000);
    expect($this->inventoryAsset->fresh()->balance_cents)->toBe(0);
    expect(StockMovement::query()->count())->toBe(0);
});

it('reposting a bill reverses old stock movements and creates new ones', function () {
    $bill = makeInventoryBill([
        [
            'item_id' => $this->tracked->id,
            'account_id' => $this->expense->id,
            'quantity' => '10',
            'unit_price_cents' => 500,
            'line_subtotal_cents' => 5000,
        ],
    ]);

    $this->poster->post($bill);
    $this->tracked->refresh();
    expect((float) $this->tracked->qty_on_hand_cached)->toBe(10.0);

    // Edit the bill — increase qty.
    $bill->lines()->first()->update([
        'quantity' => '20',
        'line_subtotal_cents' => 10000,
        'line_total_cents' => 10000,
    ]);

    $this->poster->repost($bill->fresh('lines.item'));

    $this->tracked->refresh();
    expect((float) $this->tracked->qty_on_hand_cached)->toBe(20.0);

    // Original movement + reversal + new movement.
    expect(StockMovement::query()->where('item_id', $this->tracked->id)->count())->toBe(3);
});

it('voiding a bill reverses stock movements', function () {
    $bill = makeInventoryBill([
        [
            'item_id' => $this->tracked->id,
            'account_id' => $this->expense->id,
            'quantity' => '5',
            'unit_price_cents' => 1000,
            'line_subtotal_cents' => 5000,
        ],
    ]);

    $this->poster->post($bill);
    $this->tracked->refresh();
    expect((float) $this->tracked->qty_on_hand_cached)->toBe(5.0);

    $this->poster->void($bill->fresh());

    $this->tracked->refresh();
    expect((float) $this->tracked->qty_on_hand_cached)->toBe(0.0);

    expect(StockMovement::query()->where('item_id', $this->tracked->id)->count())->toBe(2);
});
