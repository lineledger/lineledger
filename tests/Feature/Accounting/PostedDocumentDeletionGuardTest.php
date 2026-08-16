<?php

use App\Enums\AccountSubtype;
use App\Enums\BillType;
use App\Exceptions\Posting\PostedDocumentDeletionException;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\Invoice;
use App\Services\Posting\BillPaymentPoster;
use App\Services\Posting\BillPoster;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\ReceiptPoster;

/**
 * A document posted to the GL owns an immutable journal entry; deleting it would
 * orphan that entry and distort the ledger. The model-layer guard refuses
 * deletion of any posted document on every code path — posted documents are
 * unwound by voiding, never deleting. Drafts (no journal entry) delete normally.
 */
beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->customer = Contact::create(['display_name' => 'Guard Customer', 'is_customer' => true]);
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function guardPostedInvoice(int $income, int $customer): Invoice
{
    $invoice = Invoice::create([
        'contact_id' => $customer,
        'invoice_no' => 'INV-G'.fake()->unique()->numberBetween(1000, 999999),
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);
    $invoice->lines()->create([
        'account_id' => $income,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'line_subtotal_cents' => 10000,
        'line_tax_cents' => 0,
        'line_total_cents' => 10000,
        'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($invoice);

    return $invoice->fresh();
}

it('refuses to delete a posted invoice', function () {
    $invoice = guardPostedInvoice($this->income->id, $this->customer->id);

    expect(fn () => $invoice->delete())->toThrow(PostedDocumentDeletionException::class);
    expect(Invoice::withTrashed()->whereKey($invoice->id)->exists())->toBeTrue();
    expect($invoice->fresh()->deleted_at)->toBeNull();
});

it('refuses to force delete a posted invoice', function () {
    $invoice = guardPostedInvoice($this->income->id, $this->customer->id);

    expect(fn () => $invoice->forceDelete())->toThrow(PostedDocumentDeletionException::class);
    expect(Invoice::withTrashed()->whereKey($invoice->id)->exists())->toBeTrue();
});

it('allows deleting a draft invoice', function () {
    $invoice = Invoice::create([
        'contact_id' => $this->customer->id,
        'invoice_no' => 'INV-DRAFT',
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);

    expect($invoice->journal_entry_id)->toBeNull();

    $invoice->delete();

    expect($invoice->fresh()->deleted_at)->not->toBeNull();
});

it('refuses to delete a posted receipt', function () {
    $invoice = guardPostedInvoice($this->income->id, $this->customer->id);
    $undeposited = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->first();

    $receipt = CustomerReceipt::create([
        'contact_id' => $this->customer->id,
        'receipt_no' => 'REC-G1',
        'receipt_date' => now()->toDateString(),
        'deposit_to_account_id' => $undeposited->id,
        'amount_cents' => 10000,
    ]);
    $receipt->applications()->create(['invoice_id' => $invoice->id, 'amount_cents' => 10000]);
    app(ReceiptPoster::class)->post($receipt->fresh('applications'));

    expect(fn () => $receipt->fresh()->delete())->toThrow(PostedDocumentDeletionException::class);
});

it('refuses to delete a posted bill and bill payment', function () {
    $vendor = Contact::create(['display_name' => 'Guard Vendor', 'is_vendor' => true]);
    $expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->first();
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->first();

    $bill = Bill::create([
        'contact_id' => $vendor->id,
        'bill_type' => BillType::Vendor,
        'bill_no' => 'BILL-G1',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);
    $bill->lines()->create([
        'account_id' => $expense->id,
        'description' => 'Supplies',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'line_subtotal_cents' => 10000,
        'line_tax_cents' => 0,
        'line_total_cents' => 10000,
        'line_order' => 0,
    ]);
    app(BillPoster::class)->post($bill);

    expect(fn () => $bill->fresh()->delete())->toThrow(PostedDocumentDeletionException::class);

    $payment = BillPayment::create([
        'contact_id' => $vendor->id,
        'payment_type' => BillType::Vendor,
        'payment_no' => 'PAY-G1',
        'payment_date' => now()->toDateString(),
        'paid_from_account_id' => $bank->id,
        'amount_cents' => 10000,
    ]);
    $payment->applications()->create(['bill_id' => $bill->id, 'amount_cents' => 10000]);
    app(BillPaymentPoster::class)->post($payment->fresh('applications'));

    expect(fn () => $payment->fresh()->delete())->toThrow(PostedDocumentDeletionException::class);
});
