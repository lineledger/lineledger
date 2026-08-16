<?php

use App\Actions\Sales\ConvertEstimateToInvoice;
use App\Actions\Sales\SaveEstimate;
use App\Enums\AccountSubtype;
use App\Enums\EstimateStatus;
use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
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

function pendingEstimateWithTax(): array
{
    $gst = TaxCode::where('code', 'GST')->firstOrFail();

    $estimate = app(SaveEstimate::class)->handle([
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
                'quantity' => '1',
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

    return [$estimate, $gst];
}

it('converts an estimate into a matching draft invoice with no GL entry', function () {
    [$estimate] = pendingEstimateWithTax();
    $jeBefore = JournalEntry::count();

    $invoice = app(ConvertEstimateToInvoice::class)->handle($estimate);

    expect($invoice->status)->toBe(InvoiceStatus::Draft);
    expect($invoice->journal_entry_id)->toBeNull();
    expect($invoice->lines)->toHaveCount(2);
    expect($invoice->subtotal_cents)->toBe($estimate->subtotal_cents);
    expect($invoice->tax_cents)->toBe($estimate->tax_cents);
    expect($invoice->total_cents)->toBe($estimate->total_cents);

    // Conversion must never touch the ledger.
    expect(JournalEntry::count())->toBe($jeBefore);

    $estimate->refresh();
    expect($estimate->status)->toBe(EstimateStatus::Converted);
    expect($estimate->converted_invoice_id)->toBe($invoice->id);
});

it('carries line detail through to the invoice', function () {
    [$estimate, $gst] = pendingEstimateWithTax();

    $invoice = app(ConvertEstimateToInvoice::class)->handle($estimate);

    $estimateLines = $estimate->lines->values();
    $invoiceLines = $invoice->lines->values();

    foreach ($estimateLines as $i => $line) {
        expect((int) $invoiceLines[$i]->unit_price_cents)->toBe((int) $line->unit_price_cents);
        expect($invoiceLines[$i]->account_id)->toBe($line->account_id);
        expect($invoiceLines[$i]->tax_code_id)->toBe($line->tax_code_id);
        expect((string) $invoiceLines[$i]->quantity)->toBe((string) $line->quantity);
    }
});

it('blocks converting an already converted estimate', function () {
    [$estimate] = pendingEstimateWithTax();
    app(ConvertEstimateToInvoice::class)->handle($estimate);

    expect(fn () => app(ConvertEstimateToInvoice::class)->handle($estimate->fresh()))
        ->toThrow(RuntimeException::class);
});

it('converts an accepted estimate', function () {
    [$estimate] = pendingEstimateWithTax();
    $estimate->update(['status' => EstimateStatus::Accepted]);

    $invoice = app(ConvertEstimateToInvoice::class)->handle($estimate->fresh());

    expect($invoice->status)->toBe(InvoiceStatus::Draft);
    expect($estimate->fresh()->status)->toBe(EstimateStatus::Converted);
});

it('carries the line discount, service date and PO through to the invoice', function () {
    // Regression guard: conversion previously dropped the line discount (overstating
    // the invoice vs. the quoted total), plus service_date and the customer PO.
    $estimate = app(SaveEstimate::class)->handle([
        'contact_id' => $this->customer->id,
        'estimate_no' => null,
        'estimate_date' => $this->company->currentDateTime()->toDateString(),
        'expires_on' => null,
        'terms_id' => null,
        'memo' => null,
        'customer_po' => 'PO-555',
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

    expect($estimate->total_cents)->toBe(7500); // 10000 − 2500 discount, no tax

    $invoice = app(ConvertEstimateToInvoice::class)->handle($estimate);

    expect($invoice->customer_po)->toBe('PO-555')
        ->and($invoice->total_cents)->toBe(7500); // discount preserved (was 10000 before the fix)

    $line = $invoice->lines->first();
    expect((int) $line->line_discount_cents)->toBe(2500)
        ->and((string) $line->service_date)->toContain('2026-03-15');
});

it('carries both taxes when converting a dual-tax estimate to an invoice', function () {
    // Regression guard: conversion copied the line's primary tax_code_id but not
    // secondary_tax_code_id, so a GST + PST quote silently dropped PST — the
    // invoice under-charged tax — once converted.
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

    // GST 5% (500) + PST 7% (700) on $100 = 1200; proves the secondary contributes.
    expect($estimate->tax_cents)->toBe(1200);

    $invoice = app(ConvertEstimateToInvoice::class)->handle($estimate);

    $line = $invoice->lines->first();
    expect($line->tax_code_id)->toBe($gst->id)
        ->and($line->secondary_tax_code_id)->toBe($pst->id)          // was null before the fix
        ->and($invoice->tax_cents)->toBe($estimate->tax_cents)        // both taxes survived (was 500)
        ->and($invoice->total_cents)->toBe($estimate->total_cents);
});
