<?php

use App\Enums\AccountSubtype;
use App\Enums\BillType;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\CustomerReceipt;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Posting\BillPaymentPoster;
use App\Services\Posting\BillPoster;
use App\Services\Posting\CreditMemoPoster;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\JournalPoster;
use App\Services\Posting\ReceiptPoster;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('renders the AR statement showing invoices and receipts in date order', function () {
    $customer = Contact::create(['display_name' => 'Acme Co', 'is_customer' => true]);
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $undeposited = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->first();

    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-501',
        'invoice_date' => CarbonImmutable::create(2026, 3, 1),
        'due_date' => CarbonImmutable::create(2026, 3, 31),
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

    $receipt = CustomerReceipt::create([
        'contact_id' => $customer->id,
        'receipt_no' => 'REC-501',
        'receipt_date' => CarbonImmutable::create(2026, 3, 15),
        'deposit_to_account_id' => $undeposited->id,
        'amount_cents' => 5000,
    ]);
    $receipt->applications()->create(['invoice_id' => $invoice->id, 'amount_cents' => 5000]);
    app(ReceiptPoster::class)->post($receipt->fresh('applications'));

    $this->actingAs($this->user);

    $response = $this->get(route('reports.contact-statement', [
        'company' => $this->company->slug,
        'contact' => $customer->id,
        'kind' => 'ar',
        'start' => '2026-01-01',
        'end' => '2026-12-31',
    ]));

    $response->assertOk();
    $response->assertSee('AR Statement');
    $response->assertSee('Acme Co');
    $response->assertSee('INV-501');
    $response->assertSee('REC-501');
    $response->assertSee('200.00');  // invoice running after debit
    $response->assertSee('150.00');  // closing balance: 200 - 50
});

it('includes a journal entry that posts to AR (e.g. a refund cheque) so the statement ties to the GL', function () {
    $customer = Contact::create(['display_name' => 'Refund Co', 'is_customer' => true]);
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->first();
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->first();

    // A genuine invoice document.
    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-REF',
        'invoice_date' => CarbonImmutable::create(2026, 3, 1),
        'due_date' => CarbonImmutable::create(2026, 3, 31),
    ]);
    $invoice->lines()->create([
        'account_id' => $income->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'line_subtotal_cents' => 10000,
        'line_tax_cents' => 0,
        'line_total_cents' => 10000,
        'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($invoice);

    // A refund cheque booked as a plain journal entry: DR AR / CR Bank, tagged to the customer.
    $refund = JournalEntry::create([
        'entry_no' => 'JE-REFUND',
        'entry_date' => CarbonImmutable::create(2026, 3, 10),
        'memo' => 'Cheque #29803 refund',
    ]);
    $refund->lines()->create(['account_id' => $ar->id, 'contact_id' => $customer->id, 'debit_cents' => 2500, 'credit_cents' => 0, 'line_order' => 0]);
    $refund->lines()->create(['account_id' => $bank->id, 'debit_cents' => 0, 'credit_cents' => 2500, 'line_order' => 1]);
    app(JournalPoster::class)->post($refund);

    $this->actingAs($this->user);

    $response = $this->get(route('reports.contact-statement', [
        'company' => $this->company->slug,
        'contact' => $customer->id,
        'kind' => 'ar',
        'start' => '2026-01-01',
        'end' => '2026-12-31',
    ]));

    $response->assertOk();
    $response->assertSee('INV-REF');
    $response->assertSee('JE-REFUND');       // the refund cheque appears as a journal line
    $response->assertSee('125.00');           // closing: 100 invoice + 25 refund = ties to GL AR
});

it('keeps a voided credit memo and its reversal on the statement so it ties to the GL', function () {
    $customer = Contact::create(['display_name' => 'Void Co', 'is_customer' => true]);
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->first();
    $undeposited = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->first();

    // Invoice for 555 → DR AR 555.
    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-000001',
        'invoice_date' => CarbonImmutable::create(2026, 5, 24),
        'due_date' => CarbonImmutable::create(2026, 6, 24),
    ]);
    $invoice->lines()->create([
        'account_id' => $income->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => 55500,
        'line_subtotal_cents' => 55500,
        'line_tax_cents' => 0,
        'line_total_cents' => 55500,
        'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($invoice);

    // Credit memo for 50 → CR AR 50.
    $memo = CreditMemo::create([
        'contact_id' => $customer->id,
        'credit_memo_no' => 'CM-000001',
        'credit_memo_date' => CarbonImmutable::create(2026, 5, 24),
    ]);
    $memo->lines()->create([
        'account_id' => $income->id,
        'description' => 'Credit',
        'quantity' => '1',
        'unit_price_cents' => 5000,
        'line_subtotal_cents' => 5000,
        'line_tax_cents' => 0,
        'line_total_cents' => 5000,
        'line_order' => 0,
    ]);
    app(CreditMemoPoster::class)->post($memo);

    // Card refund of the credit memo: negative receipt → DR AR 50.
    $refund = CustomerReceipt::create([
        'contact_id' => $customer->id,
        'credit_memo_id' => $memo->id,
        'receipt_no' => 'REC-000001',
        'receipt_date' => CarbonImmutable::create(2026, 5, 24),
        'deposit_to_account_id' => $undeposited->id,
        'amount_cents' => -5000,
    ]);
    app(ReceiptPoster::class)->post($refund);

    // Void the credit memo → reversing entry DR AR 50.
    app(CreditMemoPoster::class)->void($memo);

    $this->actingAs($this->user);

    $response = $this->get(route('reports.contact-statement', [
        'company' => $this->company->slug,
        'contact' => $customer->id,
        'kind' => 'ar',
        'start' => '2026-01-01',
        'end' => '2026-12-31',
    ]));

    $response->assertOk();
    $response->assertSee('INV-000001');
    $response->assertSee('CM-000001');                 // the original credit memo is still listed
    $response->assertSee('REC-000001');
    $response->assertSee('Void of credit memo CM-000001');
    // 555 − 50 (CM) + 50 (refund) + 50 (void) = 605, tying to the GL AR balance.
    $response->assertSee('605.00');

    expect((int) round($ar->fresh()->balance_cents))->toBe(60500);
});

