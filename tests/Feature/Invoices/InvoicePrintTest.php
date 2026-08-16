<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceSetting;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\Posting\InvoicePoster;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();
    $this->gst = TaxCode::query()->where('code', 'GST')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function makePostedInvoice(object $test): Invoice
{
    $customer = Contact::create([
        'display_name' => 'Chavez, Isaac Jr.',
        'is_customer' => true,
        'billing_line1' => '123 Main St',
        'billing_city' => 'Victoria',
        'billing_region' => 'BC',
        'billing_postal_code' => 'V8V 1A1',
    ]);

    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-PRINT-1',
        'invoice_date' => CarbonImmutable::create(2026, 5, 24),
        'due_date' => CarbonImmutable::create(2026, 6, 23),
    ]);

    $invoice->lines()->create([
        'account_id' => $test->income->id,
        'tax_code_id' => $test->gst->id,
        'description' => 'Embalming services',
        'quantity' => '1',
        'unit_price_cents' => 5000,
        'line_subtotal_cents' => 5000,
        'line_tax_cents' => 250,
        'line_total_cents' => 5250,
        'line_order' => 0,
    ]);

    app(InvoicePoster::class)->post($invoice);

    return $invoice->fresh();
}

it('returns the invoice as an inline PDF', function () {
    $invoice = makePostedInvoice($this);

    $response = $this->get(route('invoices.print', [
        'company' => $this->company->slug,
        'invoice' => $invoice->id,
    ]));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toContain('inline');
});

it('404s when the invoice belongs to another company', function () {
    $otherCompany = Company::factory()->create();
    app()->instance('current_company', $otherCompany);
    $foreignInvoice = makePostedInvoice((object) ['income' => Account::query()->where('company_id', $otherCompany->id)->where('subtype', AccountSubtype::Income->value)->first(), 'gst' => TaxCode::query()->where('company_id', $otherCompany->id)->where('code', 'GST')->firstOrFail()]);
    app()->instance('current_company', $this->company);

    $this->get(route('invoices.print', [
        'company' => $this->company->slug,
        'invoice' => $foreignInvoice->id,
    ]))->assertNotFound();
});

it('renders customer-facing content without GL account codes', function () {
    $invoice = makePostedInvoice($this)->load('lines.taxCode', 'lines.item', 'contact', 'terms');

    $settings = new InvoiceSetting([...InvoiceSetting::defaults(), 'company_id' => $this->company->id]);
    $settings->footer_message = 'Thank you for your business';
    $this->company->tax_number = '123456789 RT0001';

    $taxSummary = [['label' => 'GST (5%)', 'rate' => 5.0, 'tax_cents' => 250]];

    $html = view('pdf.invoices.invoice', [
        'company' => $this->company,
        'invoice' => $invoice,
        'settings' => $settings,
        'taxSummary' => $taxSummary,
        'logoData' => null,
    ])->render();

    expect($html)
        ->toContain('INV-PRINT-1')
        ->toContain('Chavez, Isaac Jr.')
        ->toContain('123 Main St')
        ->toContain('GST (5%)')
        ->toContain('5.00%')
        ->toContain('GST/HST No.')
        ->toContain('123456789 RT0001')
        ->toContain('Thank you for your business')
        // Internal GL account code/name must not leak onto the customer invoice.
        ->not->toContain($this->income->code)
        ->not->toContain($this->income->name);
});

it('hides the service date line when the service date column is toggled off', function () {
    $invoice = makePostedInvoice($this);
    $invoice->lines()->update(['service_date' => CarbonImmutable::create(2026, 5, 20)]);
    $invoice = $invoice->fresh()->load('lines.taxCode', 'lines.item', 'contact', 'terms');

    $render = fn (bool $show) => view('pdf.invoices.invoice', [
        'company' => $this->company,
        'invoice' => $invoice,
        'settings' => new InvoiceSetting([...InvoiceSetting::defaults(), 'company_id' => $this->company->id, 'show_service_date_column' => $show]),
        'taxSummary' => [['label' => 'GST (5%)', 'rate' => 5.0, 'tax_cents' => 250]],
        'logoData' => null,
    ])->render();

    expect($render(true))->toContain('Service date');
    expect($render(false))->not->toContain('Service date');
});

