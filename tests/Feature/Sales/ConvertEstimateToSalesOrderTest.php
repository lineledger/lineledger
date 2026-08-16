<?php

use App\Actions\Sales\ConvertEstimateToSalesOrder;
use App\Actions\Sales\SaveEstimate;
use App\Enums\AccountSubtype;
use App\Enums\EstimateStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Estimate;
use App\Models\JournalEntry;
use App\Models\TaxCode;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->customer = Contact::create(['display_name' => 'Acme Corp', 'is_customer' => true]);
    $this->incomeAccount = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function pendingEstimate(): Estimate
{
    $gst = TaxCode::where('code', 'GST')->firstOrFail();

    return app(SaveEstimate::class)->handle([
        'contact_id' => test()->customer->id,
        'estimate_no' => null,
        'estimate_date' => test()->company->currentDateTime()->toDateString(),
        'expires_on' => null,
        'terms_id' => null,
        'memo' => 'Quote memo',
        'customer_message' => 'Thanks!',
        'lines' => [
            [
                'item_id' => null,
                'account_id' => test()->incomeAccount->id,
                'description' => 'Service A',
                'quantity' => '4',
                'unit_price_cents' => 10000,
                'tax_code_id' => $gst->id,
            ],
            [
                'item_id' => null,
                'account_id' => test()->incomeAccount->id,
                'description' => 'Service B',
                'quantity' => '2',
                'unit_price_cents' => 2500,
                'tax_code_id' => null,
            ],
        ],
    ]);
}

it('converts an estimate into a matching open sales order with no GL entry', function () {
    $estimate = pendingEstimate();
    $jeBefore = JournalEntry::count();

    $order = app(ConvertEstimateToSalesOrder::class)->handle($estimate);

    expect($order->status)->toBe(SalesOrderStatus::Open);
    expect($order->lines)->toHaveCount(2);
    expect($order->subtotal_cents)->toBe($estimate->subtotal_cents);
    expect($order->tax_cents)->toBe($estimate->tax_cents);
    expect($order->total_cents)->toBe($estimate->total_cents);

    // Conversion never touches the ledger.
    expect(JournalEntry::count())->toBe($jeBefore);

    $estimate->refresh();
    expect($estimate->status)->toBe(EstimateStatus::Converted);
    expect($estimate->converted_sales_order_id)->toBe($order->id);
    expect($estimate->converted_invoice_id)->toBeNull();
});

it('carries line detail through to the sales order', function () {
    $estimate = pendingEstimate();

    $order = app(ConvertEstimateToSalesOrder::class)->handle($estimate);

    $estimateLines = $estimate->lines->values();
    $orderLines = $order->lines->values();

    foreach ($estimateLines as $i => $line) {
        expect((int) $orderLines[$i]->unit_price_cents)->toBe((int) $line->unit_price_cents);
        expect($orderLines[$i]->account_id)->toBe($line->account_id);
        expect($orderLines[$i]->tax_code_id)->toBe($line->tax_code_id);
        expect((string) $orderLines[$i]->quantity)->toBe((string) $line->quantity);
    }
});

it('blocks converting an already converted estimate', function () {
    $estimate = pendingEstimate();
    app(ConvertEstimateToSalesOrder::class)->handle($estimate);

    expect(fn () => app(ConvertEstimateToSalesOrder::class)->handle($estimate->fresh()))
        ->toThrow(RuntimeException::class);
});

it('carries the line discount and header references through to the sales order', function () {
    // Regression guard: conversion previously dropped the line discount plus
    // service_date and the customer PO.
    $estimate = app(SaveEstimate::class)->handle([
        'contact_id' => $this->customer->id,
        'estimate_no' => null,
        'estimate_date' => $this->company->currentDateTime()->toDateString(),
        'expires_on' => null,
        'terms_id' => null,
        'memo' => null,
        'customer_po' => 'PO-777',
        'customer_message' => 'Thanks!',
        'lines' => [[
            'item_id' => null,
            'account_id' => $this->incomeAccount->id,
            'description' => 'Discounted service',
            'service_date' => '2026-03-15',
            'quantity' => '1',
            'unit_price_cents' => 10000,
            'line_discount_cents' => 2500,
            'tax_code_id' => null,
        ]],
    ]);

    expect($estimate->total_cents)->toBe(7500);

    $order = app(ConvertEstimateToSalesOrder::class)->handle($estimate);

    expect($order->customer_po)->toBe('PO-777')
        ->and($order->total_cents)->toBe(7500); // discount preserved (was 10000 before the fix)

    $line = $order->lines->first();
    expect((int) $line->line_discount_cents)->toBe(2500)
        ->and((string) $line->service_date)->toContain('2026-03-15');
});

it('carries both taxes when converting a dual-tax estimate to a sales order', function () {
    // Regression guard: the sales-order converter also dropped the line's
    // secondary_tax_code_id, so a GST + PST quote lost its PST on conversion.
    $gst = TaxCode::where('code', 'GST')->firstOrFail();
    $pst = TaxCode::create(['code' => 'PST', 'name' => 'PST (7%)', 'rate_basis_points' => 700, 'is_recoverable' => false]);

    $estimate = app(SaveEstimate::class)->handle([
        'contact_id' => $this->customer->id,
        'estimate_no' => null,
        'estimate_date' => $this->company->currentDateTime()->toDateString(),
        'expires_on' => null,
        'terms_id' => null,
        'memo' => null,
        'customer_message' => null,
        'lines' => [[
            'item_id' => null,
            'account_id' => $this->incomeAccount->id,
            'description' => 'Dual-taxed service',
            'quantity' => '1',
            'unit_price_cents' => 10000,
            'tax_code_id' => $gst->id,
            'secondary_tax_code_id' => $pst->id,
        ]],
    ]);

    expect($estimate->tax_cents)->toBe(1200); // GST 5% + PST 7% on $100

    $order = app(ConvertEstimateToSalesOrder::class)->handle($estimate);

    $line = $order->lines->first();
    expect($line->tax_code_id)->toBe($gst->id)
        ->and($line->secondary_tax_code_id)->toBe($pst->id)      // was null before the fix
        ->and($order->tax_cents)->toBe($estimate->tax_cents)
        ->and($order->total_cents)->toBe($estimate->total_cents);
});
