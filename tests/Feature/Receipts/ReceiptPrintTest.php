<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\Invoice;
use App\Models\InvoiceSetting;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\ReceiptPoster;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();
    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->gst = TaxCode::query()->where('code', 'GST')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function makePostedReceipt(object $test): CustomerReceipt
{
    $customer = Contact::create([
        'display_name' => 'Chavez, Isaac Jr.',
        'is_customer' => true,
        'billing_line1' => '123 Main St',
        'billing_city' => 'Victoria',
        'billing_region' => 'BC',
        'billing_postal_code' => 'V8V 1A1',
    ]);

    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-RCPT-1',
        'invoice_date' => CarbonImmutable::create(2026, 5, 1),
        'due_date' => CarbonImmutable::create(2026, 5, 31),
    ]);
    $invoice->lines()->create([
        'account_id' => $test->income->id,
        'description' => 'Embalming services',
        'quantity' => '1',
        'unit_price_cents' => 5000,
        'line_subtotal_cents' => 5000,
        'line_tax_cents' => 0,
        'line_total_cents' => 5000,
        'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($invoice);

    $receipt = CustomerReceipt::create([
        'contact_id' => $customer->id,
        'receipt_no' => 'RCPT-PRINT-1',
        'receipt_date' => CarbonImmutable::create(2026, 5, 24),
        'deposit_to_account_id' => $test->bank->id,
        'amount_cents' => 5000,
    ]);
    $receipt->applications()->create([
        'invoice_id' => $invoice->id,
        'amount_cents' => 5000,
    ]);

    app(ReceiptPoster::class)->post($receipt->fresh('applications'));

    return $receipt->fresh();
}

it('returns the receipt as an inline PDF', function () {
    $receipt = makePostedReceipt($this);

    $response = $this->get(route('receipts.print', [
        'company' => $this->company->slug,
        'receipt' => $receipt->id,
    ]));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toContain('inline');
});

it('404s when the receipt belongs to another company', function () {
    $otherCompany = Company::factory()->create();
    app()->instance('current_company', $otherCompany);
    $foreign = makePostedReceipt((object) [
        'company' => $otherCompany,
        'income' => Account::query()->where('company_id', $otherCompany->id)->where('subtype', AccountSubtype::Income->value)->first(),
        'bank' => Account::query()->where('company_id', $otherCompany->id)->where('subtype', AccountSubtype::Bank->value)->first(),
    ]);
    app()->instance('current_company', $this->company);

    $this->get(route('receipts.print', [
        'company' => $this->company->slug,
        'receipt' => $foreign->id,
    ]))->assertNotFound();
});

it('renders customer-facing receipt content', function () {
    $receipt = makePostedReceipt($this)->load('contact', 'paymentMethod', 'applications.invoice');

    $settings = new InvoiceSetting([...InvoiceSetting::defaults(), 'company_id' => $this->company->id]);
    $settings->footer_message = 'Thank you for your payment';

    $html = view('pdf.receipts.receipt', [
        'company' => $this->company,
        'receipt' => $receipt,
        'settings' => $settings,
        'logoData' => null,
    ])->render();

    expect($html)
        ->toContain('RECEIPT')
        ->toContain('RCPT-PRINT-1')
        ->toContain('Chavez, Isaac Jr.')
        ->toContain('123 Main St')
        ->toContain('INV-RCPT-1')
        ->toContain('Amount Received')
        ->toContain('Thank you for your payment')
        // Internal GL account code/name must not leak onto the customer receipt.
        ->not->toContain($this->income->name)
        ->not->toContain($this->bank->name);
});