it('shows the document logo at the configured height in place of the company name', function () {
    Storage::fake('public');

    $invoice = makePostedInvoice($this)->load('lines.taxCode', 'lines.item', 'contact', 'terms');

    $this->company->name = 'Pacific Crematorium Limited';
    $this->company->document_logo_path = UploadedFile::fake()->image('doc-logo.png')->store('company-logos', 'public');
    $this->company->document_logo_max_height = 96;

    $settings = new InvoiceSetting([
        ...InvoiceSetting::defaults(),
        'company_id' => $this->company->id,
        'show_company_name' => false,
    ]);

    $html = view('pdf.invoices.invoice', [
        'company' => $this->company,
        'invoice' => $invoice,
        'settings' => $settings,
        'taxSummary' => [],
    ])->render();

    expect($html)
        ->toContain('<img')
        ->toContain('max-height: 96px')
        // The name is hidden from the header (it still appears in the <title>).
        ->not->toContain('<div class="company-name">');
});

it('shows email and website only when those header fields are enabled', function () {
    $invoice = makePostedInvoice($this)->load('lines.taxCode', 'lines.item', 'contact', 'terms');

    $this->company->email = 'office@crematorium.test';
    $this->company->website = 'https://crematorium.test';

    $render = fn (bool $show) => view('pdf.invoices.invoice', [
        'company' => $this->company,
        'invoice' => $invoice,
        'settings' => new InvoiceSetting([
            ...InvoiceSetting::defaults(),
            'company_id' => $this->company->id,
            'show_company_email' => $show,
            'show_company_website' => $show,
        ]),
        'taxSummary' => [],
    ])->render();

    expect($render(true))
        ->toContain('office@crematorium.test')
        ->toContain('https://crematorium.test');
    expect($render(false))
        ->not->toContain('office@crematorium.test')
        ->not->toContain('https://crematorium.test');
});

it('hides the GST number and a toggled-off column', function () {
    $invoice = makePostedInvoice($this)->load('lines.taxCode', 'lines.item', 'contact', 'terms');

    $settings = new InvoiceSetting([...InvoiceSetting::defaults(), 'company_id' => $this->company->id]);
    $settings->show_tax_number = false;
    $settings->show_qty_column = false;
    $this->company->tax_number = '123456789 RT0001';

    $html = view('pdf.invoices.invoice', [
        'company' => $this->company,
        'invoice' => $invoice,
        'settings' => $settings,
        'taxSummary' => [['label' => 'GST (5%)', 'rate' => 5.0, 'tax_cents' => 250]],
        'logoData' => null,
    ])->render();

    expect($html)
        ->not->toContain('GST/HST No.')
        ->not->toContain('123456789 RT0001')
        ->not->toContain('>Qty<');
});

it('prints the Qty column after the Description column', function () {
    $invoice = makePostedInvoice($this)->load('lines.taxCode', 'lines.item', 'contact', 'terms');

    $html = view('pdf.invoices.invoice', [
        'company' => $this->company,
        'invoice' => $invoice,
        // Qty defaults on.
        'settings' => new InvoiceSetting([...InvoiceSetting::defaults(), 'company_id' => $this->company->id]),
        'taxSummary' => [['label' => 'GST (5%)', 'rate' => 5.0, 'tax_cents' => 250]],
        'logoData' => null,
    ])->render();

    expect(strpos($html, '>Description<'))->toBeLessThan(strpos($html, '>Qty<'));
});

it('hides the unit price column when the Unit column is toggled off', function () {
    $invoice = makePostedInvoice($this)->load('lines.taxCode', 'lines.item', 'contact', 'terms');

    $render = fn (bool $show) => view('pdf.invoices.invoice', [
        'company' => $this->company,
        'invoice' => $invoice,
        'settings' => new InvoiceSetting([...InvoiceSetting::defaults(), 'company_id' => $this->company->id, 'show_unit_column' => $show]),
        'taxSummary' => [['label' => 'GST (5%)', 'rate' => 5.0, 'tax_cents' => 250]],
        'logoData' => null,
    ])->render();

    expect($render(true))->toContain('Price Each');
    expect($render(false))->not->toContain('Price Each');
});
