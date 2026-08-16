<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Services\Posting\InvoicePoster;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();

    $this->alice = Contact::create(['display_name' => 'Alice', 'email' => 'alice@x.test', 'is_customer' => true]);
    $this->bob = Contact::create(['display_name' => 'Bob', 'email' => 'bob@x.test', 'is_customer' => true]);

    $this->bobInvoice = postedInvoiceFor($this, $this->bob, 'INV-BOB-1', 9000);
});

afterEach(fn () => app()->forgetInstance('current_company'));

function postedInvoiceFor(object $test, Contact $customer, string $no, int $cents): Invoice
{
    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => $no,
        'invoice_date' => CarbonImmutable::create(2026, 5, 1),
        'due_date' => CarbonImmutable::create(2026, 5, 31),
    ]);
    $invoice->lines()->create([
        'account_id' => $test->income->id,
        'description' => 'Services',
        'quantity' => '1',
        'unit_price_cents' => $cents,
        'line_subtotal_cents' => $cents,
        'line_tax_cents' => 0,
        'line_total_cents' => $cents,
        'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($invoice);

    return $invoice->fresh();
}

it('redirects unauthenticated visitors to the portal login, not the staff login', function () {
    $this->get(route('portal.dashboard', ['company' => $this->company->slug]))
        ->assertRedirect(route('portal.login', ['company' => $this->company->slug]));
});

it('shows only the signed-in customer’s own open invoices on the dashboard', function () {
    postedInvoiceFor($this, $this->alice, 'INV-ALICE-1', 5000);

    $this->actingAs($this->alice, 'customer');

    Livewire::test('pages::portal.dashboard', ['company' => $this->company])
        ->assertSee('INV-ALICE-1')
        ->assertDontSee('INV-BOB-1');
});

it('blocks downloading another customer’s invoice PDF', function () {
    $this->actingAs($this->alice, 'customer');

    $this->get(route('portal.invoices.pdf', ['company' => $this->company->slug, 'invoice' => $this->bobInvoice->id]))
        ->assertNotFound();
});

it('lets a customer download their own invoice PDF', function () {
    $this->actingAs($this->bob, 'customer');

    $this->get(route('portal.invoices.pdf', ['company' => $this->company->slug, 'invoice' => $this->bobInvoice->id]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});
