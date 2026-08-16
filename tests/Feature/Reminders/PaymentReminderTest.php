<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceReminderLog;
use App\Models\ReminderTier;
use App\Models\User;
use App\Notifications\Sales\InvoiceReminderNotification;
use App\Services\Posting\InvoicePoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-06-27 12:00:00');

    $this->company = Company::factory()->create(['timezone' => 'UTC']);
    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $this->company);
    $this->actingAs($this->user);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    // Reminder emails are opt-in, so the customer under test consents up front.
    $this->customer = Contact::create(['display_name' => 'Acme', 'email' => 'buyer@acme.test', 'is_customer' => true, 'reminder_emails_enabled' => true]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
    CarbonImmutable::setTestNow();
});

function reminderPostedInvoice(string $dueDate, int $cents = 10000): Invoice
{
    $invoice = Invoice::create([
        'company_id' => test()->company->id,
        'contact_id' => test()->customer->id,
        'invoice_no' => 'INV-'.str()->random(6),
        'invoice_date' => $dueDate,
        'due_date' => $dueDate,
    ]);
    $invoice->lines()->create([
        'account_id' => test()->income->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => $cents,
        'line_subtotal_cents' => $cents,
        'line_tax_cents' => 0,
        'line_total_cents' => $cents,
        'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($invoice);

    return $invoice->refresh();
}

function runReminders(): void
{
    test()->artisan('reminders:send', ['--sync' => true])->assertSuccessful();
}

it('seeds the default four tiers on first run', function () {
    runReminders();

    expect(ReminderTier::where('company_id', $this->company->id)->count())->toBe(4);
});

it('sends one overdue reminder and is idempotent across runs', function () {
    Notification::fake();
    reminderPostedInvoice('2026-06-20'); // 7 days overdue → the +7 tier

    runReminders();
    runReminders();

    Notification::assertSentOnDemandTimes(InvoiceReminderNotification::class, 1);
    expect(InvoiceReminderLog::where('company_id', $this->company->id)->count())->toBe(1);
});

it('sends a before-due heads-up at the -3 tier', function () {
    Notification::fake();
    reminderPostedInvoice('2026-06-29'); // due in 2 days → the -3 heads-up has fired

    runReminders();

    Notification::assertSentOnDemandTimes(InvoiceReminderNotification::class, 1);
    $tier = InvoiceReminderLog::first()->reminderTier;
    expect($tier->offset_days)->toBe(-3);
});

it('sends only the highest fired tier for a long-overdue invoice', function () {
    Notification::fake();
    reminderPostedInvoice('2026-05-28'); // 30 days overdue → all tiers fired, only +14 sends

    runReminders();

    Notification::assertSentOnDemandTimes(InvoiceReminderNotification::class, 1);
    expect(InvoiceReminderLog::first()->reminderTier->offset_days)->toBe(14);
});

it('skips invoices with reminders disabled, customers who have not opted in, or no email', function () {
    Notification::fake();

    $disabled = reminderPostedInvoice('2026-06-20');
    $disabled->update(['reminders_enabled' => false]);

    // The default for a new customer: no consent, so the sweep leaves them alone.
    $optedOutCustomer = Contact::create(['display_name' => 'Opted out', 'email' => 'quiet@x.test', 'is_customer' => true]);
    $optedOut = Invoice::create(['company_id' => $this->company->id, 'contact_id' => $optedOutCustomer->id, 'invoice_no' => 'INV-MUTE', 'invoice_date' => '2026-06-20', 'due_date' => '2026-06-20']);
    $optedOut->lines()->create(['account_id' => $this->income->id, 'description' => 'x', 'quantity' => '1', 'unit_price_cents' => 10000, 'line_subtotal_cents' => 10000, 'line_tax_cents' => 0, 'line_total_cents' => 10000, 'line_order' => 0]);
    app(InvoicePoster::class)->post($optedOut);

    $noEmailCustomer = Contact::create(['display_name' => 'NoEmail', 'is_customer' => true]);
    $noEmail = Invoice::create(['company_id' => $this->company->id, 'contact_id' => $noEmailCustomer->id, 'invoice_no' => 'INV-NOEM', 'invoice_date' => '2026-06-20', 'due_date' => '2026-06-20']);
    $noEmail->lines()->create(['account_id' => $this->income->id, 'description' => 'x', 'quantity' => '1', 'unit_price_cents' => 10000, 'line_subtotal_cents' => 10000, 'line_tax_cents' => 0, 'line_total_cents' => 10000, 'line_order' => 0]);
    app(InvoicePoster::class)->post($noEmail);

    runReminders();

    Notification::assertNothingSent();
});

it('does not remind a not-yet-due invoice before any tier fires', function () {
    Notification::fake();
    reminderPostedInvoice('2026-07-15'); // due far in the future, before the -3 heads-up

    runReminders();

    Notification::assertNothingSent();
});

it('lists due reminders on the worklist and sends one on demand', function () {
    Notification::fake();
    $invoice = reminderPostedInvoice('2026-06-20');

    Livewire::test('pages::reminders.index', ['company' => $this->company])
        ->assertOk()
        ->assertSee($invoice->invoice_no)
        ->assertSee('Acme')
        ->call('sendNow', $invoice->id)
        ->assertHasNoErrors();

    Notification::assertSentOnDemandTimes(InvoiceReminderNotification::class, 1);
    expect(InvoiceReminderLog::where('invoice_id', $invoice->id)->count())->toBe(1);
});

it('turns off reminders for a customer and an invoice from the worklist', function () {
    $invoice = reminderPostedInvoice('2026-06-20');

    $page = Livewire::test('pages::reminders.index', ['company' => $this->company]);

    $page->call('snoozeInvoice', $invoice->id);
    expect($invoice->fresh()->reminders_enabled)->toBeFalse();

    $page->call('disableReminders', $this->customer->id);
    expect($this->customer->fresh()->reminder_emails_enabled)->toBeFalse();

    // With the invoice off and the customer opted out, nothing remains due.
    expect($page->instance()->dueItems)->toHaveCount(0);
});

it('hides opted-out customers from the worklist until the reveal switch is on', function () {
    $this->customer->update(['reminder_emails_enabled' => false]);
    $invoice = reminderPostedInvoice('2026-06-20');

    $page = Livewire::test('pages::reminders.index', ['company' => $this->company])
        ->assertDontSee($invoice->invoice_no);

    $page->set('showOptedOut', true)->assertSee($invoice->invoice_no);
});

it('sends on demand to an opted-out customer, without opting them back in', function () {
    Notification::fake();
    $this->customer->update(['reminder_emails_enabled' => false]);
    $invoice = reminderPostedInvoice('2026-06-20');

    Livewire::test('pages::reminders.index', ['company' => $this->company])
        ->set('showOptedOut', true)
        ->call('sendNow', $invoice->id)
        ->assertHasNoErrors();

    Notification::assertSentOnDemandTimes(InvoiceReminderNotification::class, 1);
    expect($this->customer->fresh()->reminder_emails_enabled)->toBeFalse();
});
