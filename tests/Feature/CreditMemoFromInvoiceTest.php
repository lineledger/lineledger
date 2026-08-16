<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\TaxCalculator;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->customer = Contact::create(['display_name' => 'Acme Corp', 'is_customer' => true]);
    $this->incomeAccount = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function makeSourceInvoice(): Invoice
{
    $gst = TaxCode::where('code', 'GST')->firstOrFail();

    $invoice = Invoice::create([
        'contact_id' => test()->customer->id,
        'invoice_no' => 'INV-SRC-1',
        'invoice_date' => now()->subDays(5)->toDateString(),
        'due_date' => now()->addDays(25)->toDateString(),
        'memo' => 'Consulting',
    ]);

    $totals = app(TaxCalculator::class)->line('2', 50000, $gst);

    $invoice->lines()->create([
        'account_id' => test()->incomeAccount->id,
        'description' => 'Consulting hours',
        'quantity' => '2',
        'unit_price_cents' => 50000,
        'tax_code_id' => $gst->id,
        'line_subtotal_cents' => $totals['subtotal_cents'],
        'line_tax_cents' => $totals['tax_cents'],
        'line_total_cents' => $totals['total_cents'],
        'line_order' => 0,
    ]);

    app(InvoicePoster::class)->post($invoice);

    return $invoice->fresh('lines');
}

it('prefills a new credit memo form from a source invoice', function () {
    $source = makeSourceInvoice();
    $gst = TaxCode::where('code', 'GST')->firstOrFail();

    $component = Livewire::withQueryParams(['invoice' => $source->id])
        ->test('pages::credit-memos.form', ['company' => $this->company]);

    $component
        ->assertSet('contact_id', $this->customer->id)
        ->assertSet('memo', 'Credit for invoice INV-SRC-1')
        ->assertSet('credit_memo_date', $this->company->currentDateTime()->toDateString())
        ->assertCount('lines', 1)
        ->assertSet('lines.0.account_id', $this->incomeAccount->id)
        ->assertSet('lines.0.description', 'Consulting hours')
        ->assertSet('lines.0.quantity', '2')
        ->assertSet('lines.0.unit_price', '500.00')
        ->assertSet('lines.0.tax_code_id', $gst->id);

    // credit_memo_no should be a freshly generated number, not derived from the invoice
    expect($component->get('credit_memo_no'))->not->toBe('INV-SRC-1');
});

it('ignores invoice= when the source invoice belongs to a different company', function () {
    $otherCompany = Company::factory()->create();
    app()->instance('current_company', $otherCompany);
    $otherCustomer = Contact::create(['display_name' => 'Other Customer', 'is_customer' => true]);
    $otherIncome = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();

    $otherInvoice = Invoice::create([
        'contact_id' => $otherCustomer->id,
        'invoice_no' => 'INV-OTHER-1',
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->toDateString(),
    ]);

    $otherInvoice->lines()->create([
        'account_id' => $otherIncome->id,
        'description' => 'Other co line',
        'quantity' => '1',
        'unit_price_cents' => 5000,
        'line_subtotal_cents' => 5000,
        'line_tax_cents' => 0,
        'line_total_cents' => 5000,
        'line_order' => 0,
    ]);

    app()->instance('current_company', $this->company);

    Livewire::withQueryParams(['invoice' => $otherInvoice->id])
        ->test('pages::credit-memos.form', ['company' => $this->company])
        ->assertSet('contact_id', null)
        ->assertSet('memo', '')
        ->assertSet('lines.0.description', '');
});
