<?php

use App\Actions\Sales\SendInvoiceToCustomer;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceSetting;
use App\Models\Member;
use App\Models\PortalLoginLink;
use App\Models\User;
use App\Notifications\Sales\InvoiceSharedNotification;
use App\Services\Posting\InvoicePoster;
use App\Services\Reporting\InvoicePdfRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();

    $this->customer = Contact::create(['display_name' => 'Acme', 'email' => 'buyer@acme.test', 'is_customer' => true]);

    $this->invoice = Invoice::create([
        'contact_id' => $this->customer->id,
        'invoice_no' => 'INV-SEND-1',
        'invoice_date' => CarbonImmutable::create(2026, 5, 1),
        'due_date' => CarbonImmutable::create(2026, 5, 31),
    ]);
    $this->invoice->lines()->create([
        'account_id' => $income->id,
        'description' => 'Services',
        'quantity' => '1',
        'unit_price_cents' => 5000,
        'line_subtotal_cents' => 5000,
        'line_tax_cents' => 0,
        'line_total_cents' => 5000,
        'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($this->invoice);
    $this->invoice->refresh();
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('issues a deep-linked magic token and emails the invoice', function () {
    Notification::fake();

    InvoiceSetting::create(['company_id' => $this->company->id, 'email_from_address' => 'billing@acme.test', 'email_from_name' => 'Acme Billing']);

    app(SendInvoiceToCustomer::class)->handle($this->company, $this->invoice, ['someone@client.test'], 'Thanks for your business.', bypassOptIn: true);

    $link = PortalLoginLink::where('contact_id', $this->customer->id)->first();
    expect($link)->not->toBeNull()
        ->and($link->intended_path)->toBe('/pay/'.$this->company->slug.'/invoices/'.$this->invoice->id);

    Notification::assertSentOnDemand(
        InvoiceSharedNotification::class,
        function (InvoiceSharedNotification $notification, array $channels, AnonymousNotifiable $notifiable) {
            return $notifiable->routeNotificationFor('mail') === ['someone@client.test']
                && $notification->message === 'Thanks for your business.'
                && $notification->replyToAddress === 'billing@acme.test';
        },
    );
});

it('carries CC and BCC recipients through to the notification', function () {
    Notification::fake();

    app(SendInvoiceToCustomer::class)->handle(
        $this->company,
        $this->invoice,
        ['buyer@acme.test'],
        'Note',
        ['cc@acme.test'],
        ['bcc@acme.test'],
        bypassOptIn: true,
    );

    Notification::assertSentOnDemand(
        InvoiceSharedNotification::class,
        fn (InvoiceSharedNotification $n) => $n->cc === ['cc@acme.test'] && $n->bcc === ['bcc@acme.test'],
    );
});

it('emails from a no-reply address and drops the platform branding', function () {
    $this->mock(InvoicePdfRenderer::class, function ($mock) {
        $mock->shouldReceive('raw')->andReturn('%PDF-1.4 fake');
        $mock->shouldReceive('filename')->andReturn('invoice.pdf');
    });

    $notification = new InvoiceSharedNotification(
        invoice: $this->invoice,
        company: $this->company,
        payUrl: 'https://books.test/pay/'.$this->company->slug.'/login/tok',
        message: 'Please find your invoice attached.',
        replyToAddress: 'billing@acme.test',
        senderName: 'Acme Billing',
    );

    $mail = $notification->toMail(new AnonymousNotifiable);

    expect($mail->from[0])->toStartWith('no-reply@')
        ->and($mail->replyTo[0][0])->toBe('billing@acme.test');

    $companyName = $this->company->brand_name ?: $this->company->name;
    $html = (string) app(Markdown::class)->render($mail->markdown, $mail->viewData);
    $body = Str::after($html, '</head>');

    expect($body)->not->toContain('LineLedger')
        ->and($html)->not->toContain('Regards')
        ->and($body)->toContain($companyName);
});

it('labels the send action for a member when the invoice belongs to one', function () {
    $member = Member::factory()->create();
    $this->invoice->update(['member_id' => $member->id]);

    Livewire::test('pages::invoices.show', ['company' => $this->company, 'invoice' => $this->invoice->fresh()])
        ->assertSeeHtml('Send to member')
        ->assertSeeHtml('Send invoice to member');
});

it('labels the send action for a client when the invoice has no member', function () {
    Livewire::test('pages::invoices.show', ['company' => $this->company, 'invoice' => $this->invoice])
        ->assertSeeHtml('Send to client')
        ->assertSeeHtml('Send invoice to client');
});

it('sends from the invoice page modal to the confirmed address', function () {
    Notification::fake();

    Livewire::test('pages::invoices.show', ['company' => $this->company, 'invoice' => $this->invoice])
        ->assertSet('sendToEmail', 'buyer@acme.test')
        ->set('sendToEmail', 'changed@client.test')
        ->set('sendMessage', 'Custom note')
        ->call('sendToClient')
        ->assertHasNoErrors();

    Notification::assertSentOnDemand(
        InvoiceSharedNotification::class,
        fn ($n, $channels, AnonymousNotifiable $notifiable) => $notifiable->routeNotificationFor('mail') === ['changed@client.test'],
    );
});

it('validates the recipient email', function () {
    Notification::fake();

    Livewire::test('pages::invoices.show', ['company' => $this->company, 'invoice' => $this->invoice])
        ->set('sendToEmail', 'not-an-email')
        ->call('sendToClient')
        ->assertHasErrors(['sendToEmail']);

    Notification::assertNothingSent();
});

it('sends to multiple comma-separated recipients and CCs the sender when opted in', function () {
    Notification::fake();
    InvoiceSetting::create(['company_id' => $this->company->id, 'email_cc_self' => true]);

    Livewire::test('pages::invoices.show', ['company' => $this->company, 'invoice' => $this->invoice])
        ->assertSet('sendCcSelf', true)
        ->set('sendToEmail', 'a@client.test, b@client.test')
        ->set('sendCc', 'cc@client.test')
        ->call('sendToClient')
        ->assertHasNoErrors();

    Notification::assertSentOnDemand(
        InvoiceSharedNotification::class,
        function (InvoiceSharedNotification $n, array $channels, AnonymousNotifiable $notifiable) {
            return $notifiable->routeNotificationFor('mail') === ['a@client.test', 'b@client.test']
                && in_array('cc@client.test', $n->cc, true)
                && in_array($this->user->email, $n->cc, true);
        },
    );
});

it('rejects a malformed CC address without sending', function () {
    Notification::fake();

    Livewire::test('pages::invoices.show', ['company' => $this->company, 'invoice' => $this->invoice])
        ->set('sendCc', 'good@client.test, bad-email')
        ->call('sendToClient')
        ->assertHasErrors(['sendCc']);

    Notification::assertNothingSent();
});

it('deep-links to the invoice after consuming the token', function () {
    $token = 'deeplinktoken';
    PortalLoginLink::create([
        'company_id' => $this->company->id,
        'contact_id' => $this->customer->id,
        'token_hash' => PortalLoginLink::hashToken($token),
        'expires_at' => CarbonImmutable::now()->addMinutes(10),
        'intended_path' => '/pay/'.$this->company->slug.'/invoices/'.$this->invoice->id,
    ]);

    $this->get(route('portal.login.consume', ['company' => $this->company->slug, 'token' => $token]))
        ->assertRedirect('/pay/'.$this->company->slug.'/invoices/'.$this->invoice->id);
});

it('shows the customer their own invoice but 404s on another', function () {
    $other = Contact::create(['display_name' => 'Other', 'email' => 'o@x.test', 'is_customer' => true]);

    $this->actingAs($this->customer, 'customer');

    Livewire::test('pages::portal.invoice', ['company' => $this->company, 'invoice' => $this->invoice])
        ->assertSee('INV-SEND-1');

    $this->actingAs($other, 'customer');
    Livewire::test('pages::portal.invoice', ['company' => $this->company, 'invoice' => $this->invoice])
        ->assertStatus(404);
});
