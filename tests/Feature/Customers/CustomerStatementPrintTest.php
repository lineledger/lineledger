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
use App\Services\Posting\InvoicePoster;
use App\Services\Reporting\CustomerStatementBuilder;
use App\Services\Reporting\CustomerStatementPdfRenderer;
use App\Services\Reporting\PdfExporter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->customer = Contact::factory()->customer()->create([
        'display_name' => 'Rainbow Memorials',
        'billing_line1' => '456 Harbour Rd',
        'billing_city' => 'Victoria',
        'billing_region' => 'BC',
        'billing_postal_code' => 'V9A 3S1',
    ]);

    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $invoice = Invoice::create([
        'contact_id' => $this->customer->id,
        'invoice_no' => 'INV-STMT-1',
        'invoice_date' => CarbonImmutable::create(2026, 5, 1),
        'due_date' => CarbonImmutable::create(2026, 5, 31),
        'memo' => 'Pre-need arrangement for the Harbour Rd family plot, balance due on completion',
        'customer_po' => 'PO-HARBOUR-42',
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
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('returns the statement as an inline PDF for both types', function (string $type) {
    $response = $this->get(route('customers.statement.print', [
        'company' => $this->company->slug,
        'contact' => $this->customer->id,
        'type' => $type,
        'as_of' => '2026-06-01',
        'start' => '2026-01-01',
        'end' => '2026-06-01',
    ]));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toContain('inline');
})->with(['open-invoices', 'activity']);

it('returns the statement as a download', function () {
    $response = $this->get(route('customers.statement.download', [
        'company' => $this->company->slug,
        'contact' => $this->customer->id,
        'type' => 'open-invoices',
        'as_of' => '2026-06-01',
    ]));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment')
        ->and($response->headers->get('Content-Disposition'))->toContain('statement-');
});

it('404s for another company\'s contact', function () {
    $otherCompany = Company::factory()->create();
    app()->instance('current_company', $otherCompany);
    $foreign = Contact::factory()->customer()->create();
    app()->instance('current_company', $this->company);

    $this->get(route('customers.statement.print', [
        'company' => $this->company->slug,
        'contact' => $foreign->id,
    ]))->assertNotFound();
});

it('404s for a vendor-only contact', function () {
    $vendor = Contact::factory()->vendor()->create();

    $this->get(route('customers.statement.print', [
        'company' => $this->company->slug,
        'contact' => $vendor->id,
    ]))->assertNotFound();
});

it('renders the open-invoices statement with invoice rows, aging strip and total due', function () {
    $data = app(CustomerStatementBuilder::class)
        ->openInvoices($this->company, $this->customer, CarbonImmutable::create(2026, 6, 1));

    $html = view('pdf.statements.customer-statement', [
        'company' => $this->company,
        'contact' => $this->customer,
        'type' => CustomerStatementType::OpenInvoices,
        'settings' => new InvoiceSetting([...InvoiceSetting::defaults(), 'company_id' => $this->company->id]),
        'data' => $data,
    ])->render();

    expect($html)
        ->toContain('STATEMENT')
        ->toContain('Rainbow Memorials')
        ->toContain('456 Harbour Rd')
        ->toContain('INV-STMT-1')
        ->toContain('<th>Memo</th>')
        ->toContain('<td class="memo">Pre-need arrangement for the Harbour Rd family plot, balance due on completion</td>')
        ->toContain('200.00')
        ->toContain('Total Due')
        ->toContain('Current')
        ->toContain('1–30 Days')
        // The name is HTML-escaped in the template (Faker can generate an apostrophe).
        ->toContain(e($this->company->name));
});

it('renders the activity statement with opening balance, running lines and closing balance', function () {
    $data = app(CustomerStatementBuilder::class)->activity(
        $this->company,
        $this->customer,
        CarbonImmutable::create(2026, 1, 1),
        CarbonImmutable::create(2026, 6, 1),
    );

    $html = view('pdf.statements.customer-statement', [
        'company' => $this->company,
        'contact' => $this->customer,
        'type' => CustomerStatementType::Activity,
        'settings' => new InvoiceSetting([...InvoiceSetting::defaults(), 'company_id' => $this->company->id]),
        'data' => $data,
    ])->render();

    expect($html)
        ->toContain('Opening balance')
        ->toContain('INV-STMT-1')
        ->toContain('Charges')
        ->toContain('Payments')
        ->toContain('Balance Due')
        ->toContain('200.00');
});

it('honors the document header toggles', function () {
    $data = app(CustomerStatementBuilder::class)
        ->openInvoices($this->company, $this->customer, CarbonImmutable::create(2026, 6, 1));

    $render = fn (bool $show) => view('pdf.statements.customer-statement', [
        'company' => $this->company,
        'contact' => $this->customer,
        'type' => CustomerStatementType::OpenInvoices,
        'settings' => new InvoiceSetting([...InvoiceSetting::defaults(), 'company_id' => $this->company->id, 'show_company_name' => $show]),
        'data' => $data,
    ])->render();

    expect($render(true))->toContain('<div class="company-name">');
    expect($render(false))->not->toContain('<div class="company-name">');
});

it('shows a friendly empty state when the customer has no open invoices', function () {
    $settled = Contact::factory()->customer()->create();

    $data = app(CustomerStatementBuilder::class)
        ->openInvoices($this->company, $settled, CarbonImmutable::create(2026, 6, 1));

    $html = view('pdf.statements.customer-statement', [
        'company' => $this->company,
        'contact' => $settled,
        'type' => CustomerStatementType::OpenInvoices,
        'settings' => new InvoiceSetting([...InvoiceSetting::defaults(), 'company_id' => $this->company->id]),
        'data' => $data,
    ])->render();

    expect($html)->toContain('No open invoices');
});

/**
 * Hit the print route with $query, capturing the view data the controller
 * hands the PDF engine instead of rendering it.
 *
 * @param  array<string, string>  $query
 * @return array<string, mixed>
 */
function capturePrintedStatement(object $test, array $query): array
{
    $captured = [];

    $pdf = Mockery::mock(PdfExporter::class);
    $pdf->shouldReceive('inline')->once()->andReturnUsing(function (string $view, array $data) use (&$captured) {
        $captured = $data;

        return new Response('%PDF-fake');
    });
    app()->instance(PdfExporter::class, $pdf);

    $test->get(route('customers.statement.print', [
        'company' => $test->company->slug,
        'contact' => $test->customer->id,
        ...$query,
    ]))->assertOk();

    return $captured;
}

it('prints today\'s open invoices with the original columns when given no parameters', function () {
    $data = capturePrintedStatement($this, []);

    expect($data['type'])->toBe(CustomerStatementType::OpenInvoices)
        ->and($data['columns'])->toBe(['invoice_date', 'invoice_no', 'memo', 'due_date', 'original', 'balance'])
        ->and($data['data']['start'])->toBeNull()
        ->and($data['data']['as_of'])->toBe($this->company->currentDateTime()->toDateString());

    $html = view('pdf.statements.customer-statement', $data)->render();

    expect($html)
        ->toContain('<th style="width: 11%;">Date</th>')
        ->toContain('<th style="width: 15%;">Invoice #</th>')
        ->toContain('<th>Memo</th>')
        ->toContain('<th class="num" style="width: 17%;">Original Amount</th>')
        ->toContain('As Of')
        ->not->toContain('P.O. #')
        ->not->toContain('PO-HARBOUR-42');
});

it('prints the P.O. column when it is selected', function () {
    $data = capturePrintedStatement($this, ['type' => 'open-invoices', 'as_of' => '2026-06-01', 'cols' => 'po,paid']);

    expect($data['columns'])->toBe(['invoice_date', 'invoice_no', 'po', 'paid', 'balance']);

    $html = view('pdf.statements.customer-statement', $data)->render();

    expect($html)
        ->toContain('P.O. #')
        ->toContain('PO-HARBOUR-42')
        ->toContain('Amount Paid')
        ->not->toContain('<th>Memo</th>')
        ->not->toContain('Pre-need arrangement');
});

it('falls back to the company\'s saved columns when none are requested', function () {
    InvoiceSetting::updateOrCreate(['company_id' => $this->company->id], [
        ...InvoiceSetting::defaults(),
        'statement_columns' => ['open-invoices' => ['po']],
    ]);

    expect(capturePrintedStatement($this, ['as_of' => '2026-06-01'])['columns'])
        ->toBe(['invoice_date', 'invoice_no', 'po', 'balance']);
});

it('prints a period with a balance forward row when an open-invoices start is given', function () {
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $later = Invoice::create([
        'contact_id' => $this->customer->id,
        'invoice_no' => 'INV-STMT-2',
        'invoice_date' => CarbonImmutable::create(2026, 5, 20),
        'due_date' => CarbonImmutable::create(2026, 6, 19),
    ]);
    $later->lines()->create(['account_id' => $income->id, 'description' => 'Service', 'quantity' => '1', 'unit_price_cents' => 5000, 'line_subtotal_cents' => 5000, 'line_tax_cents' => 0, 'line_total_cents' => 5000, 'line_order' => 0]);
    app(InvoicePoster::class)->post($later);

    $data = capturePrintedStatement($this, ['type' => 'open-invoices', 'start' => '2026-05-15', 'end' => '2026-06-01', 'as_of' => '2026-06-01']);

    expect($data['data']['start'])->toBe('2026-05-15')
        ->and(collect($data['data']['rows'])->pluck('kind')->all())->toBe(['forward', 'invoice']);

    $html = view('pdf.statements.customer-statement', $data)->render();

    expect($html)
        ->toContain('Period')
        ->toContain('5/15/2026 – 6/1/2026')
        ->toContain('Balance forward')
        ->toContain('<td colspan="5">Balance forward</td>')
        ->toContain('INV-STMT-2')
        ->not->toContain('INV-STMT-1')
        ->toContain('$250.00');
});

it('drops the open-invoices start under range=all', function () {
    $data = capturePrintedStatement($this, ['type' => 'open-invoices', 'start' => '2026-05-15', 'as_of' => '2026-06-01', 'range' => 'all']);

    expect($data['data']['start'])->toBeNull();
});

it('prints the activity statement with its chosen columns', function () {
    $data = capturePrintedStatement($this, ['type' => 'activity', 'start' => '2026-01-01', 'end' => '2026-06-01', 'cols' => 'po']);

    expect($data['columns'])->toBe(['date', 'doc_no', 'po', 'charges', 'payments', 'running']);

    $html = view('pdf.statements.customer-statement', $data)->render();

    expect($html)
        ->toContain('1/1/2026 – 6/1/2026')
        ->toContain('<td colspan="5">Opening balance</td>')
        ->toContain('PO-HARBOUR-42')
        ->not->toContain('<th style="width: 13%;">Type</th>');
});

it('builds the same view data through the renderer the email uses', function () {
    $data = app(CustomerStatementPdfRenderer::class)->data(
        $this->company,
        $this->customer,
        CustomerStatementType::OpenInvoices,
        null,
        CarbonImmutable::create(2026, 6, 1),
        [],
    );

    expect($data['columns'])->toBe(['invoice_date', 'invoice_no', 'balance']);

    $html = view('pdf.statements.customer-statement', $data)->render();

    expect($html)->toContain('<td colspan="2">Total Due</td>');
});
