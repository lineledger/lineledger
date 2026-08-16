<?php

use App\Actions\Purchasing\FulfillPurchaseOrder;
use App\Actions\Purchasing\SavePurchaseOrder;
use App\Enums\AccountSubtype;
use App\Enums\BillStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Services\Posting\BillPoster;

beforeEach(function () {
    $this->company = Company::factory()->create(['costing_method' => 'weighted_average']);
    app()->instance('current_company', $this->company);

    $this->vendor = Contact::create(['display_name' => 'Acme Supply', 'is_vendor' => true]);
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();

    $this->poster = app(BillPoster::class);
});

afterEach(fn () => app()->forgetInstance('current_company'));

function openPurchaseOrder(int $qty = 10): PurchaseOrder
{
    return app(SavePurchaseOrder::class)->handle([
        'contact_id' => test()->vendor->id,
        'po_no' => null,
        'po_date' => test()->company->currentDateTime()->toDateString(),
        'expected_date' => null,
        'terms_id' => null,
        'memo' => null,
        'vendor_message' => null,
        'lines' => [[
            'item_id' => null,
            'account_id' => test()->expense->id,
            'description' => 'Widget',
            'quantity' => (string) $qty,
            'unit_price_cents' => 10000,
            'tax_code_id' => null,
        ]],
    ]);
}

it('creates a purchase order with an auto number and computed totals', function () {
    $order = openPurchaseOrder(10);

    expect($order->po_no)->toStartWith('PO')
        ->and($order->status)->toBe(PurchaseOrderStatus::Open)
        ->and($order->total_cents)->toBe(100000)
        ->and($order->lines)->toHaveCount(1);
});

it('partially fulfills an order into a linked draft bill', function () {
    $order = openPurchaseOrder(10);
    $line = $order->lines->first();

    $bill = app(FulfillPurchaseOrder::class)->handle($order, [$line->id => 4]);

    expect($bill->status)->toBe(BillStatus::Draft)
        ->and($bill->journal_entry_id)->toBeNull()
        ->and($bill->purchase_order_id)->toBe($order->id)
        ->and($bill->lines)->toHaveCount(1)
        ->and($bill->lines->first()->purchase_order_line_id)->toBe($line->id)
        ->and((float) $bill->lines->first()->quantity)->toBe(4.0);

    $order->refresh()->load('lines.billLines.bill');
    expect($order->effectiveStatus())->toBe(PurchaseOrderStatus::Partial)
        ->and((float) $order->lines->first()->qtyBilled())->toBe(4.0)
        ->and((float) $order->lines->first()->qtyBackordered())->toBe(6.0);
});

it('closes the order once the remaining quantity is billed', function () {
    $order = openPurchaseOrder(10);
    $line = $order->lines->first();

    app(FulfillPurchaseOrder::class)->handle($order, [$line->id => 4]);
    app(FulfillPurchaseOrder::class)->handle($order->fresh(), [$line->id => 6]);

    $order->refresh()->load('lines.billLines.bill');
    expect($order->effectiveStatus())->toBe(PurchaseOrderStatus::Closed)
        ->and((float) $order->lines->first()->qtyBackordered())->toBe(0.0)
        ->and($order->bills()->count())->toBe(2);
});

it('rejects billing more than the outstanding quantity', function () {
    $order = openPurchaseOrder(10);
    $line = $order->lines->first();

    expect(fn () => app(FulfillPurchaseOrder::class)->handle($order, [$line->id => 11]))
        ->toThrow(RuntimeException::class);

    expect($order->fresh()->load('lines.billLines.bill')->effectiveStatus())
        ->toBe(PurchaseOrderStatus::Open);
});

it('restores backordered quantity when a bill is voided', function () {
    $order = openPurchaseOrder(10);
    $line = $order->lines->first();

    $bill = app(FulfillPurchaseOrder::class)->handle($order, [$line->id => 4]);
    $this->poster->post($bill);

    $order->refresh()->load('lines.billLines.bill');
    expect($order->effectiveStatus())->toBe(PurchaseOrderStatus::Partial);

    $this->poster->void($bill->fresh());

    $order->refresh()->load('lines.billLines.bill');
    expect($order->effectiveStatus())->toBe(PurchaseOrderStatus::Open)
        ->and((float) $order->lines->first()->qtyBackordered())->toBe(10.0);
});

it('cannot fulfill a cancelled order', function () {
    $order = openPurchaseOrder(10);
    $line = $order->lines->first();
    $order->update(['status' => PurchaseOrderStatus::Cancelled]);

    expect(fn () => app(FulfillPurchaseOrder::class)->handle($order->fresh(), [$line->id => 1]))
        ->toThrow(RuntimeException::class);
});

it('receives stock when a tracked-item bill from a PO is posted', function () {
    $inventoryAsset = Account::query()->where('subtype', AccountSubtype::Inventory->value)->first();
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $cogs = Account::query()->where('subtype', AccountSubtype::CostOfGoodsSold->value)->first();

    $item = Item::factory()->tracked()->create([
        'income_account_id' => $income->id,
        'inventory_asset_account_id' => $inventoryAsset->id,
        'cogs_account_id' => $cogs->id,
    ]);

    $order = app(SavePurchaseOrder::class)->handle([
        'contact_id' => $this->vendor->id,
        'po_no' => null,
        'po_date' => $this->company->currentDateTime()->toDateString(),
        'expected_date' => null,
        'terms_id' => null,
        'memo' => null,
        'vendor_message' => null,
        'lines' => [[
            'item_id' => $item->id,
            'account_id' => $inventoryAsset->id,
            'description' => 'Tracked widget',
            'quantity' => '20',
            'unit_price_cents' => 500,
            'tax_code_id' => null,
        ]],
    ]);

    $bill = app(FulfillPurchaseOrder::class)->handle($order, [$order->lines->first()->id => 20]);
    $this->poster->post($bill);

    expect((float) $item->fresh()->qty_on_hand_cached)->toBe(20.0);
});