it('renders the AP statement showing bills and payments in date order', function () {
    $vendor = Contact::create(['display_name' => 'Beta Supplies', 'is_vendor' => true]);
    $expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
    $cash = Account::query()->where('subtype', AccountSubtype::Bank->value)->first()
        ?? Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->first();

    $bill = Bill::create([
        'contact_id' => $vendor->id,
        'bill_type' => BillType::Vendor,
        'bill_no' => 'BILL-77',
        'bill_date' => CarbonImmutable::create(2026, 3, 1),
        'due_date' => CarbonImmutable::create(2026, 3, 31),
    ]);
    $bill->lines()->create([
        'account_id' => $expense->id,
        'description' => 'Goods',
        'quantity' => '1',
        'unit_price_cents' => 8000,
        'line_subtotal_cents' => 8000,
        'line_tax_cents' => 0,
        'line_total_cents' => 8000,
        'line_order' => 0,
    ]);
    app(BillPoster::class)->post($bill);

    $payment = BillPayment::create([
        'contact_id' => $vendor->id,
        'payment_type' => BillType::Vendor,
        'payment_no' => 'PAY-77',
        'payment_date' => CarbonImmutable::create(2026, 3, 20),
        'paid_from_account_id' => $cash->id,
        'amount_cents' => 3000,
    ]);
    $payment->applications()->create(['bill_id' => $bill->id, 'amount_cents' => 3000]);
    app(BillPaymentPoster::class)->post($payment->fresh('applications'));

    $this->actingAs($this->user);

    $response = $this->get(route('reports.contact-statement', [
        'company' => $this->company->slug,
        'contact' => $vendor->id,
        'kind' => 'ap',
        'start' => '2026-01-01',
        'end' => '2026-12-31',
    ]));

    $response->assertOk();
    $response->assertSee('AP Statement');
    $response->assertSee('Beta Supplies');
    $response->assertSee('BILL-77');
    $response->assertSee('PAY-77');
    $response->assertSee('80.00');   // bill credit
    $response->assertSee('-50.00');  // closing: 0 - 80 + 30 = -50
});

it('AR aging row links the customer name to its statement', function () {
    $customer = Contact::create(['display_name' => 'Linked Customer', 'is_customer' => true]);
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    $inv = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-LINK',
        'invoice_date' => CarbonImmutable::create(2026, 5, 1),
        'due_date' => CarbonImmutable::create(2026, 5, 31),
    ]);
    $inv->lines()->create([
        'account_id' => $income->id,
        'description' => 'x',
        'quantity' => '1',
        'unit_price_cents' => 4400,
        'line_subtotal_cents' => 4400,
        'line_tax_cents' => 0,
        'line_total_cents' => 4400,
        'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($inv);

    $this->actingAs($this->user);

    $expected = route('reports.contact-statement', [
        'company' => $this->company->slug,
        'contact' => $customer->id,
        'kind' => 'ar',
    ], absolute: false);

    $response = $this->get(route('reports.ar-aging', ['company' => $this->company->slug, 'as_of' => '2026-05-20']));
    $response->assertOk();
    $response->assertSee('Linked Customer');
    $response->assertSee($expected, escape: false);
});

it('AP aging row links the vendor name to its statement', function () {
    $vendor = Contact::create(['display_name' => 'Linked Vendor', 'is_vendor' => true]);
    $expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();

    $bill = Bill::create([
        'contact_id' => $vendor->id,
        'bill_type' => BillType::Vendor,
        'bill_no' => 'BILL-LINK',
        'bill_date' => CarbonImmutable::create(2026, 5, 1),
        'due_date' => CarbonImmutable::create(2026, 5, 31),
    ]);
    $bill->lines()->create([
        'account_id' => $expense->id,
        'description' => 'x',
        'quantity' => '1',
        'unit_price_cents' => 7700,
        'line_subtotal_cents' => 7700,
        'line_tax_cents' => 0,
        'line_total_cents' => 7700,
        'line_order' => 0,
    ]);
    app(BillPoster::class)->post($bill);

    $this->actingAs($this->user);

    $expected = route('reports.contact-statement', [
        'company' => $this->company->slug,
        'contact' => $vendor->id,
        'kind' => 'ap',
    ], absolute: false);

    $response = $this->get(route('reports.ap-aging', ['company' => $this->company->slug, 'as_of' => '2026-05-20']));
    $response->assertOk();
    $response->assertSee('Linked Vendor');
    $response->assertSee($expected, escape: false);
});
