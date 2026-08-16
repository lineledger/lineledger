<?php

use App\Actions\Sales\SaveFormStyle;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\FormStyle;
use App\Models\Invoice;
use App\Models\InvoiceSetting;
use App\Models\User;
use App\Services\Reporting\InvoicePdfRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
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

function makeStyledInvoice(object $test, ?int $formStyleId = null): Invoice
{
    $customer = Contact::create(['display_name' => 'Styled Customer', 'is_customer' => true]);

    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-STYLE-1',
        'invoice_date' => CarbonImmutable::create(2026, 6, 9),
        'due_date' => CarbonImmutable::create(2026, 7, 9),
        'form_style_id' => $formStyleId,
    ]);

    $invoice->lines()->create([
        'account_id' => $test->income->id,
        'description' => 'Styled services',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'line_subtotal_cents' => 10000,
        'line_tax_cents' => 0,
        'line_total_cents' => 10000,
        'line_order' => 0,
    ]);

    return $invoice->fresh();
}

/**
 * Render the invoice PDF blade with the renderer's own view data.
 */
function renderInvoiceHtml(Company $company, Invoice $invoice): string
{
    $renderer = app(InvoicePdfRenderer::class);
    $method = new ReflectionMethod($renderer, 'data');

    return view('pdf.invoices.invoice', $method->invoke($renderer, $company, $invoice))->render();
}

it('creates a form style via the settings page', function () {
    Livewire::test('pages::settings.lists.form-styles', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_name', 'Modern Blue')
        ->set('f_accent_color', '#2563eb')
        ->set('f_footer_message', 'Pay within 30 days.')
        ->set('f_show_logo', false)
        ->call('save')
        ->assertHasNoErrors();

    $style = FormStyle::query()->where('name', 'Modern Blue')->first();

    expect($style)->not->toBeNull()
        ->and($style->company_id)->toBe($this->company->id)
        ->and($style->accent_color)->toBe('#2563eb')
        ->and($style->footer_message)->toBe('Pay within 30 days.')
        ->and($style->show_logo)->toBeFalse()
        ->and($style->is_active)->toBeTrue();
});

it('edits a form style via the settings page', function () {
    $style = FormStyle::create(['name' => 'Plain', 'is_active' => true]);

    Livewire::test('pages::settings.lists.form-styles', ['company' => $this->company])
        ->call('openEdit', $style->id)
        ->set('f_name', 'Plainer')
        ->set('f_accent_color', '#ff0000')
        ->call('save')
        ->assertHasNoErrors();

    $style->refresh();
    expect($style->name)->toBe('Plainer')
        ->and($style->accent_color)->toBe('#ff0000');
});

it('rejects an invalid accent colour', function () {
    Livewire::test('pages::settings.lists.form-styles', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_name', 'Bad Colour')
        ->set('f_accent_color', 'blue')
        ->call('save')
        ->assertHasErrors(['f_accent_color']);
});

it('makes the first active style the default automatically', function () {
    $style = app(SaveFormStyle::class)->handle(['name' => 'First']);

    expect($style->is_default)->toBeTrue();
});

it('clears the previous default when a new default is set', function () {
    $first = app(SaveFormStyle::class)->handle(['name' => 'First']);
    $second = app(SaveFormStyle::class)->handle(['name' => 'Second', 'is_default' => true]);

    expect($second->is_default)->toBeTrue()
        ->and($first->refresh()->is_default)->toBeFalse()
        ->and(FormStyle::query()->where('is_default', true)->count())->toBe(1);
});

it('keeps non-default new styles non-default once a default exists', function () {
    $first = app(SaveFormStyle::class)->handle(['name' => 'First']);
    $second = app(SaveFormStyle::class)->handle(['name' => 'Second']);

    expect($first->refresh()->is_default)->toBeTrue()
        ->and($second->is_default)->toBeFalse();
});

it('hides the form style picker when the company has no active styles', function () {
    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->assertDontSeeHtml('data-test="invoice-form-style"');
});

it('defaults new invoices to the company default style and persists the pick', function () {
    $default = app(SaveFormStyle::class)->handle(['name' => 'House Style']);
    $other = app(SaveFormStyle::class)->handle(['name' => 'Alt Style']);

    $customer = Contact::create(['display_name' => 'Picker Co', 'is_customer' => true]);

    $component = Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->assertSeeHtml('data-test="invoice-form-style"')
        ->assertSet('form_style_id', $default->id)
        ->call('selectContact', $customer->id)
        ->set('lines.0.account_id', $this->income->id)
        ->set('lines.0.description', 'Styled work')
        ->set('lines.0.unit_price', '50.00')
        ->set('form_style_id', $other->id)
        ->call('saveDraft')
        ->assertHasNoErrors();

    $invoice = Invoice::query()->where('contact_id', $customer->id)->firstOrFail();
    expect($invoice->form_style_id)->toBe($other->id);
});

it('renders the style accent, footer and logo override on the invoice pdf', function () {
    InvoiceSetting::updateOrCreate(['company_id' => $this->company->id], [
        'show_logo' => true,
        'footer_message' => 'Settings footer',
    ]);

    $style = app(SaveFormStyle::class)->handle([
        'name' => 'Branded',
        'show_logo' => false,
        'accent_color' => '#aa12bc',
        'footer_message' => 'Style footer wins',
    ]);

    $invoice = makeStyledInvoice($this, $style->id);

    $html = renderInvoiceHtml($this->company, $invoice);

    expect($html)
        ->toContain('#aa12bc')
        ->toContain('Style footer wins')
        ->not->toContain('Settings footer');
});

it('suppresses the logo when the style hides it even though settings show it', function () {
    Storage::fake('public');
    $logoPath = 'logos/test-logo.png';
    Storage::disk('public')->put($logoPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    $this->company->update(['logo_path' => $logoPath]);

    InvoiceSetting::updateOrCreate(['company_id' => $this->company->id], ['show_logo' => true]);

    $style = app(SaveFormStyle::class)->handle(['name' => 'No Logo', 'show_logo' => false]);
    $invoice = makeStyledInvoice($this, $style->id);

    $html = renderInvoiceHtml($this->company, $invoice);

    expect($html)->not->toContain('<img src="data:image');

    // Sanity check: without the style the logo renders.
    $invoice->update(['form_style_id' => null]);
    $style->delete();

    $html = renderInvoiceHtml($this->company, $invoice->fresh());
    expect($html)->toContain('<img src="data:image');
});

it('falls back to the company default style when the invoice has none', function () {
    app(SaveFormStyle::class)->handle(['name' => 'Default Style', 'accent_color' => '#00ff99']);

    $invoice = makeStyledInvoice($this);

    $html = renderInvoiceHtml($this->company, $invoice);

    expect($html)->toContain('#00ff99');
});

it('renders exactly the settings-driven output when no styles exist', function () {
    InvoiceSetting::updateOrCreate(['company_id' => $this->company->id], [
        'footer_message' => 'Plain settings footer',
    ]);

    $invoice = makeStyledInvoice($this);

    $html = renderInvoiceHtml($this->company, $invoice);

    expect($html)
        ->toContain('Plain settings footer')
        ->toContain('INV-STYLE-1')
        ->not->toContain('border-top-color');
});
