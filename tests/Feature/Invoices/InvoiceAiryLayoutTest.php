<?php

use App\Actions\Sales\SaveFormStyle;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\Country;
use App\Enums\InvoiceLayout;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\FormStyle;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\User;
use App\Services\Posting\InvoicePoster;
use App\Services\Reporting\InvoicePdfRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->forCountry(Country::UnitedKingdom)->create([
        'name' => 'Northwind Studio Ltd',
        'address_region' => 'ENG',
        'email' => 'hello@northwind.test',
        'website' => 'https://northwind.test',
    ]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->company->invoiceSettingsOrNew()->forceFill([
        'company_id' => $this->company->id,
        'show_company_email' => true,
        'show_company_website' => true,
        'show_qty_column' => true,
        'show_unit_column' => true,
        'payment_instructions' => "Payments by bank transfer to:\nAccount number: 00000000",
        'footer_message' => 'Invoices not paid by their due date incur a 5% late fee.',
    ])->save();

    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();
    $customer = Contact::create([
        'display_name' => 'Harbour Autocare',
        'company_name' => 'Harbour Autocare',
        'first_name' => 'Sam',
        'last_name' => 'Carter',
        'is_customer' => true,
    ]);
    $support = Item::create(['name' => 'Support', 'type' => 'service', 'income_account_id' => $income->id]);

    $this->invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => '2147',
        'invoice_date' => CarbonImmutable::create(2026, 9, 25),
        'due_date' => CarbonImmutable::create(2026, 10, 10),
        'customer_message' => 'Thank you for your business and have a great day!',
    ]);
    $this->invoice->lines()->create([
        'account_id' => $income->id, 'item_id' => $support->id, 'description' => 'Upgrade MySQL DB 8.0 -> 8.4',
        'quantity' => '7.5', 'unit_price_cents' => 8500, 'line_subtotal_cents' => 63750, 'line_tax_cents' => 0,
        'line_total_cents' => 63750, 'line_order' => 0,
    ]);
    $this->invoice->lines()->create([
        'account_id' => $income->id, 'description' => 'Maintenance', 'quantity' => '1',
        'unit_price_cents' => 12500, 'line_subtotal_cents' => 12500, 'line_tax_cents' => 0,
        'line_total_cents' => 12500, 'line_order' => 1,
    ]);
    app(InvoicePoster::class)->post($this->invoice);
    $this->invoice = $this->invoice->fresh();

    $this->airy = fn () => app(SaveFormStyle::class)->handle(['name' => 'Airy', 'layout' => InvoiceLayout::Airy, 'is_default' => true, 'is_active' => true]);
    $this->html = fn () => app(InvoicePdfRenderer::class)->html($this->company->fresh(), $this->invoice->fresh());
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('prints with the Airy layout when the invoice style uses it', function () {
    ($this->airy)();

    expect(($this->html)())
        ->toContain('Invoice to')
        ->toContain('Sam Carter')
        ->toContain('Harbour Autocare')
        ->toContain('Invoice no.')
        ->toContain('10/10/2026')
        ->toContain('<div class="item">Support</div>')
        ->toContain('Upgrade MySQL DB 8.0 -&gt; 8.4')
        ->toContain('>7.50<')
        ->toContain('>1<')
        ->toContain('85.00')
        ->toContain('Balance Due')
        ->toContain('£762.50')
        ->toContain('Thank you for your business and have a great day!')
        ->toContain('Account number: 00000000')
        ->toContain('5% late fee')
        ->toContain('hello@northwind.test')
        // A services invoice to a customer with no shipping address keeps the
        // open two-column header.
        ->not->toContain('Ship To');

    // A real PDF comes out of it.
    expect(substr(app(InvoicePdfRenderer::class)->raw($this->company->fresh(), $this->invoice), 0, 4))->toBe('%PDF');
});

it('keeps the classic layout when there is no style, or the style is classic', function () {
    expect(($this->html)())->toContain('Ship To')->not->toContain('Invoice to');

    app(SaveFormStyle::class)->handle(['name' => 'Plain', 'layout' => InvoiceLayout::Classic, 'is_default' => true, 'is_active' => true]);

    expect(($this->html)())->toContain('Ship To')->not->toContain('Invoice to');
});

it('prints in DejaVu Sans so any currency symbol or name prints', function () {
    ($this->airy)();
    $this->invoice->forceFill(['currency_code' => 'INR'])->save();
    $this->invoice->contact->update(['first_name' => 'Zoë', 'last_name' => 'Łukasiewicz']);

    expect(($this->html)())
        ->toContain('font-family: DejaVu Sans')
        ->not->toContain('Helvetica')
        ->toContain('Zoë Łukasiewicz')
        ->toContain('₹762.50');
});

