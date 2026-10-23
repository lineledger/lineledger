<?php

use App\Actions\Sales\SaveFormStyle;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\Country;
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
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->forCountry(Country::UnitedKingdom)->create([
        'name' => 'Xtech Web Services Ltd',
        'address_region' => 'ENG',
        'email' => 'hello@xws.im',
        'website' => 'https://www.xws.im',
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
        'payment_instructions' => "Payments to be made via Bank transfer to:\nAccount Number: 28483796",
        'footer_message' => 'Invoices that are not paid by their due date will incur a 5% late fee automatically.',
    ])->save();

    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();
    $customer = Contact::create([
        'display_name' => 'Global Autocare',
        'company_name' => 'Global Autocare',
        'first_name' => 'Stephen',
        'last_name' => 'Charter',
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
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('prints with the Airy layout when the invoice style uses it', function () {
    app(SaveFormStyle::class)->handle(['name' => 'XWS', 'layout' => FormStyle::LAYOUT_AIRY, 'is_default' => true, 'is_active' => true]);

    $html = app(InvoicePdfRenderer::class)->html($this->company->fresh(), $this->invoice);

    expect($html)
        ->toContain('Invoice to')
        ->toContain('Stephen Charter')
        ->toContain('Global Autocare')
        ->toContain('Invoice no.')
        ->toContain('10/10/2026')
        ->toContain('<div class="item">Support</div>')
        ->toContain('Upgrade MySQL DB 8.0 -&gt; 8.4')
        ->toContain('>7.50<')
        ->toContain('>1<')
        ->toContain('85.00')
        ->toContain('BALANCE DUE')
        ->toContain('£762.50')
        ->toContain('Thank you for your business and have a great day!')
        ->toContain('Account Number: 28483796')
        ->toContain('5% late fee')
        ->toContain('hello@xws.im')
        ->not->toContain('Ship To');

    // A real PDF comes out of it.
    expect(substr(app(InvoicePdfRenderer::class)->raw($this->company->fresh(), $this->invoice), 0, 4))->toBe('%PDF');
});

it('keeps the classic layout when there is no style, or the style is classic', function () {
    $renderer = app(InvoicePdfRenderer::class);

    expect($renderer->html($this->company, $this->invoice))->toContain('Ship To')->not->toContain('BALANCE DUE');

    app(SaveFormStyle::class)->handle(['name' => 'Plain', 'layout' => FormStyle::LAYOUT_CLASSIC, 'is_default' => true, 'is_active' => true]);

    expect($renderer->html($this->company->fresh(), $this->invoice))->toContain('Ship To');
});

it('shows subtotal, payment and balance when the invoice is part paid', function () {
    app(SaveFormStyle::class)->handle(['name' => 'XWS', 'layout' => FormStyle::LAYOUT_AIRY, 'is_default' => true, 'is_active' => true]);
    $this->invoice->forceFill(['amount_paid_cents' => 20000])->save();

    $html = app(InvoicePdfRenderer::class)->html($this->company->fresh(), $this->invoice->fresh());

    expect($html)->toContain('TOTAL')->toContain('PAYMENT')->toContain('200.00')->toContain('£562.50');
});

it('stores only known layouts on a form style', function () {
    $style = app(SaveFormStyle::class)->handle(['name' => 'Odd', 'layout' => 'neon', 'is_active' => true]);

    expect($style->layout)->toBe(FormStyle::LAYOUT_CLASSIC);

    $style = app(SaveFormStyle::class)->handle(['name' => 'Odd', 'layout' => FormStyle::LAYOUT_AIRY], $style);

    expect($style->fresh()->layout)->toBe(FormStyle::LAYOUT_AIRY);
});

it('lets the layout be chosen on the form styles page', function () {
    Livewire::test('pages::settings.lists.form-styles', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_name', 'XWS')
        ->set('f_layout', FormStyle::LAYOUT_AIRY)
        ->call('save')
        ->assertHasNoErrors();

    expect(FormStyle::query()->where('name', 'XWS')->value('layout'))->toBe(FormStyle::LAYOUT_AIRY);
});
