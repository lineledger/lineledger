<?php

use App\Actions\Sales\SendCustomerStatement;
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
use App\Services\Reporting\PdfExporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

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

it('queues the statement with sanitized columns and the open-invoices start', function () {
    Notification::fake();

    app(SendCustomerStatement::class)->handle(
        $this->company,
        $this->customer,
        CustomerStatementType::OpenInvoices,
        CarbonImmutable::create(2026, 1, 1),
        CarbonImmutable::create(2026, 6, 1),
        ['customer@example.com'],
        'Here is your statement.',
        [],
        ['paid', 'bogus', 'po'],
    );

    Notification::assertSentOnDemand(
        CustomerStatementNotification::class,
        fn (CustomerStatementNotification $notification): bool => $notification->columns === ['po', 'paid']
            && $notification->start === '2026-01-01'
            && $notification->end === '2026-06-01',
    );
});

it('leaves the columns to the saved default when none are given', function () {
    Notification::fake();

    app(SendCustomerStatement::class)->handle(
        $this->company,
        $this->customer,
        CustomerStatementType::Activity,
        CarbonImmutable::create(2026, 1, 1),
        CarbonImmutable::create(2026, 6, 1),
        ['customer@example.com'],
        '',
    );

    Notification::assertSentOnDemand(
        CustomerStatementNotification::class,
        fn (CustomerStatementNotification $notification): bool => $notification->columns === null,
    );
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

/**
 * Deliver $notification as the queue worker would (nothing bound), capturing
 * the view data its attached PDF is rendered from.
 *
 * @return array<string, mixed>
 */
function captureStatementAttachment(CustomerStatementNotification $notification, Company $company): array
{
    $captured = [];

    $pdf = Mockery::mock(PdfExporter::class);
    $pdf->shouldReceive('raw')->once()->andReturnUsing(function (string $view, array $data) use (&$captured) {
        $captured = $data;

        return '%PDF-fake';
    });
    app()->instance(PdfExporter::class, $pdf);

    app()->forgetInstance('current_company');
    $notification->toMail((object) []);
    app()->instance('current_company', $company);

    return $captured;
}

it('prints the chosen columns and range on the attached PDF', function () {
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $invoice = Invoice::create([
        'contact_id' => $this->customer->id,
        'invoice_no' => 'INV-MAIL-PO',
        'invoice_date' => CarbonImmutable::create(2026, 5, 1),
        'due_date' => CarbonImmutable::create(2026, 5, 31),
        'customer_po' => 'PO-MAIL-7',
    ]);
    $invoice->lines()->create(['account_id' => $income->id, 'description' => 'Service', 'quantity' => '1', 'unit_price_cents' => 20000, 'line_subtotal_cents' => 20000, 'line_tax_cents' => 0, 'line_total_cents' => 20000, 'line_order' => 0]);
    app(InvoicePoster::class)->post($invoice);

    $data = captureStatementAttachment(new CustomerStatementNotification(
        contact: $this->customer,
        company: $this->company,
        type: CustomerStatementType::OpenInvoices,
        start: '2026-04-01',
        end: '2026-06-01',
        statementUrl: 'https://example.com/statement-link',
        message: '',
        columns: ['po'],
    ), $this->company);

    expect($data['columns'])->toBe(['invoice_date', 'invoice_no', 'po', 'balance'])
        ->and($data['data']['start'])->toBe('2026-04-01');

    expect(view('pdf.statements.customer-statement', $data)->render())
        ->toContain('P.O. #')
        ->toContain('PO-MAIL-7')
        ->toContain('4/1/2026 – 6/1/2026');
});

it('still delivers a statement queued before columns existed, with the saved default', function () {
    InvoiceSetting::updateOrCreate(['company_id' => $this->company->id], [
        ...InvoiceSetting::defaults(),
        'statement_columns' => ['open-invoices' => ['terms']],
    ]);

    $queued = new CustomerStatementNotification(
        contact: $this->customer,
        company: $this->company,
        type: CustomerStatementType::OpenInvoices,
        start: null,
        end: '2026-06-01',
        statementUrl: 'https://example.com/statement-link',
        message: '',
    );

    // A pre-upgrade payload carries no `columns` key; the defaulted property
    // must survive the round trip as null rather than fail to unserialize.
    $payload = serialize($queued);
    expect($payload)->not->toContain('columns');

    $restored = unserialize($payload);

    expect($restored->columns)->toBeNull();

    $data = captureStatementAttachment($restored, $this->company);

    expect($data['columns'])->toBe(['invoice_date', 'invoice_no', 'terms', 'balance'])
        ->and($data['data']['start'])->toBeNull();
});
