<?php

use App\Actions\Sales\FulfillSalesOrder;
use App\Actions\Sales\SaveSalesOrder;
use App\Enums\AccountSubtype;
use App\Enums\InvoiceStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\StockAdjustmentReason;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Item;
use App\Models\SalesOrder;
use App\Models\StockAdjustment;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\StockAdjustmentPoster;

beforeEach(function () {
    $this->company = Company::factory()->create(['costing_method' => 'weighted_average']);
    app()->instance('current_company', $this->company);

    $this->customer = Contact::create(['display_name' => 'Acme Corp', 'is_customer' => true]);
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    $this->poster = app(InvoicePoster::class);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function openSalesOrder(int $qty = 10): SalesOrder
{
    return app(SaveSalesOrder::class)->handle([
        'contact_id' => test()->customer->id,
        'order_no' => null,
        'order_date' => test()->company->currentDateTime()->toDateString(),
        'expected_date' => null,
        'terms_id' => null,
        'memo' => null,
        'customer_message' => null,
        'lines' => [[
            'item_id' => null,
            'account_id' => test()->income->id,
            'description' => 'Widget',
            'quantity' => (string) $qty,
            'unit_price_cents' => 10000,
            'tax_code_id' => null,
        ]],
    ]);
}

it('partially fulfills an order into a linked draft invoice', function () {
    $order = openSalesOrder(10);
    $line = $order->lines->first();

    $invoice = app(FulfillSalesOrder::class)->handle($order, [$line->id => 4]);

    expect($invoice->status)->toBe(InvoiceStatus::Draft);
    expect($invoice->journal_entry_id)->toBeNull();
    expect($invoice->sales_order_id)->toBe($order->id);
    expect($invoice->lines)->toHaveCount(1);
    expect($invoice->lines->first()->sales_order_line_id)->toBe($line->id);
    expect((float) $invoice->lines->first()->quantity)->toBe(4.0);

    $order->refresh()->load('lines.invoiceLines.invoice');
    expect($order->effectiveStatus())->toBe(SalesOrderStatus::Partial);
    expect((float) $order->lines->first()->qtyInvoiced())->toBe(4.0);
    expect((float) $order->lines->first()->qtyBackordered())->toBe(6.0);
});

it('closes the order once the remaining quantity is fulfilled', function () {
    $order = openSalesOrder(10);
    $line = $order->lines->first();

    app(FulfillSalesOrder::class)->handle($order, [$line->id => 4]);
    app(FulfillSalesOrder::class)->handle($order->fresh(), [$line->id => 6]);

    $order->refresh()->load('lines.invoiceLines.invoice');
    expect($order->effectiveStatus())->toBe(SalesOrderStatus::Closed);
    expect((float) $order->lines->first()->qtyBackordered())->toBe(0.0);
    expect($order->invoices()->count())->toBe(2);
});

it('rejects invoicing more than the outstanding quantity', function () {
    $order = openSalesOrder(10);
    $line = $order->lines->first();

    expect(fn () => app(FulfillSalesOrder::class)->handle($order, [$line->id => 11]))
        ->toThrow(RuntimeException::class);

    expect($order->fresh()->load('lines.invoiceLines.invoice')->effectiveStatus())
        ->toBe(SalesOrderStatus::Open);
});

it('rejects a fulfillment with no positive quantities', function () {
    $order = openSalesOrder(10);
    $line = $order->lines->first();

    expect(fn () => app(FulfillSalesOrder::class)->handle($order, [$line->id => 0]))
        ->toThrow(RuntimeException::class);
});

it('restores backordered quantity when a fulfillment invoice is voided', function () {
    $order = openSalesOrder(10);
    $line = $order->lines->first();

    $invoice = app(FulfillSalesOrder::class)->handle($order, [$line->id => 4]);
    $this->poster->post($invoice);

    // Posted draw-down still counts as invoiced.
    $order->refresh()->load('lines.invoiceLines.invoice');
    expect($order->effectiveStatus())->toBe(SalesOrderStatus::Partial);

    $this->poster->void($invoice->fresh());

    // Voiding drops the invoice from the live sum — no SO callback needed.
    $order->refresh()->load('lines.invoiceLines.invoice');
    expect($order->effectiveStatus())->toBe(SalesOrderStatus::Open);
    expect((float) $order->lines->first()->qtyBackordered())->toBe(10.0);
});

it('cannot fulfill a cancelled order', function () {
    $order = openSalesOrder(10);
    $line = $order->lines->first();
    $order->update(['status' => SalesOrderStatus::Cancelled]);

    expect(fn () => app(FulfillSalesOrder::class)->handle($order->fresh(), [$line->id => 1]))
        ->toThrow(RuntimeException::class);
});

it('posts a tracked-item fulfillment invoice through the normal inventory path', function () {
    $inventoryAsset = Account::query()->where('subtype', AccountSubtype::Inventory->value)->first();
    $cogs = Account::query()->where('subtype', AccountSubtype::CostOfGoodsSold->value)->first();

    $item = Item::factory()->tracked()->create([
        'income_account_id' => $this->income->id,
        'inventory_asset_account_id' => $inventoryAsset->id,
        'cogs_account_id' => $cogs->id,
    ]);

    // Seed 100 units on hand at $5.00.
    $adjustment = StockAdjustment::create([
        'adjustment_no' => 'ADJ-'.uniqid(),
        'adjustment_date' => now()->toDateString(),
        'reason' => StockAdjustmentReason::OpeningBalance,
    ]);
    $adjustment->lines()->create(['item_id' => $item->id, 'qty_change' => '100', 'unit_cost_cents' => 500, 'line_order' => 0]);
    app(StockAdjustmentPoster::class)->post($adjustment->fresh('lines.item'));

    $order = app(SaveSalesOrder::class)->handle([
        'contact_id' => $this->customer->id,
        'order_no' => null,
        'order_date' => $this->company->currentDateTime()->toDateString(),
        'expected_date' => null,
        'terms_id' => null,
        'memo' => null,
        'customer_message' => null,
        'lines' => [[
            'item_id' => $item->id,
            'account_id' => $this->income->id,
            'description' => 'Tracked widget',
            'quantity' => '20',
            'unit_price_cents' => 1000,
            'tax_code_id' => null,
        ]],
    ]);

    $invoice = app(FulfillSalesOrder::class)->handle($order, [$order->lines->first()->id => 20]);
    $this->poster->post($invoice);

    // The existing InvoicePoster issued stock just as for any invoice.
    expect((float) $item->fresh()->qty_on_hand_cached)->toBe(80.0);
});

it('blocks posting a fulfillment invoice that exceeds available stock', function () {
    $inventoryAsset = Account::query()->where('subtype', AccountSubtype::Inventory->value)->first();
    $cogs = Account::query()->where('subtype', AccountSubtype::CostOfGoodsSold->value)->first();

    $item = Item::factory()->tracked()->create([
        'income_account_id' => $this->income->id,
        'inventory_asset_account_id' => $inventoryAsset->id,
        'cogs_account_id' => $cogs->id,
    ]);

    $order = app(SaveSalesOrder::class)->handle([
        'contact_id' => $this->customer->id,
        'order_no' => null,
        'order_date' => $this->company->currentDateTime()->toDateString(),
        'expected_date' => null,
        'terms_id' => null,
        'memo' => null,
        'customer_message' => null,
        'lines' => [[
            'item_id' => $item->id,
            'account_id' => $this->income->id,
            'description' => 'Tracked widget',
            'quantity' => '5',
            'unit_price_cents' => 1000,
            'tax_code_id' => null,
        ]],
    ]);

    // Fulfilling is fine (non-posting), but posting the invoice with no stock fails.
    $invoice = app(FulfillSalesOrder::class)->handle($order, [$order->lines->first()->id => 5]);

    expect(fn () => $this->poster->post($invoice))->toThrow(InsufficientStockException::class);
});
