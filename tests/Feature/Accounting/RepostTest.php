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
use App\Models\JournalLine;
use App\Services\Posting\BillPaymentPoster;
use App\Services\Posting\BillPoster;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\ReceiptPoster;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * GL trial balance for the company — sum of debits and credits across
 * all posted (non-voided) journal lines. Returns ['dr' => x, 'cr' => x].
 */
function ledgerTotals(Company $company): array
{
    $row = JournalLine::query()
        ->whereHas('journalEntry', fn ($q) => $q
            ->where('company_id', $company->id)
            ->where('is_posted', true)
        )
        ->selectRaw('COALESCE(SUM(debit_cents), 0) AS dr, COALESCE(SUM(credit_cents), 0) AS cr')
        ->first();

    return ['dr' => (int) $row->dr, 'cr' => (int) $row->cr];
}

it('reposts an invoice and the GL ties out to the new total', function () {
    $customer = Contact::create(['display_name' => 'Edit Customer', 'is_customer' => true]);
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->first();

    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'EDIT-1',
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);
    $invoice->lines()->create([
        'account_id' => $income->id,
        'description' => 'Original',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'line_subtotal_cents' => 10000,
        'line_tax_cents' => 0,
        'line_total_cents' => 10000,
        'line_order' => 0,
    ]);

    app(InvoicePoster::class)->post($invoice);
    $invoice->refresh();

    expect($ar->fresh()->balance_cents)->toBe(10000);

    // Edit: bump amount, swap account stays same, change date
    $invoice->lines()->delete();
    $invoice->lines()->create([
        'account_id' => $income->id,
        'description' => 'Edited',
        'quantity' => '2',
        'unit_price_cents' => 7500,
        'line_subtotal_cents' => 15000,
        'line_tax_cents' => 0,
        'line_total_cents' => 15000,
        'line_order' => 0,
    ]);
    $invoice->update(['invoice_date' => now()->subDay()->toDateString()]);

    app(InvoicePoster::class)->repost($invoice->fresh('lines'));

    $invoice->refresh();

    expect($invoice->total_cents)->toBe(15000);
    expect($invoice->status)->toBe(InvoiceStatus::Posted);
    expect($ar->fresh()->balance_cents)->toBe(15000);
    expect($income->fresh()->balance_cents)->toBe(15000);

    $totals = ledgerTotals($this->company);
    expect($totals['dr'])->toBe($totals['cr']); // Always balanced
});

it('reposting a paid invoice with a lower new total recomputes status (over-paid → paid)', function () {
    $customer = Contact::create(['display_name' => 'Paid Customer', 'is_customer' => true]);
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $undep = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->first();

    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'EDIT-PAID-1',
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);
    $invoice->lines()->create([
        'account_id' => $income->id, 'description' => 'x', 'quantity' => '1',
        'unit_price_cents' => 10000,
        'line_subtotal_cents' => 10000, 'line_tax_cents' => 0, 'line_total_cents' => 10000,
        'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($invoice);
    $invoice->refresh();

    // Pay it in full
    $receipt = CustomerReceipt::create([
        'contact_id' => $customer->id,
        'receipt_no' => 'REC-PAID-1',
        'receipt_date' => now()->toDateString(),
        'deposit_to_account_id' => $undep->id,
        'amount_cents' => 10000,
    ]);
    $receipt->applications()->create(['invoice_id' => $invoice->id, 'amount_cents' => 10000]);
    app(ReceiptPoster::class)->post($receipt->fresh('applications'));

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);

    // Edit: reduce total to 5000 — invoice should stay paid (over-paid)
    $invoice->lines()->delete();
    $invoice->lines()->create([
        'account_id' => $income->id, 'description' => 'reduced', 'quantity' => '1',
        'unit_price_cents' => 5000,
        'line_subtotal_cents' => 5000, 'line_tax_cents' => 0, 'line_total_cents' => 5000,
        'line_order' => 0,
    ]);

    app(InvoicePoster::class)->repost($invoice->fresh('lines'));

    $invoice->refresh();
    expect($invoice->total_cents)->toBe(5000);
    expect($invoice->status)->toBe(InvoiceStatus::Paid);

    $totals = ledgerTotals($this->company);
    expect($totals['dr'])->toBe($totals['cr']);
});

