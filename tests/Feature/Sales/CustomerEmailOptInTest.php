<?php

use App\Actions\Sales\SendInvoiceToCustomer;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\Sales\InvoiceSharedNotification;
use App\Services\Posting\InvoicePoster;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $this->company);
    $this->actingAs($this->user);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
});

afterEach(fn () => app()->forgetInstance('current_company'));

function optInPostedInvoice(Contact $customer): Invoice
{
    $invoice = Invoice::create([
        'company_id' => test()->company->id,
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-'.str()->random(6),
        'invoice_date' => '2026-06-01',
        'due_date' => '2026-06-30',
    ]);
    $invoice->lines()->create([
        'account_id' => test()->income->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'line_subtotal_cents' => 10000,
        'line_tax_cents' => 0,
        'line_total_cents' => 10000,
        'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($invoice);

    return $invoice->refresh();
}

it('leaves both email preferences off for a newly created customer', function () {
    $customer = Contact::create(['display_name' => 'Quiet Co', 'email' => 'q@x.test', 'is_customer' => true]);

    expect($customer->invoice_emails_enabled)->toBeFalse()
        ->and($customer->reminder_emails_enabled)->toBeFalse();
});

it('defaults both switches off on the customer create form', function () {
    Livewire::test('pages::customers.index', ['company' => $this->company])
        ->call('openCreate')
        ->assertSet('f_invoice_emails_enabled', false)
        ->assertSet('f_reminder_emails_enabled', false);
});

it('saves and reloads the email preferences from the customer form', function () {
    Livewire::test('pages::customers.index', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_display_name', 'Chatty Co')
        ->set('f_email', 'chatty@x.test')
        ->set('f_invoice_emails_enabled', true)
        ->set('f_reminder_emails_enabled', true)
        ->call('save')
        ->assertHasNoErrors();

    $customer = Contact::query()->where('display_name', 'Chatty Co')->firstOrFail();
    expect($customer->invoice_emails_enabled)->toBeTrue()
        ->and($customer->reminder_emails_enabled)->toBeTrue();

    // Editing rehydrates what was saved, rather than falling back to the defaults.
    Livewire::test('pages::customers.index', ['company' => $this->company])
        ->call('openEdit', $customer->id)
        ->assertSet('f_invoice_emails_enabled', true)
        ->assertSet('f_reminder_emails_enabled', true);
});

it('refuses to email an invoice to a customer who has not opted in', function () {
    Notification::fake();
    $customer = Contact::create(['display_name' => 'Quiet Co', 'email' => 'q@x.test', 'is_customer' => true]);
    $invoice = optInPostedInvoice($customer);

    $sent = app(SendInvoiceToCustomer::class)->handle($this->company, $invoice, ['q@x.test'], 'Here you go.');

    expect($sent)->toBeFalse();
    Notification::assertNothingSent();
});

it('emails an invoice once the customer has opted in', function () {
    Notification::fake();
    $customer = Contact::create(['display_name' => 'Chatty Co', 'email' => 'c@x.test', 'is_customer' => true, 'invoice_emails_enabled' => true]);
    $invoice = optInPostedInvoice($customer);

    $sent = app(SendInvoiceToCustomer::class)->handle($this->company, $invoice, ['c@x.test'], 'Here you go.');

    expect($sent)->toBeTrue();
    Notification::assertSentOnDemandTimes(InvoiceSharedNotification::class, 1);
});

it('still sends when a human uses the invoice send modal on an opted-out customer', function () {
    Notification::fake();
    $customer = Contact::create(['display_name' => 'Quiet Co', 'email' => 'q@x.test', 'is_customer' => true]);
    $invoice = optInPostedInvoice($customer);

    Livewire::test('pages::invoices.show', ['company' => $this->company, 'invoice' => $invoice])
        ->set('sendToEmail', 'q@x.test')
        ->set('sendMessage', 'Here you go.')
        ->call('sendToClient')
        ->assertHasNoErrors();

    Notification::assertSentOnDemandTimes(InvoiceSharedNotification::class, 1);

    // The one-off send does not silently opt the customer in.
    expect($customer->fresh()->invoice_emails_enabled)->toBeFalse();
});
