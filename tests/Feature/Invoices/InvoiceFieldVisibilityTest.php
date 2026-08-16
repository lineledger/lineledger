<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceSetting;
use App\Models\Item;
use App\Models\TaxCode;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('hides the shipping/PO/tax/service-date fields but keeps the essentials by default', function () {
    Livewire::test('pages::invoices.form', ['company' => $this->company])
        // Lean defaults — these start hidden on a new company.
        ->assertSet('fieldVisibility.customer_po', false)
        ->assertSet('fieldVisibility.tracking_no', false)
        ->assertSet('fieldVisibility.tax_column', false)
        ->assertSet('fieldVisibility.service_date_column', false)
        ->assertSet('fieldVisibility.discount_column', false)
        ->assertSet('fieldVisibility.markup_column', false)
        ->assertSet('fieldVisibility.document_discount', false)
        ->assertDontSeeHtml('data-test="invoice-customer-po"')
        ->assertDontSeeHtml('data-test="invoice-tracking-no"')
        ->assertDontSeeHtml('data-test="line-tax"')
        ->assertDontSeeHtml('data-test="line-service-date"')
        ->assertDontSeeHtml('data-test="line-discount-pct"')
        ->assertDontSeeHtml('data-test="line-markup-pct"')
        ->assertDontSeeHtml('data-test="invoice-document-discount-pct"')
        // Essentials stay on.
        ->assertSeeHtml('data-test="invoice-customer-message"')
        ->assertSeeHtml('data-test="line-item"')
        ->assertSeeHtml('data-test="line-qty"')
        ->assertSeeHtml('data-test="line-account"')
        ->assertSeeHtml('data-test="line-unit-price"');
});

it('re-shows the optional line columns when toggled on', function () {
    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('fieldVisibility.discount_column', true)
        ->set('fieldVisibility.markup_column', true)
        ->set('fieldVisibility.tax_column', true)
        ->set('fieldVisibility.service_date_column', true)
        ->set('fieldVisibility.document_discount', true)
        ->assertSeeHtml('data-test="line-discount-pct"')
        ->assertSeeHtml('data-test="line-markup-pct"')
        ->assertSeeHtml('data-test="line-tax"')
        ->assertSeeHtml('data-test="line-service-date"')
        ->assertSeeHtml('data-test="invoice-document-discount-pct"');
});

it('hides the item, qty and tax columns when toggled off', function () {
    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('fieldVisibility.item_column', false)
        ->set('fieldVisibility.qty_column', false)
        ->set('fieldVisibility.tax_column', false)
        ->assertDontSeeHtml('data-test="line-item"')
        ->assertDontSeeHtml('data-test="line-qty"')
        ->assertDontSeeHtml('data-test="line-tax"')
        // Untoggled columns remain.
        ->assertSeeHtml('data-test="line-unit-price"');
});

it('still posts a draft with the item, qty and tax columns hidden', function () {
    $customer = Contact::create(['display_name' => 'Hidden Cols Co', 'is_customer' => true]);

    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('fieldVisibility.item_column', false)
        ->set('fieldVisibility.qty_column', false)
        ->set('fieldVisibility.tax_column', false)
        ->call('selectContact', $customer->id)
        ->set('lines.0.account_id', $this->income->id)
        ->set('lines.0.description', 'Consulting')
        ->set('lines.0.unit_price', '100.00')
        ->call('saveDraft')
        ->assertHasNoErrors();

    $invoice = Invoice::query()->where('contact_id', $customer->id)->firstOrFail();

    // Quantity falls back to its default when the column is hidden.
    expect((float) $invoice->lines->first()->quantity)->toBe(1.0);
});

it('hides a header field when its toggle is turned off', function () {
    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('fieldVisibility.customer_po', false)
        ->set('fieldVisibility.tracking_no', false)
        ->assertDontSeeHtml('data-test="invoice-customer-po"')
        ->assertDontSeeHtml('data-test="invoice-tracking-no"')
        // Untoggled fields remain.
        ->assertSeeHtml('data-test="invoice-customer-message"');
});

it('hides the service date and account columns when toggled off', function () {
    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('fieldVisibility.service_date_column', false)
        ->set('fieldVisibility.account_column', false)
        ->assertDontSeeHtml('data-test="line-service-date"')
        ->assertDontSeeHtml('data-test="line-account"');
});

it('persists visibility choices per company on InvoiceSetting', function () {
    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('fieldVisibility.memo', false)
        ->set('fieldVisibility.service_date_column', false);

    $this->assertDatabaseHas('invoice_settings', [
        'company_id' => $this->company->id,
        'show_memo' => false,
        'show_service_date_column' => false,
        // An untoggled essential keeps its default-on value.
        'show_item_column' => true,
    ]);
});

