<?php

use App\Actions\Recurring\SaveRecurringDocument;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\BillStatus;
use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\RecurringDocument;
use App\Models\TaxCode;
use App\Notifications\Sales\InvoiceSharedNotification;
use App\Services\Recurring\RecurringDocumentGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-05-24 12:00:00');

    $this->company = Company::factory()->create(['timezone' => 'UTC']);
    app()->instance('current_company', $this->company);

    $this->customer = Contact::create(['display_name' => 'Acme Corp', 'is_customer' => true]);
    $this->vendor = Contact::create(['display_name' => 'Landlord LLC', 'is_vendor' => true]);

    $this->incomeAccount = Account::query()->where('subtype', AccountSubtype::Income->value)->firstOrFail();
    $this->expenseAccount = Account::query()->where('type', AccountType::Expense->value)->firstOrFail();
    $this->gst = TaxCode::where('code', 'GST')->firstOrFail();

    $this->today = $this->company->currentDateTime()->toDateString();
});

afterEach(function () {
    app()->forgetInstance('current_company');
    CarbonImmutable::setTestNow();
});

function makeInvoiceSchedule(array $overrides = []): RecurringDocument
{
    return app(SaveRecurringDocument::class)->handle(array_merge([
        'document_type' => 'invoice',
        'contact_id' => test()->customer->id,
        'terms_id' => null,
        'memo' => 'Monthly retainer',
        'name' => 'Acme retainer',
        'frequency' => 'monthly',
        'start_date' => test()->today,
        'day_of_month' => (int) test()->company->currentDateTime()->format('j'),
        'end_type' => 'never',
        'lines' => [[
            'item_id' => null,
            'account_id' => test()->incomeAccount->id,
            'description' => 'Service',
            'quantity' => '1',
            'unit_price_cents' => 10000,
            'tax_code_id' => test()->gst->id,
        ]],
    ], $overrides));
}

function makeBillSchedule(array $overrides = []): RecurringDocument
{
    return app(SaveRecurringDocument::class)->handle(array_merge([
        'document_type' => 'bill',
        'bill_type' => 'vendor',
        'vendor_reference' => 'RENT-2026',
        'contact_id' => test()->vendor->id,
        'terms_id' => null,
        'memo' => 'Monthly rent',
        'name' => 'Office rent',
        'frequency' => 'monthly',
        'start_date' => test()->today,
        'day_of_month' => (int) test()->company->currentDateTime()->format('j'),
        'end_type' => 'never',
        'lines' => [[
            'item_id' => null,
            'account_id' => test()->expenseAccount->id,
            'description' => 'Rent',
            'quantity' => '1',
            'unit_price_cents' => 200000,
            'tax_code_id' => null,
        ]],
    ], $overrides));
}

function runGenerate(): void
{
    test()->artisan('recurring:generate', ['--sync' => true])->assertSuccessful();
}

it('generates a draft invoice for a due schedule without touching the ledger', function () {
    $schedule = makeInvoiceSchedule();
    $jeBefore = JournalEntry::count();

    runGenerate();

    $invoices = Invoice::query()->where('recurring_document_id', $schedule->id)->get();
    expect($invoices)->toHaveCount(1);

    $invoice = $invoices->first();
    expect($invoice->status)->toBe(InvoiceStatus::Draft)
        ->and($invoice->journal_entry_id)->toBeNull()
        ->and($invoice->company_id)->toBe($this->company->id)
        ->and($invoice->contact_id)->toBe($this->customer->id)
        ->and($invoice->invoice_date->toDateString())->toBe($this->today)
        ->and($invoice->invoice_no)->not->toBeNull()
        ->and($invoice->subtotal_cents)->toBe(10000)
        ->and($invoice->lines)->toHaveCount(1);

    // Generation must never post.
    expect(JournalEntry::count())->toBe($jeBefore);

    // Schedule advanced one month into the future.
    $schedule->refresh();
    expect($schedule->occurrences_generated)->toBe(1)
        ->and($schedule->next_run_date->toDateString())->toBe('2026-06-24')
        ->and($schedule->is_active)->toBeTrue();
});

it('generates a draft vendor bill with the BILL prefix', function () {
    $schedule = makeBillSchedule();

    runGenerate();

    $bill = Bill::query()->where('recurring_document_id', $schedule->id)->firstOrFail();
    expect($bill->status)->toBe(BillStatus::Draft)
        ->and($bill->journal_entry_id)->toBeNull()
        ->and($bill->vendor_reference)->toBe('RENT-2026')
        ->and($bill->bill_no)->toStartWith('BILL-')
        ->and($bill->total_cents)->toBe(200000)
        ->and($bill->contact_id)->toBe($this->vendor->id);
});

