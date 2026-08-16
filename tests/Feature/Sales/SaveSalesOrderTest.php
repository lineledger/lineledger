<?php

use App\Actions\Sales\SaveSalesOrder;
use App\Enums\AccountSubtype;
use App\Enums\SalesOrderStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\TaxCode;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->customer = Contact::create([
        'display_name' => 'Acme Corp',
        'is_customer' => true,
    ]);

    $this->incomeAccount = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function salesOrderData(array $overrides = []): array
{
    $gst = TaxCode::where('code', 'GST')->firstOrFail();

    return array_merge([
        'contact_id' => test()->customer->id,
        'order_no' => null,
        'order_date' => test()->company->currentDateTime()->toDateString(),
        'expected_date' => null,
        'terms_id' => null,
        'memo' => 'Order memo',
        'customer_message' => 'Thanks!',
        'lines' => [
            [
                'item_id' => null,
                'account_id' => test()->incomeAccount->id,
                'description' => 'Widget A',
                'quantity' => '10',
                'unit_price_cents' => 10000,
                'tax_code_id' => $gst->id,
            ],
            [
                'item_id' => null,
                'account_id' => test()->incomeAccount->id,
                'description' => 'Widget B',
                'quantity' => '2',
                'unit_price_cents' => 2500,
                'tax_code_id' => null,
            ],
        ],
    ], $overrides);
}

it('creates a sales order with an auto-generated number and computed totals', function () {
    $order = app(SaveSalesOrder::class)->handle(salesOrderData());

    expect($order->order_no)->toBe('SO-000001');
    expect($order->status)->toBe(SalesOrderStatus::Open);
    expect($order->lines)->toHaveCount(2);

    // 10 * 100.00 + 2 * 25.00 = 1050.00 subtotal; GST 5% on the first line only = 50.00.
    expect($order->subtotal_cents)->toBe(105000);
    expect($order->tax_cents)->toBe(5000);
    expect($order->total_cents)->toBe(110000);

    // A fresh order is fully outstanding.
    expect($order->effectiveStatus())->toBe(SalesOrderStatus::Open);
    expect((float) $order->lines->first()->qtyBackordered())->toBe(10.0);
});

it('increments the order number per company', function () {
    app(SaveSalesOrder::class)->handle(salesOrderData());
    $second = app(SaveSalesOrder::class)->handle(salesOrderData());

    expect($second->order_no)->toBe('SO-000002');
});

it('updates an existing order in place, replacing its lines', function () {
    $order = app(SaveSalesOrder::class)->handle(salesOrderData());

    $updated = app(SaveSalesOrder::class)->handle(salesOrderData([
        'order_no' => $order->order_no,
        'memo' => 'Revised',
        'lines' => [[
            'item_id' => null,
            'account_id' => $this->incomeAccount->id,
            'description' => 'Single line',
            'quantity' => '3',
            'unit_price_cents' => 1000,
            'tax_code_id' => null,
        ]],
    ]), $order);

    expect($updated->id)->toBe($order->id);
    expect($updated->memo)->toBe('Revised');
    expect($updated->lines)->toHaveCount(1);
    expect($updated->total_cents)->toBe(3000);
});