it('reloads the saved layout on a fresh form', function () {
    InvoiceSetting::updateOrCreate(
        ['company_id' => $this->company->id],
        [...InvoiceSetting::defaults(), 'show_fob' => false],
    );

    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->assertSet('fieldVisibility.fob', false)
        ->assertDontSeeHtml('data-test="invoice-fob"');
});

it('flags when a new invoice would exceed the customer credit limit', function () {
    $customer = Contact::create([
        'display_name' => 'Limited Co',
        'is_customer' => true,
        'credit_limit_cents' => 10000, // $100 limit
    ]);

    $status = Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->call('selectContact', $customer->id)
        ->set('lines.0.account_id', $this->income->id)
        ->set('lines.0.quantity', '1')
        ->set('lines.0.unit_price', '150.00')
        ->assertSeeHtml('data-test="invoice-credit-over"')
        ->get('creditStatus');

    expect($status['over'])->toBeTrue()
        ->and($status['limit'])->toBe(10000)
        ->and($status['projected'])->toBe(15000);
});

it('posts a manual line with the account column hidden by defaulting the income account', function () {
    $customer = Contact::create(['display_name' => 'Acme Co', 'is_customer' => true]);

    $defaultIncome = Account::query()
        ->selectableForItemAccount()
        ->where('type', AccountType::Income->value)
        ->where('is_active', true)
        ->orderBy('code')
        ->value('id');

    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('fieldVisibility.account_column', false)
        ->call('selectContact', $customer->id)
        // No item picked, so no account is prefilled — and the column is hidden.
        ->set('lines.0.description', 'Consulting')
        ->set('lines.0.quantity', '1')
        ->set('lines.0.unit_price', '100.00')
        ->call('saveDraft')
        ->assertHasNoErrors();

    $invoice = Invoice::query()->where('contact_id', $customer->id)->firstOrFail();

    expect($invoice->lines->first()->account_id)->toBe($defaultIncome);
});

it('prefills new lines with the configured default sales account', function () {
    $sales = Account::query()
        ->selectableForItemAccount()
        ->where('type', AccountType::Income->value)
        ->where('is_active', true)
        ->orderBy('code')
        ->value('id');

    InvoiceSetting::updateOrCreate(
        ['company_id' => $this->company->id],
        [...InvoiceSetting::defaults(), 'default_sales_account_id' => $sales],
    );

    $customer = Contact::create(['display_name' => 'Default Acct Co', 'is_customer' => true]);

    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->assertSet('lines.0.account_id', $sales)
        ->call('selectContact', $customer->id)
        ->set('lines.0.quantity', '1')
        ->set('lines.0.unit_price', '100.00')
        ->call('saveDraft')
        ->assertHasNoErrors();

    $invoice = Invoice::query()->where('contact_id', $customer->id)->firstOrFail();

    expect($invoice->lines->first()->account_id)->toBe($sales);
});

it('shows the sales rep field when the Employees feature is enabled', function () {
    $this->company->update(['features_employees' => true]);

    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->assertSeeHtml('data-test="invoice-sales-rep"');
});

it('hides the sales rep field when the Employees feature is off', function () {
    $this->company->update(['features_employees' => false]);

    Livewire::test('pages::invoices.form', ['company' => $this->company])
        // Field is hidden even though the show_sales_rep setting defaults on.
        ->assertSet('fieldVisibility.sales_rep', true)
        ->assertDontSeeHtml('data-test="invoice-sales-rep"');
});

it('shows the class and location columns by default when the features are enabled', function () {
    $this->company->update(['features_classes' => true, 'features_locations' => true]);

    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->assertSet('fieldVisibility.class_column', true)
        ->assertSet('fieldVisibility.location_column', true)
        ->assertSeeHtml('data-test="line-class"')
        ->assertSeeHtml('data-test="line-location"');
});

it('hides the class and location columns when toggled off and persists the choice', function () {
    $this->company->update(['features_classes' => true, 'features_locations' => true]);

    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('fieldVisibility.class_column', false)
        ->set('fieldVisibility.location_column', false)
        ->assertDontSeeHtml('data-test="line-class"')
        ->assertDontSeeHtml('data-test="line-location"');

    $this->assertDatabaseHas('invoice_settings', [
        'company_id' => $this->company->id,
        'show_class_column' => false,
        'show_location_column' => false,
    ]);
});

it('hides the class and location columns when the features are off regardless of the toggle', function () {
    $this->company->update(['features_classes' => false, 'features_locations' => false]);

    Livewire::test('pages::invoices.form', ['company' => $this->company])
        // Hidden even though the settings default on.
        ->assertSet('fieldVisibility.class_column', true)
        ->assertSet('fieldVisibility.location_column', true)
        ->assertDontSeeHtml('data-test="line-class"')
        ->assertDontSeeHtml('data-test="line-location"');
});

