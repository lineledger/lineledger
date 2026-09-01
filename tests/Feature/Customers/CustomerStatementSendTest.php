<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\CustomerStatementType;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceSetting;
use App\Models\User;
use App\Notifications\Sales\CustomerStatementNotification;
use App\Services\Posting\InvoicePoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'owner@example.com']);
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->customer = Contact::factory()->customer()->create(['email' => 'customer@example.com']);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('emails the statement to the parsed recipients with CC-self applied', function () {
    Notification::fake();

    Livewire::test('pages::customers.index', ['company' => $this->company])
        ->call('openStatement', $this->customer->id)
        ->set('statementToEmail', 'customer@example.com, second@example.com')
        ->set('statementCc', 'bookkeeper@example.com')
        ->set('statementCcSelf', true)
        ->set('statementMessage', 'Here is your statement.')
        ->call('emailStatement')
        ->assertHasNoErrors();

    Notification::assertSentOnDemand(
        CustomerStatementNotification::class,
        function (CustomerStatementNotification $notification, array $channels, object $notifiable): bool {
            return $notifiable->routes['mail'] === ['customer@example.com', 'second@example.com']
                && $notification->cc === ['bookkeeper@example.com', 'owner@example.com']
                && $notification->type === CustomerStatementType::OpenInvoices
                && $notification->message === 'Here is your statement.'
                && $notification->contact->is($this->customer);
        },
    );
});

it('rejects a malformed recipient address', function () {
    Notification::fake();

    Livewire::test('pages::customers.index', ['company' => $this->company])
        ->call('openStatement', $this->customer->id)
        ->set('statementToEmail', 'not-an-email')
        ->call('emailStatement')
        ->assertHasErrors(['statementToEmail']);

    Notification::assertNothingSent();
});

it('renders the mail with the PDF attached and Reply-To from invoice settings', function () {
    // Post an invoice so the statement carries real content.
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $invoice = Invoice::create([
        'contact_id' => $this->customer->id,
        'invoice_no' => 'INV-MAIL-1',
        'invoice_date' => CarbonImmutable::create(2026, 5, 1),
        'due_date' => CarbonImmutable::create(2026, 5, 31),
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

    InvoiceSetting::updateOrCreate(['company_id' => $this->company->id], [
        ...InvoiceSetting::defaults(),
        'email_from_address' => 'billing@example.com',
    ]);

    $notification = new CustomerStatementNotification(
        contact: $this->customer,
        company: $this->company,
        type: CustomerStatementType::OpenInvoices,
        start: null,
        end: '2026-06-01',
        statementUrl: 'https://example.com/statement-link',
        message: 'Here is your statement.',
        replyToAddress: 'billing@example.com',
        senderName: null,
        cc: [],
    );

    // Deliver as the queue worker would: with no tenant context bound. The
    // builder's explicit company_id filters must carry the statement through.
    app()->forgetInstance('current_company');

    $mail = $notification->toMail((object) []);

    app()->instance('current_company', $this->company);

    expect($mail->subject)->toContain('Statement from')
        ->and($mail->replyTo[0][0])->toBe('billing@example.com')
        ->and($mail->rawAttachments)->toHaveCount(1)
        ->and($mail->rawAttachments[0]['name'])->toStartWith('statement-')
        ->and($mail->rawAttachments[0]['name'])->toEndWith('.pdf')
        ->and($mail->rawAttachments[0]['options']['mime'])->toBe('application/pdf')
        ->and($mail->viewData['actionUrl'])->toBe('https://example.com/statement-link')
        ->and($mail->viewData['detailLine'])->toContain('200.00');
});
