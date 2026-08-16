<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Posting\CreditMemoPoster;
use App\Services\Posting\InvoicePoster;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function postInvoiceFor(object $test, Contact $customer, string $no, int $cents): Invoice
{
    $inv = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => $no,
        'invoice_date' => CarbonImmutable::create(2026, 5, 20),
        'due_date' => CarbonImmutable::create(2026, 6, 20),
    ]);

    $inv->lines()->create([
        'account_id' => $test->income->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => $cents,
        'line_subtotal_cents' => $cents,
        'line_tax_cents' => 0,
        'line_total_cents' => $cents,
        'line_order' => 0,
    ]);

    app(InvoicePoster::class)->post($inv);

    return $inv->fresh();
}

it('lists recent posted invoices in the quick-pick dropdown', function () {
    $customer = Contact::create(['display_name' => 'Recent Co', 'is_customer' => true]);
    postInvoiceFor($this, $customer, 'REC-INV-1', 5000);

    $numbers = Livewire::test('pages::receipts.form', ['company' => $this->company])
        ->get('recentInvoices')
        ->pluck('invoice_no')
        ->all();

    expect($numbers)->toContain('REC-INV-1');
});

it('selects the customer and loads their open invoices when picking a recent invoice', function () {
    $customer = Contact::create(['display_name' => 'Recent Co', 'is_customer' => true]);
    $invoice = postInvoiceFor($this, $customer, 'REC-INV-2', 8000);

    $component = Livewire::test('pages::receipts.form', ['company' => $this->company])
        ->set('recent_invoice_id', (string) $invoice->id)
        ->assertSet('contact_id', $customer->id)
        // The dropdown resets after selecting.
        ->assertSet('recent_invoice_id', '');

    expect($component->get('applyTable'))->toHaveCount(1)
        ->and($component->get('applyTable')[0]['invoice_id'])->toBe($invoice->id)
        // "Customer only" — amount stays blank, so nothing is auto-applied yet.
        ->and($component->get('amount'))->toBe('');
});

it('pre-loads the customer preferred payment method when one is set', function () {
    $method = PaymentMethod::create(['name' => 'EFT', 'is_active' => true]);
    $customer = Contact::create([
        'display_name' => 'Prefers EFT Co',
        'is_customer' => true,
        'preferred_payment_method_id' => $method->id,
    ]);

    // Via the customer combo.
    Livewire::test('pages::receipts.form', ['company' => $this->company])
        ->call('selectContact', $customer->id)
        ->assertSet('payment_method_id', $method->id);

    // Via the recent-invoices quick-pick.
    $invoice = postInvoiceFor($this, $customer, 'PREF-1', 5000);

    Livewire::test('pages::receipts.form', ['company' => $this->company])
        ->set('recent_invoice_id', (string) $invoice->id)
        ->assertSet('payment_method_id', $method->id);
});

it('shows the customer credit and net balance when a credit memo exists', function () {
    $customer = Contact::create(['display_name' => 'TBMC Ltd.', 'is_customer' => true]);
    postInvoiceFor($this, $customer, 'INV-CR-1', 55500); // $555 invoice

    $memo = CreditMemo::create([
        'contact_id' => $customer->id,
        'credit_memo_no' => 'CM-50',
        'credit_memo_date' => CarbonImmutable::create(2026, 5, 21),
    ]);
    $memo->lines()->create([
        'account_id' => $this->income->id, 'description' => 'credit', 'quantity' => '1',
        'unit_price_cents' => 5000, 'line_subtotal_cents' => 5000,
        'line_tax_cents' => 0, 'line_total_cents' => 5000, 'line_order' => 0,
    ]);
    app(CreditMemoPoster::class)->post($memo);

    $statementUrl = route('reports.contact-statement', ['company' => $this->company->slug, 'contact' => $customer->id, 'kind' => 'ar']);

    $summary = Livewire::test('pages::receipts.form', ['company' => $this->company])
        ->call('selectContact', $customer->id)
        ->assertSeeHtml('data-test="receipt-credit-summary"')
        ->assertSeeHtml('data-test="receipt-credit-statement-link"')
        ->assertSeeHtml($statementUrl)
        ->get('creditSummary');

    expect($summary['credit'])->toBe(5000)
        ->and($summary['open_invoices'])->toBe(55500)
        ->and($summary['net'])->toBe(50500);
});
