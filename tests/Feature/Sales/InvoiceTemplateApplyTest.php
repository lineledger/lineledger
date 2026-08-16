<?php

use App\Actions\Sales\SaveInvoice;
use App\Actions\Sales\SaveInvoiceTemplate;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceSetting;
use App\Models\InvoiceTemplate;
use App\Models\TaxCode;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $this->gst = TaxCode::where('code', 'GST')->firstOrFail();
    $this->customer = Contact::create(['display_name' => 'Acme Corp', 'is_customer' => true]);

    $this->template = app(SaveInvoiceTemplate::class)->handle([
        'name' => 'Standard package',
        'is_active' => true,
        'lines' => [
            [
                'item_id' => null,
                'account_id' => $this->income->id,
                'description' => 'Consulting',
                'quantity' => '10',
                'unit_price_cents' => 10000,
                'tax_code_id' => $this->gst->id,
            ],
            [
                'item_id' => null,
                'account_id' => $this->income->id,
                'description' => 'Setup fee',
                'quantity' => '1',
                'unit_price_cents' => 2500,
                'tax_code_id' => null,
            ],
        ],
    ]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('replaces the empty line and populates lines when a template is selected on create', function () {
    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->assertSeeHtml('data-test="invoice-template-picker"')
        ->set('template_id', $this->template->id)
        ->assertCount('lines', 2)
        ->assertSet('lines.0.description', 'Consulting')
        ->assertSet('lines.0.unit_price', '100.00')
        ->assertSet('lines.0.tax_code_id', $this->gst->id)
        ->assertSet('lines.1.unit_price', '25.00')
        // Totals recompute through recalcLine(): line 0 is 1000.00 + 5% GST = 1050.00.
        ->assertSet('lines.0.total', 105000)
        // Subtotal (pre-tax) across both lines: 1000.00 + 25.00 = 1025.00.
        ->tap(fn ($c) => expect($c->instance()->totals['subtotal'])->toBe(102500));
});

it('hides the template picker when the Template field is toggled off', function () {
    InvoiceSetting::updateOrCreate(
        ['company_id' => $this->company->id],
        ['show_template' => false],
    );

    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->assertSet('fieldVisibility.template', false)
        ->assertDontSeeHtml('data-test="invoice-template-picker"');
});

it('appends template lines when an existing line already has content', function () {
    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('lines.0.description', 'Manual line')
        ->set('lines.0.unit_price', '50.00')
        ->set('template_id', $this->template->id)
        ->assertCount('lines', 3)
        ->assertSet('lines.0.description', 'Manual line')
        ->assertSet('lines.1.description', 'Consulting');
});

it('hides the picker and ignores template selection when editing an invoice', function () {
    $invoice = app(SaveInvoice::class)->handle([
        'contact_id' => $this->customer->id,
        'invoice_no' => 'INV-100',
        'invoice_date' => $this->company->currentDateTime()->toDateString(),
        'due_date' => $this->company->currentDateTime()->toDateString(),
        'lines' => [[
            'item_id' => null,
            'account_id' => $this->income->id,
            'description' => 'Original line',
            'quantity' => '1',
            'unit_price_cents' => 9900,
            'tax_code_id' => null,
        ]],
    ]);

    Livewire::test('pages::invoices.form', ['company' => $this->company, 'invoice' => $invoice])
        ->assertDontSeeHtml('data-test="invoice-template-picker"')
        ->set('template_id', $this->template->id)
        // Unchanged: still the single original line.
        ->assertCount('lines', 1)
        ->assertSet('lines.0.description', 'Original line');
});

it('posts an invoice built from a template', function () {
    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('contact_id', $this->customer->id)
        ->set('template_id', $this->template->id)
        ->set('invoice_no', 'INV-TPL-1')
        ->call('postInvoice')
        ->assertHasNoErrors();

    $invoice = Invoice::query()->where('invoice_no', 'INV-TPL-1')->with('lines')->firstOrFail();

    expect($invoice->lines)->toHaveCount(2)
        ->and($invoice->journal_entry_id)->not->toBeNull();

    $first = $invoice->lines->firstWhere('description', 'Consulting');
    expect($first->account_id)->toBe($this->income->id)
        ->and($first->unit_price_cents)->toBe(10000)
        ->and((float) $first->quantity)->toBe(10.0)
        ->and($first->tax_code_id)->toBe($this->gst->id);
});

it('saves the current invoice lines as a template', function () {
    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('lines.0.description', 'Retainer')
        ->set('lines.0.account_id', $this->income->id)
        ->set('lines.0.quantity', '1')
        ->set('lines.0.unit_price', '500.00')
        ->set('lines.0.tax_code_id', $this->gst->id)
        ->set('template_name', 'Retainer template')
        ->call('saveAsTemplate')
        ->assertHasNoErrors();

    $template = InvoiceTemplate::query()->where('name', 'Retainer template')->with('lines')->firstOrFail();

    expect($template->lines)->toHaveCount(1)
        ->and($template->lines->first()->description)->toBe('Retainer')
        ->and($template->lines->first()->unit_price_cents)->toBe(50000)
        ->and($template->lines->first()->tax_code_id)->toBe($this->gst->id);
});
