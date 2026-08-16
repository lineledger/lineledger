<?php

use App\Enums\AccountSubtype;
use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\TaxCode;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\TaxCalculator;

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

it('posts a tax-free invoice and updates AR', function () {
    $invoice = Invoice::create([
        'contact_id' => $this->customer->id,
        'invoice_no' => 'INV-001',
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);

    $calc = app(TaxCalculator::class);
    $totals = $calc->line('2', 5000); // 2 × $50

    $invoice->lines()->create([
        'account_id' => $this->incomeAccount->id,
        'description' => 'Consulting',
        'quantity' => '2',
        'unit_price_cents' => 5000,
        'line_subtotal_cents' => $totals['subtotal_cents'],
        'line_tax_cents' => $totals['tax_cents'],
        'line_total_cents' => $totals['total_cents'],
        'line_order' => 0,
    ]);

    app(InvoicePoster::class)->post($invoice);

    $invoice->refresh();

    expect($invoice->status)->toBe(InvoiceStatus::Posted);
    expect($invoice->total_cents)->toBe(10000);
    expect($invoice->journal_entry_id)->not->toBeNull();

    $ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->first();

    expect($ar->fresh()->balance_cents)->toBe(10000);
    expect($this->incomeAccount->fresh()->balance_cents)->toBe(10000);
});

it('posts an invoice with GST tax to per-agency payable account', function () {
    $gst = TaxCode::where('code', 'GST')->firstOrFail();

    $invoice = Invoice::create([
        'contact_id' => $this->customer->id,
        'invoice_no' => 'INV-002',
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);

    $calc = app(TaxCalculator::class);
    $totals = $calc->line('1', 10000, $gst);

    $invoice->lines()->create([
        'account_id' => $this->incomeAccount->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'tax_code_id' => $gst->id,
        'line_subtotal_cents' => $totals['subtotal_cents'],
        'line_tax_cents' => $totals['tax_cents'],
        'line_total_cents' => $totals['total_cents'],
        'line_order' => 0,
    ]);

    app(InvoicePoster::class)->post($invoice);
    $invoice->refresh();

    expect($invoice->subtotal_cents)->toBe(10000);
    expect($invoice->tax_cents)->toBe(500);
    expect($invoice->total_cents)->toBe(10500);

    $ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->first();
    $gstPayable = $gst->agency->payableAccount;

    expect($ar->fresh()->balance_cents)->toBe(10500);
    expect($this->incomeAccount->fresh()->balance_cents)->toBe(10000);
    expect($gstPayable->fresh()->balance_cents)->toBe(500);
});

it('voids a posted invoice and reverses the GL entry', function () {
    $invoice = Invoice::create([
        'contact_id' => $this->customer->id,
        'invoice_no' => 'INV-003',
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);

    $invoice->lines()->create([
        'account_id' => $this->incomeAccount->id,
        'description' => 'Test',
        'quantity' => '1',
        'unit_price_cents' => 5000,
        'line_subtotal_cents' => 5000,
        'line_tax_cents' => 0,
        'line_total_cents' => 5000,
        'line_order' => 0,
    ]);

    $poster = app(InvoicePoster::class);
    $poster->post($invoice);
    $poster->void($invoice->fresh());

    $invoice->refresh();
    $ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->first();

    expect($invoice->status)->toBe(InvoiceStatus::Void);
    expect($ar->fresh()->balance_cents)->toBe(0);
    expect($this->incomeAccount->fresh()->balance_cents)->toBe(0);
});