it('shows the total, payment and balance when the invoice is part paid', function () {
    ($this->airy)();
    $this->invoice->forceFill(['amount_paid_cents' => 20000])->save();

    expect(($this->html)())
        ->toContain('>Total<')->toContain('762.50')
        ->toContain('>Payment<')->toContain('-200.00')
        ->toContain('£562.50');
});

it('shows applied credits so the balance due adds up', function () {
    ($this->airy)();
    $this->invoice->forceFill(['reconciled_cents' => 30000])->save();

    expect(($this->html)())
        ->toContain('>Total<')->toContain('762.50')
        ->toContain('>Credits applied<')->toContain('-300.00')
        ->toContain('£462.50');
});

it('shows only the balance due on a plain unpaid invoice', function () {
    ($this->airy)();

    expect(($this->html)())->not->toContain('>Total<')->not->toContain('>Subtotal<')->toContain('£762.50');
});

it('leaves item names off when the item column is turned off', function () {
    ($this->airy)();
    $this->company->fresh()->invoiceSettings->update(['show_item_column' => false]);

    expect(($this->html)())->not->toContain('<div class="item">Support</div>')->toContain('Upgrade MySQL DB 8.0 -&gt; 8.4');
});

it('prints ship-to and shipping details when the invoice has them', function () {
    ($this->airy)();
    $this->invoice->contact->update(['shipping_line1' => '99 Dock Rd', 'shipping_city' => 'Leeds', 'shipping_postal_code' => 'LS1 1AA']);
    $this->invoice->forceFill(['ship_via' => 'Royal Mail', 'tracking_no' => 'RM-123', 'customer_po' => 'PO-55'])->save();

    expect(($this->html)())
        ->toContain('Ship To')->toContain('99 Dock Rd')
        ->toContain('Ship Via')->toContain('Royal Mail')
        ->toContain('Tracking #')->toContain('RM-123')
        ->toContain('P.O. #')->toContain('PO-55')
        ->not->toContain('F.O.B.');
});

it('makes room in the page margin for a multi-line footer', function () {
    $style = ($this->airy)();
    $style->update(['footer_message' => "Late fee 5%.\nCompany no. 00000000.\nThank you!"]);

    // Three 13px lines: the bottom margin grows by 39px and the footer sits
    // below the page content, so it cannot overlap the lines or totals.
    expect(($this->html)())
        ->toContain('@page { margin: 40px 44px 83px 44px; }')
        ->toContain('.footer { position: fixed; bottom: -45px; left: 0; right: 0; height: 39px;')
        ->toContain("<div class=\"footer\">Late fee 5%.\nCompany no. 00000000.\nThank you!</div>");
});

it('prints the labels in French for a French customer', function () {
    ($this->airy)();
    $this->invoice->contact->update(['locale' => 'fr']);

    expect(($this->html)())
        ->toContain('FACTURE')
        ->toContain('Facturer à')
        ->toContain('N° de facture')
        ->toContain('Activité')
        ->toContain('Solde dû')
        ->not->toContain('Invoice to')
        ->not->toContain('Balance Due');
});

it('looks the default style up once per render', function () {
    ($this->airy)();
    $company = $this->company->fresh();
    $invoice = $this->invoice->fresh();

    DB::enableQueryLog();
    app(InvoicePdfRenderer::class)->html($company, $invoice);
    $styleQueries = collect(DB::getQueryLog())->filter(fn (array $q) => str_contains($q['query'], 'form_styles'));
    DB::disableQueryLog();

    expect($styleQueries)->toHaveCount(1);
});

it('stores only known layouts on a form style', function () {
    $style = app(SaveFormStyle::class)->handle(['name' => 'Odd', 'layout' => 'neon', 'is_active' => true]);

    expect($style->layout)->toBe(InvoiceLayout::Classic);

    $style = app(SaveFormStyle::class)->handle(['name' => 'Odd', 'layout' => 'airy'], $style);

    expect($style->fresh()->layout)->toBe(InvoiceLayout::Airy);

    // A new style without a layout is classic in memory as well as in the database.
    expect(app(SaveFormStyle::class)->handle(['name' => 'Bare'])->layout)->toBe(InvoiceLayout::Classic);
});

it('lets the layout be chosen on the form styles page', function () {
    Livewire::test('pages::settings.lists.form-styles', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_name', 'Clean')
        ->set('f_layout', 'airy')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="style-layout-cell">Airy</td>');

    expect(FormStyle::query()->where('name', 'Clean')->value('layout'))->toBe(InvoiceLayout::Airy);
});

it('rejects an unknown layout on the form styles page', function () {
    Livewire::test('pages::settings.lists.form-styles', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_name', 'Odd')
        ->set('f_layout', 'neon')
        ->call('save')
        ->assertHasErrors(['f_layout']);

    expect(FormStyle::query()->where('name', 'Odd')->exists())->toBeFalse();
});