it('does not double-generate when run twice on the same day', function () {
    $schedule = makeInvoiceSchedule();

    runGenerate();
    runGenerate();

    expect(Invoice::query()->where('recurring_document_id', $schedule->id)->count())->toBe(1);
});

it('catches up every missed occurrence in one run', function () {
    // Two weeks ago, weekly: occurrences at -14, -7, and today = 3 drafts.
    $start = $this->company->currentDateTime()->subWeeks(2)->toDateString();
    $schedule = makeInvoiceSchedule(['frequency' => 'weekly', 'day_of_month' => null, 'start_date' => $start]);

    runGenerate();

    $schedule->refresh();
    expect(Invoice::query()->where('recurring_document_id', $schedule->id)->count())->toBe(3)
        ->and($schedule->occurrences_generated)->toBe(3)
        ->and($schedule->next_run_date->isFuture())->toBeTrue();
});

it('deactivates after the configured number of occurrences', function () {
    $start = $this->company->currentDateTime()->subWeeks(2)->toDateString();
    $schedule = makeInvoiceSchedule([
        'frequency' => 'weekly',
        'day_of_month' => null,
        'start_date' => $start,
        'end_type' => 'after_occurrences',
        'max_occurrences' => 2,
    ]);

    runGenerate();

    $schedule->refresh();
    expect(Invoice::query()->where('recurring_document_id', $schedule->id)->count())->toBe(2)
        ->and($schedule->is_active)->toBeFalse()
        ->and($schedule->next_run_date)->toBeNull();
});

it('deactivates once the end date has passed', function () {
    $start = $this->company->currentDateTime()->subWeeks(2)->toDateString();
    $endDate = $this->company->currentDateTime()->subWeek()->toDateString();
    $schedule = makeInvoiceSchedule([
        'frequency' => 'weekly',
        'day_of_month' => null,
        'start_date' => $start,
        'end_type' => 'on_date',
        'end_date' => $endDate,
    ]);

    runGenerate();

    $schedule->refresh();
    // Occurrences at -14 and -7 only; advancing to today exceeds the end date.
    expect(Invoice::query()->where('recurring_document_id', $schedule->id)->count())->toBe(2)
        ->and($schedule->is_active)->toBeFalse()
        ->and($schedule->next_run_date)->toBeNull();
});

it('skips paused schedules', function () {
    $schedule = makeInvoiceSchedule();
    $schedule->update(['is_active' => false]);

    runGenerate();

    expect(Invoice::query()->where('recurring_document_id', $schedule->id)->count())->toBe(0);
});

it('generates across all companies in console context with no bound company', function () {
    makeInvoiceSchedule();

    $companyB = Company::factory()->create(['timezone' => 'UTC']);
    app()->instance('current_company', $companyB);

    $customerB = Contact::create(['display_name' => 'Beta Inc', 'is_customer' => true]);
    $incomeB = Account::query()->where('subtype', AccountSubtype::Income->value)->firstOrFail();

    app(SaveRecurringDocument::class)->handle([
        'document_type' => 'invoice',
        'contact_id' => $customerB->id,
        'name' => 'Beta retainer',
        'frequency' => 'monthly',
        'start_date' => $companyB->currentDateTime()->toDateString(),
        'day_of_month' => (int) $companyB->currentDateTime()->format('j'),
        'end_type' => 'never',
        'lines' => [[
            'item_id' => null,
            'account_id' => $incomeB->id,
            'description' => 'Service',
            'quantity' => '1',
            'unit_price_cents' => 5000,
            'tax_code_id' => null,
        ]],
    ]);

    // Simulate the scheduler: no tenant bound in the container.
    app()->forgetInstance('current_company');

    runGenerate();

    expect(Invoice::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->count())->toBe(1)
        ->and(Invoice::query()->withoutGlobalScopes()->where('company_id', $companyB->id)->count())->toBe(1);
});

