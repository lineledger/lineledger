<?php

use App\Enums\AccountSubtype;
use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\Invoice;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\ReceiptPoster;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->customer = Contact::create([
        'display_name' => 'Beta Inc',
        'is_customer' => true,
    ]);

    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    $this->invoice = Invoice::create([
        'contact_id' => $this->customer->id,
        'invoice_no' => 'INV-100',
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);

    $this->invoice->lines()->create([
        'account_id' => $income->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => 20000,
        'line_subtotal_cents' => 20000,
        'line_tax_cents' => 0,
        'line_total_cents' => 20000,
        'line_order' => 0,
    ]);

    app(InvoicePoster::class)->post($this->invoice);
    $this->invoice->refresh();

    $this->undeposited = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('records and posts a full payment, marking invoice paid', function () {
    $receipt = CustomerReceipt::create([
        'contact_id' => $this->customer->id,
        'receipt_no' => 'REC-001',
        'receipt_date' => now()->toDateString(),
        'deposit_to_account_id' => $this->undeposited->id,
        'amount_cents' => 20000,
    ]);

    $receipt->applications()->create([
        'invoice_id' => $this->invoice->id,
        'amount_cents' => 20000,
    ]);

    app(ReceiptPoster::class)->post($receipt->fresh('applications'));

    $this->invoice->refresh();

    expect($this->invoice->status)->toBe(InvoiceStatus::Paid);
    expect($this->invoice->amount_paid_cents)->toBe(20000);
    expect($this->invoice->balanceCents())->toBe(0);

    $ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->first();
    expect($ar->fresh()->balance_cents)->toBe(0);
    expect($this->undeposited->fresh()->balance_cents)->toBe(20000);
});

it('marks invoice partial on a smaller payment', function () {
    $receipt = CustomerReceipt::create([
        'contact_id' => $this->customer->id,
        'receipt_no' => 'REC-002',
        'receipt_date' => now()->toDateString(),
        'deposit_to_account_id' => $this->undeposited->id,
        'amount_cents' => 5000,
    ]);

    $receipt->applications()->create([
        'invoice_id' => $this->invoice->id,
        'amount_cents' => 5000,
    ]);

    app(ReceiptPoster::class)->post($receipt->fresh('applications'));

    $this->invoice->refresh();
    expect($this->invoice->status)->toBe(InvoiceStatus::Partial);
    expect($this->invoice->amount_paid_cents)->toBe(5000);
    expect($this->invoice->balanceCents())->toBe(15000);
});

it('voiding a receipt unapplies it from invoices', function () {
    $receipt = CustomerReceipt::create([
        'contact_id' => $this->customer->id,
        'receipt_no' => 'REC-003',
        'receipt_date' => now()->toDateString(),
        'deposit_to_account_id' => $this->undeposited->id,
        'amount_cents' => 20000,
    ]);

    $receipt->applications()->create([
        'invoice_id' => $this->invoice->id,
        'amount_cents' => 20000,
    ]);

    $poster = app(ReceiptPoster::class);
    $poster->post($receipt->fresh('applications'));
    $poster->void($receipt->fresh());

    $this->invoice->refresh();
    expect($this->invoice->amount_paid_cents)->toBe(0);
    expect($this->invoice->status)->toBe(InvoiceStatus::Posted);
});