it('reposts a receipt and re-applies to invoices correctly', function () {
    $customer = Contact::create(['display_name' => 'Recpt Customer', 'is_customer' => true]);
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $undep = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->first();

    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'EDIT-INV-A',
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);
    $invoice->lines()->create([
        'account_id' => $income->id, 'description' => 'x', 'quantity' => '1',
        'unit_price_cents' => 10000,
        'line_subtotal_cents' => 10000, 'line_tax_cents' => 0, 'line_total_cents' => 10000,
        'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($invoice);

    $receipt = CustomerReceipt::create([
        'contact_id' => $customer->id,
        'receipt_no' => 'EDIT-REC',
        'receipt_date' => now()->toDateString(),
        'deposit_to_account_id' => $undep->id,
        'amount_cents' => 5000,
    ]);
    $receipt->applications()->create(['invoice_id' => $invoice->id, 'amount_cents' => 5000]);
    app(ReceiptPoster::class)->post($receipt->fresh('applications'));

    expect($invoice->fresh()->amount_paid_cents)->toBe(5000);
    expect($invoice->fresh()->status->value)->toBe('partial');

    // Edit receipt: bump amount and apply more
    $receipt->update(['amount_cents' => 10000]);
    $receipt->applications()->delete();
    $receipt->applications()->create(['invoice_id' => $invoice->id, 'amount_cents' => 10000]);

    app(ReceiptPoster::class)->repost($receipt->fresh('applications'));

    $invoice->refresh();
    expect($invoice->amount_paid_cents)->toBe(10000);
    expect($invoice->status)->toBe(InvoiceStatus::Paid);

    $totals = ledgerTotals($this->company);
    expect($totals['dr'])->toBe($totals['cr']);
});

it('reposts a bill and the GL ties out', function () {
    $vendor = Contact::create(['display_name' => 'Edit Vendor', 'is_vendor' => true]);
    $expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
    $ap = Account::query()->where('subtype', AccountSubtype::AccountsPayable->value)->where('is_system', true)->first();

    $bill = Bill::create([
        'contact_id' => $vendor->id,
        'bill_type' => BillType::Vendor,
        'bill_no' => 'EDIT-B-1',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);
    $bill->lines()->create([
        'account_id' => $expense->id, 'description' => 'x', 'quantity' => '1',
        'unit_price_cents' => 5000,
        'line_subtotal_cents' => 5000, 'line_tax_cents' => 0, 'line_total_cents' => 5000,
        'line_order' => 0,
    ]);
    app(BillPoster::class)->post($bill);

    expect($ap->fresh()->balance_cents)->toBe(5000);

    // Edit: bump amount
    $bill->lines()->delete();
    $bill->lines()->create([
        'account_id' => $expense->id, 'description' => 'edited', 'quantity' => '1',
        'unit_price_cents' => 8000,
        'line_subtotal_cents' => 8000, 'line_tax_cents' => 0, 'line_total_cents' => 8000,
        'line_order' => 0,
    ]);
    app(BillPoster::class)->repost($bill->fresh('lines'));

    $bill->refresh();
    expect($bill->total_cents)->toBe(8000);
    expect($bill->status)->toBe(BillStatus::Posted);
    expect($ap->fresh()->balance_cents)->toBe(8000);
    expect($expense->fresh()->balance_cents)->toBe(8000);

    $totals = ledgerTotals($this->company);
    expect($totals['dr'])->toBe($totals['cr']);
});

it('reposts a bill payment and bills are recomputed from the live application ledger', function () {
    $vendor = Contact::create(['display_name' => 'Pay Vendor', 'is_vendor' => true]);
    $expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();

    $bill = Bill::create([
        'contact_id' => $vendor->id,
        'bill_type' => BillType::Vendor,
        'bill_no' => 'PB-1',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);
    $bill->lines()->create([
        'account_id' => $expense->id, 'description' => 'x', 'quantity' => '1',
        'unit_price_cents' => 20000,
        'line_subtotal_cents' => 20000, 'line_tax_cents' => 0, 'line_total_cents' => 20000,
        'line_order' => 0,
    ]);
    app(BillPoster::class)->post($bill);

    $payment = BillPayment::create([
        'contact_id' => $vendor->id,
        'payment_type' => BillType::Vendor,
        'payment_no' => 'EDIT-PAY-1',
        'payment_date' => now()->toDateString(),
        'paid_from_account_id' => $bank->id,
        'amount_cents' => 5000,
    ]);
    $payment->applications()->create(['bill_id' => $bill->id, 'amount_cents' => 5000]);
    app(BillPaymentPoster::class)->post($payment->fresh('applications'));

    expect($bill->fresh()->amount_paid_cents)->toBe(5000);
    expect($bill->fresh()->status)->toBe(BillStatus::Partial);

    // Edit payment: pay it in full now
    $payment->update(['amount_cents' => 20000]);
    $payment->applications()->delete();
    $payment->applications()->create(['bill_id' => $bill->id, 'amount_cents' => 20000]);

    app(BillPaymentPoster::class)->repost($payment->fresh('applications'));

    $bill->refresh();
    expect($bill->amount_paid_cents)->toBe(20000);
    expect($bill->status)->toBe(BillStatus::Paid);

    $totals = ledgerTotals($this->company);
    expect($totals['dr'])->toBe($totals['cr']);
});