function makeInvoiceWithLine(object $test): Invoice
{
    $customer = Contact::create(['display_name' => 'Show Page Co', 'is_customer' => true]);
    $item = Item::create(['company_id' => $test->company->id, 'name' => 'Widget Sentinel']);
    $gst = TaxCode::query()->where('company_id', $test->company->id)->where('code', 'GST')->firstOrFail();

    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-SHOW-1',
        'invoice_date' => CarbonImmutable::create(2026, 5, 20),
        'due_date' => CarbonImmutable::create(2026, 6, 19),
    ]);

    $invoice->lines()->create([
        'item_id' => $item->id,
        'account_id' => $test->income->id,
        'tax_code_id' => $gst->id,
        'description' => 'Consulting',
        'service_date' => CarbonImmutable::create(2026, 5, 20),
        'quantity' => '9',
        'unit_price_cents' => 5000,
        'line_subtotal_cents' => 45000,
        'line_tax_cents' => 2250,
        'line_total_cents' => 47250,
        'line_order' => 0,
    ]);

    return $invoice->fresh();
}

it('shows the essential line columns but hides tax and service date on the detail page by default', function () {
    $invoice = makeInvoiceWithLine($this);

    Livewire::test('pages::invoices.show', ['company' => $this->company, 'invoice' => $invoice])
        ->assertSeeHtml('<th class="px-4 py-2 text-left">Item</th>')
        ->assertSeeHtml('Widget Sentinel')
        ->assertSeeHtml('<th class="px-4 py-2 text-right">Qty</th>')
        // Account and Unit show by default.
        ->assertSeeHtml('<th class="px-4 py-2 text-left">Account</th>')
        ->assertSeeHtml('<th class="px-4 py-2 text-right">Unit</th>')
        // Hidden by default.
        ->assertDontSeeHtml('<th class="px-4 py-2 text-left">Tax</th>')
        ->assertDontSeeHtml('Service date: 2026-05-20');
});

it('hides the Account and Unit columns on the detail page when toggled off', function () {
    InvoiceSetting::updateOrCreate(
        ['company_id' => $this->company->id],
        [...InvoiceSetting::defaults(), 'show_account_column' => false, 'show_unit_column' => false],
    );

    $invoice = makeInvoiceWithLine($this);

    Livewire::test('pages::invoices.show', ['company' => $this->company, 'invoice' => $invoice])
        ->assertDontSeeHtml('<th class="px-4 py-2 text-left">Account</th>')
        ->assertDontSeeHtml('<th class="px-4 py-2 text-right">Unit</th>')
        // Other columns are unaffected.
        ->assertSeeHtml('<th class="px-4 py-2 text-left">Item</th>')
        ->assertSeeHtml('<th class="px-4 py-2 text-right">Qty</th>');
});

it('persists the Account and Unit column toggles from the detail page', function () {
    $invoice = makeInvoiceWithLine($this);

    Livewire::test('pages::invoices.show', ['company' => $this->company, 'invoice' => $invoice])
        ->set('fieldVisibility.account_column', false)
        ->set('fieldVisibility.unit_column', false);

    $this->assertDatabaseHas('invoice_settings', [
        'company_id' => $this->company->id,
        'show_account_column' => false,
        'show_unit_column' => false,
    ]);
});

it('hides line columns on the detail page when toggled off', function () {
    InvoiceSetting::updateOrCreate(
        ['company_id' => $this->company->id],
        [...InvoiceSetting::defaults(), 'show_item_column' => false, 'show_qty_column' => false, 'show_tax_column' => false, 'show_service_date_column' => false],
    );

    $invoice = makeInvoiceWithLine($this);

    Livewire::test('pages::invoices.show', ['company' => $this->company, 'invoice' => $invoice])
        ->assertDontSeeHtml('<th class="px-4 py-2 text-left">Item</th>')
        ->assertDontSeeHtml('Widget Sentinel')
        ->assertDontSeeHtml('<th class="px-4 py-2 text-right">Qty</th>')
        ->assertDontSeeHtml('<th class="px-4 py-2 text-left">Tax</th>')
        ->assertDontSeeHtml('Service date: 2026-05-20');
});

it('persists a column toggle from the detail page to the shared InvoiceSetting', function () {
    $invoice = makeInvoiceWithLine($this);

    Livewire::test('pages::invoices.show', ['company' => $this->company, 'invoice' => $invoice])
        ->set('fieldVisibility.qty_column', false);

    $this->assertDatabaseHas('invoice_settings', [
        'company_id' => $this->company->id,
        'show_qty_column' => false,
        'show_item_column' => true,
    ]);
});

it('does not show a credit panel when the customer has no credit limit', function () {
    $customer = Contact::create(['display_name' => 'No Limit Co', 'is_customer' => true]);

    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->call('selectContact', $customer->id)
        ->set('lines.0.account_id', $this->income->id)
        ->set('lines.0.unit_price', '150.00')
        ->assertDontSeeHtml('data-test="invoice-credit-status"');
});