it('pauses a schedule whose account was deleted instead of generating', function () {
    $schedule = makeInvoiceSchedule();

    // Remove the line account out from under the schedule.
    Account::query()->whereKey($this->incomeAccount->id)->delete();

    app(RecurringDocumentGenerator::class)->generateDue(
        $schedule,
        $this->company->currentDateTime()->startOfDay(),
    );

    $schedule->refresh();
    expect(Invoice::query()->where('recurring_document_id', $schedule->id)->count())->toBe(0)
        ->and($schedule->is_active)->toBeFalse()
        ->and($schedule->paused_reason)->not->toBeNull();
});

it('auto-posts an invoice schedule set to post automatically, without emailing', function () {
    Notification::fake();
    $jeBefore = JournalEntry::count();

    $schedule = makeInvoiceSchedule(['automation_mode' => 'post']);
    runGenerate();

    $invoice = Invoice::query()->where('recurring_document_id', $schedule->id)->firstOrFail();
    expect($invoice->status)->toBe(InvoiceStatus::Posted)
        ->and($invoice->journal_entry_id)->not->toBeNull()
        ->and(JournalEntry::count())->toBeGreaterThan($jeBefore);

    Notification::assertNothingSent();
});

it('auto-posts and emails the customer when set to post and email', function () {
    Notification::fake();
    $this->customer->update(['email' => 'buyer@acme.test', 'invoice_emails_enabled' => true]);

    $schedule = makeInvoiceSchedule(['automation_mode' => 'post_and_email']);
    runGenerate();

    $invoice = Invoice::query()->where('recurring_document_id', $schedule->id)->firstOrFail();
    expect($invoice->status)->toBe(InvoiceStatus::Posted);

    Notification::assertSentOnDemandTimes(InvoiceSharedNotification::class, 1);
});

it('posts but does not email when the customer has no email', function () {
    Notification::fake();
    // Customer created in beforeEach has no email.

    $schedule = makeInvoiceSchedule(['automation_mode' => 'post_and_email']);
    runGenerate();

    $invoice = Invoice::query()->where('recurring_document_id', $schedule->id)->firstOrFail();
    expect($invoice->status)->toBe(InvoiceStatus::Posted);

    Notification::assertNothingSent();
});

it('posts but does not email when the customer has not opted in to invoice emails', function () {
    Notification::fake();
    // Has an email, but no consent — the default for every new customer.
    $this->customer->update(['email' => 'buyer@acme.test']);

    $schedule = makeInvoiceSchedule(['automation_mode' => 'post_and_email']);
    runGenerate();

    $invoice = Invoice::query()->where('recurring_document_id', $schedule->id)->firstOrFail();
    expect($invoice->status)->toBe(InvoiceStatus::Posted);

    Notification::assertNothingSent();
});

it('degrades auto-post to a draft when the period is locked, without failing the run', function () {
    Notification::fake();
    $this->customer->update(['email' => 'buyer@acme.test', 'invoice_emails_enabled' => true]);
    // Lock the books through today so posting today is rejected.
    $this->company->update(['lock_date' => $this->today]);
    $jeBefore = JournalEntry::count();

    $schedule = makeInvoiceSchedule(['automation_mode' => 'post_and_email']);
    runGenerate();

    $invoice = Invoice::query()->where('recurring_document_id', $schedule->id)->firstOrFail();
    expect($invoice->status)->toBe(InvoiceStatus::Draft)
        ->and($invoice->journal_entry_id)->toBeNull()
        ->and(JournalEntry::count())->toBe($jeBefore);

    // A draft is never emailed, and the schedule still advanced.
    Notification::assertNothingSent();
    expect($schedule->fresh()->occurrences_generated)->toBe(1);
});

it('on catch-up posts every occurrence but emails only the most recent', function () {
    Notification::fake();
    $this->customer->update(['email' => 'buyer@acme.test', 'invoice_emails_enabled' => true]);

    // Two weeks ago, weekly → 3 occurrences (-14, -7, today).
    $start = $this->company->currentDateTime()->subWeeks(2)->toDateString();
    $schedule = makeInvoiceSchedule([
        'automation_mode' => 'post_and_email',
        'frequency' => 'weekly',
        'day_of_month' => null,
        'start_date' => $start,
    ]);
    runGenerate();

    $invoices = Invoice::query()->where('recurring_document_id', $schedule->id)->get();
    expect($invoices)->toHaveCount(3)
        ->and($invoices->every(fn ($i) => $i->status === InvoiceStatus::Posted))->toBeTrue();

    // Posted all three, but only the latest is emailed.
    Notification::assertSentOnDemandTimes(InvoiceSharedNotification::class, 1);
});
