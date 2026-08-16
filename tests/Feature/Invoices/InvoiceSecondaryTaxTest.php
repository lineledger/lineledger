<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\Country;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceSetting;
use App\Models\TaxCode;
use App\Models\User;
use App\Support\Tax\LineTaxBreakdown;
use Livewire\Livewire;

function showTaxColumn(Company $company): void
{
    InvoiceSetting::updateOrCreate(['company_id' => $company->id], ['show_tax_column' => true]);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->forCountry(Country::Canada, 'BC')->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->customer = Contact::factory()->create(['is_customer' => true]);
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $this->gst = TaxCode::where('code', 'GST')->firstOrFail();
    $this->pst = TaxCode::where('code', 'PST-BC')->firstOrFail();
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('renders one multi-select tax picker listing every tax code', function () {
    showTaxColumn($this->company);

    // A single picker (not two stacked dropdowns) whose menu lists each code.
    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->assertSeeHtml('line-tax')
        ->assertSee($this->gst->code)
        ->assertSee($this->pst->code);
});

it('derives both tax columns from the multi-select selection', function () {
    showTaxColumn($this->company);

    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('lines.0.tax_code_ids', [$this->gst->id, $this->pst->id])
        ->assertSet('lines.0.tax_code_id', $this->gst->id)
        ->assertSet('lines.0.secondary_tax_code_id', $this->pst->id)
        // A third selection is capped — only two taxes survive.
        ->set('lines.0.tax_code_ids', [$this->gst->id, $this->pst->id, $this->gst->id])
        ->assertCount('lines.0.tax_code_ids', 2);
});

it('saves both GST and PST on one invoice line, tracked separately', function () {
    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('contact_id', $this->customer->id)
        ->set('lines', [[
            'item_id' => null,
            'account_id' => $this->income->id,
            'description' => 'Service',
            'service_date' => '',
            'quantity' => '1',
            'unit_price' => '100.00',
            'discount_pct' => '',
            'markup_pct' => '',
            'tax_code_id' => $this->gst->id,
            'secondary_tax_code_id' => $this->pst->id,
            'class_id' => null,
            'location_id' => null,
            'subtotal' => 0,
            'tax' => 0,
            'secondary_tax' => 0,
            'total' => 0,
        ]])
        ->call('saveDraft')
        ->assertHasNoErrors();

    $line = Invoice::firstOrFail()->lines()->firstOrFail();

    expect($line->tax_code_id)->toBe($this->gst->id);
    expect($line->secondary_tax_code_id)->toBe($this->pst->id);
    expect($line->line_subtotal_cents)->toBe(10000);
    expect($line->line_tax_cents)->toBe(500);       // GST 5%
    expect($line->secondary_tax_cents)->toBe(700);  // PST 7%
    expect($line->line_total_cents)->toBe(11200);   // 10000 + 500 + 700
});

it('shows both tax codes and amounts on the read-only invoice page', function () {
    showTaxColumn($this->company);

    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('contact_id', $this->customer->id)
        ->set('lines', [[
            'item_id' => null,
            'account_id' => $this->income->id,
            'description' => 'Service',
            'service_date' => '',
            'quantity' => '1',
            'unit_price' => '100.00',
            'discount_pct' => '',
            'markup_pct' => '',
            'tax_code_id' => $this->gst->id,
            'secondary_tax_code_id' => $this->pst->id,
            'class_id' => null,
            'location_id' => null,
            'subtotal' => 0,
            'tax' => 0,
            'secondary_tax' => 0,
            'total' => 0,
        ]])
        ->call('saveDraft')
        ->assertHasNoErrors();

    $invoice = Invoice::firstOrFail();

    // The read-only page itemises the two taxes on the line: GST $5.00 and PST
    // $7.00 are shown as separate amounts rather than a single merged figure.
    Livewire::test('pages::invoices.show', ['invoice' => $invoice])
        ->assertSee('5.00')   // GST amount
        ->assertSee('7.00');  // PST amount, shown separately
});

it('exposes the multi-select tax picker for every company, regardless of province', function () {
    // The second tax slot is no longer gated to PST provinces — an Ontario (HST)
    // company can still apply two taxes to a line through the same picker.
    $on = Company::factory()->forCountry(Country::Canada, 'ON')->create();
    $on->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $on);

    expect($on->usesSecondaryTax())->toBeTrue();
    showTaxColumn($on);

    Livewire::test('pages::invoices.form', ['company' => $on])
        ->assertSeeHtml('line-tax');
});

it('breaks the line tax into a separate totals row per tax code', function () {
    showTaxColumn($this->company);

    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('contact_id', $this->customer->id)
        ->set('lines', [[
            'item_id' => null,
            'account_id' => $this->income->id,
            'description' => 'Service',
            'service_date' => '',
            'quantity' => '1',
            'unit_price' => '100.00',
            'discount_pct' => '',
            'markup_pct' => '',
            'tax_code_id' => $this->gst->id,
            'secondary_tax_code_id' => $this->pst->id,
            'class_id' => null,
            'location_id' => null,
            'subtotal' => 0,
            'tax' => 0,
            'secondary_tax' => 0,
            'total' => 0,
        ]])
        ->call('saveDraft')
        ->assertHasNoErrors();

    $invoice = Invoice::firstOrFail()->load('lines.taxCode', 'lines.secondaryTaxCode');

    // GST and PST each become their own breakdown row — never one merged "Tax".
    $rows = LineTaxBreakdown::forLines($invoice->lines);
    expect($rows)->toHaveCount(2);
    expect(collect($rows)->firstWhere('tax_cents', 500))->not->toBeNull(); // GST 5%
    expect(collect($rows)->firstWhere('tax_cents', 700))->not->toBeNull(); // PST 7%

    Livewire::test('pages::invoices.show', ['invoice' => $invoice])
        ->assertSeeHtml('invoice-tax-row');
});
