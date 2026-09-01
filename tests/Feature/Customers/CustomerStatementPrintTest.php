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
use Carbon\CarbonImmutable;

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
        ->toContain('200.00')
        ->toContain('Total Due')
        ->toContain('Current')
        ->toContain('1–30 Days')
        ->toContain($this->company->name);
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
