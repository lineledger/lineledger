<?php

use App\Enums\AccountSubtype;
use App\Enums\BillStatus;
use App\Enums\BillType;
use App\Enums\InvoiceStatus;
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
 * applyToInvoices / applyToBills re-fetch the target document under a row lock
 * (lockForUpdate) instead of incrementing the in-memory relation, so two
 * payments hitting the same document can't lose an update. True parallel
 * connections can't be simulated in-process on SQLite, so these tests pin the
 * additive correctness the lock guarantees: two independent payments accumulate
 * exactly, never clobbering each other.
 */
beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('accumulates two receipts against one invoice without losing either', function () {
    $customer = Contact::create(['display_name' => 'Acme Co', 'is_customer' => true]);
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $undeposited = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->first();

    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-500',
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);
    $invoice->lines()->create([
        'account_id' => $income->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => 20000,
        'line_subtotal_cents' => 20000,
        'line_tax_cents' => 0,
        'line_total_cents' => 20000,
        'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($invoice);

    $pay = function (string $no, int $cents) use ($customer, $undeposited, $invoice) {
        $receipt = CustomerReceipt::create([
            'contact_id' => $customer->id,
            'receipt_no' => $no,
            'receipt_date' => now()->toDateString(),
            'deposit_to_account_id' => $undeposited->id,
            'amount_cents' => $cents,
        ]);
        $receipt->applications()->create([
            'invoice_id' => $invoice->id,
            'amount_cents' => $cents,
        ]);
        app(ReceiptPoster::class)->post($receipt->fresh('applications'));
    };

    $pay('REC-A', 8000);
    $pay('REC-B', 7000);

    $invoice->refresh();
    expect($invoice->amount_paid_cents)->toBe(15000);
    expect($invoice->status)->toBe(InvoiceStatus::Partial);
    expect($invoice->balanceCents())->toBe(5000);
});

it('accumulates two payments against one bill without losing either', function () {
    $vendor = Contact::create(['display_name' => 'Supplier Ltd', 'is_vendor' => true]);
    $expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->first();
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->first();

    $bill = Bill::create([
        'contact_id' => $vendor->id,
        'bill_type' => BillType::Vendor,
        'bill_no' => 'BILL-500',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);
    $bill->lines()->create([
        'account_id' => $expense->id,
        'description' => 'Supplies',
        'quantity' => '1',
        'unit_price_cents' => 20000,
        'line_subtotal_cents' => 20000,
        'line_tax_cents' => 0,
        'line_total_cents' => 20000,
        'line_order' => 0,
    ]);
    app(BillPoster::class)->post($bill);

    $pay = function (string $no, int $cents) use ($vendor, $bank, $bill) {
        $payment = BillPayment::create([
            'contact_id' => $vendor->id,
            'payment_type' => BillType::Vendor,
            'payment_no' => $no,
            'payment_date' => now()->toDateString(),
            'paid_from_account_id' => $bank->id,
            'amount_cents' => $cents,
        ]);
        $payment->applications()->create([
            'bill_id' => $bill->id,
            'amount_cents' => $cents,
        ]);
        app(BillPaymentPoster::class)->post($payment->fresh('applications'));
    };

    $pay('PAY-A', 12000);
    $pay('PAY-B', 5000);

    $bill->refresh();
    expect($bill->amount_paid_cents)->toBe(17000);
    expect($bill->status)->toBe(BillStatus::Partial);
    expect($bill->balanceCents())->toBe(3000);
});
