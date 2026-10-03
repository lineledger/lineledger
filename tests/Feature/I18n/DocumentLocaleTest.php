<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\Contact;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\Sales\InvoiceSharedNotification;
use App\Services\Reporting\InvoicePdfRenderer;
use App\Support\Locales;
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
    Locales::apply('en');
});

function makeLocaleInvoice(object $test, Contact $customer): Invoice
{
    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-I18N-1',
        'invoice_date' => CarbonImmutable::create(2026, 9, 7),
        'due_date' => CarbonImmutable::create(2026, 10, 7),
    ]);

    $invoice->lines()->create([
        'account_id' => $test->income->id,
        'description' => 'Consulting',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'line_subtotal_cents' => 10000,
        'line_tax_cents' => 0,
        'line_total_cents' => 10000,
        'line_order' => 0,
    ]);

    return $invoice->fresh(['contact']);
}

it('resolves document locale from the contact then the company', function () {
    $company = $this->company;
    $company->update(['locale' => 'fr']);

    $customer = Contact::create(['display_name' => 'Acme', 'is_customer' => true]);

    expect(Locales::forDocument($customer->fresh(), $company->fresh()))->toBe('fr');

    $customer->update(['locale' => 'en']);

    expect(Locales::forDocument($customer->fresh(), $company->fresh()))->toBe('en');
});

it('defaults document locale to French for a Quebec company when unset', function () {
    $company = Company::factory()->create(['address_region' => 'QC', 'locale' => null]);
    $customer = Contact::create(['display_name' => 'Acme', 'is_customer' => true, 'locale' => null]);

    expect(Locales::forDocument($customer, $company))->toBe('fr');
});

it('defaults company locale to French when created with Quebec region', function () {
    $company = Company::factory()->create(['address_region' => 'QC']);

    expect($company->locale)->toBe('fr');
});

it('renders invoice chrome in the customer document language', function () {
    $this->company->update(['locale' => 'en']);
    $customer = Contact::create([
        'display_name' => 'Acme',
        'is_customer' => true,
        'locale' => 'fr',
    ]);
    $invoice = makeLocaleInvoice($this, $customer);

    $html = app(InvoicePdfRenderer::class)->html($this->company, $invoice);

    expect($html)->toContain('FACTURE')
        ->and($html)->toContain('Facturer à')
        ->and($html)->not->toContain('>INVOICE<');
});

it('falls back to the organization document language when the contact has none', function () {
    $this->company->update(['locale' => 'fr']);
    $customer = Contact::create(['display_name' => 'Acme', 'is_customer' => true]);
    $invoice = makeLocaleInvoice($this, $customer);

    $html = app(InvoicePdfRenderer::class)->html($this->company, $invoice);

    expect($html)->toContain('FACTURE');
});

it('does not use the staff UI locale for the invoice PDF', function () {
    $this->user->update(['locale' => 'fr']);
    $this->company->update(['locale' => 'en']);
    $customer = Contact::create(['display_name' => 'Acme', 'is_customer' => true]);
    $invoice = makeLocaleInvoice($this, $customer);

    Locales::apply('fr');

    $html = app(InvoicePdfRenderer::class)->html($this->company, $invoice);

    expect($html)->toContain('INVOICE')
        ->and($html)->not->toContain('FACTURE');
});

it('writes the invoice email in the customer document language', function () {
    $customer = Contact::create([
        'display_name' => 'Acme',
        'email' => 'buyer@acme.test',
        'is_customer' => true,
        'locale' => 'fr',
    ]);
    $invoice = makeLocaleInvoice($this, $customer);
    $companyName = $this->company->brand_name ?: $this->company->name;

    $mail = (new InvoiceSharedNotification($invoice, $this->company, 'http://pay.test/x', 'Please pay.'))
        ->toMail($customer);

    expect($mail->subject)->toBe('Facture INV-I18N-1 de '.$companyName);
});

it('restores the previous locale after rendering a document', function () {
    Locales::apply('en');
    $customer = Contact::create(['display_name' => 'Acme', 'is_customer' => true, 'locale' => 'fr']);
    $invoice = makeLocaleInvoice($this, $customer);

    app(InvoicePdfRenderer::class)->html($this->company, $invoice);

    expect(app()->getLocale())->toBe('en');
});

it('saves the staff interface language on the profile page', function () {
    Livewire::test('pages::settings.profile')
        ->set('locale', 'fr')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($this->user->fresh()->locale)->toBe('fr');
});

it('applies ?lang= for guests and shows the language switcher', function () {
    auth()->logout();

    $this->get(route('login', ['lang' => 'fr']))
        ->assertOk()
        ->assertSee('data-test="language-switcher"', false)
        ->assertSee('Français');
});

it('renders estimate chrome in the customer document language', function () {
    $customer = Contact::create([
        'display_name' => 'Acme',
        'is_customer' => true,
        'locale' => 'fr',
    ]);

    $estimate = Estimate::create([
        'contact_id' => $customer->id,
        'estimate_no' => 'EST-I18N-1',
        'estimate_date' => CarbonImmutable::create(2026, 9, 7),
        'expires_on' => CarbonImmutable::create(2026, 10, 7),
    ]);
    $estimate->lines()->create([
        'account_id' => $this->income->id,
        'description' => 'Consulting',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'line_subtotal_cents' => 10000,
        'line_tax_cents' => 0,
        'line_total_cents' => 10000,
        'line_order' => 0,
    ]);
    $estimate->load('contact', 'lines.taxCode', 'lines.item', 'terms', 'salesRep');

    $html = Locales::forContactDocument($customer, $this->company, fn (): string => view('pdf.estimates.estimate', [
        'company' => $this->company,
        'estimate' => $estimate,
        'settings' => $this->company->invoiceSettingsOrNew(),
        'taxSummary' => [],
    ])->render());

    expect($html)->toContain('SOUMISSION')
        ->and($html)->not->toContain('>ESTIMATE<');
});

it('prints both GST and TVQ numbers on an estimate for a Quebec company', function () {
    $qcCompany = Company::factory()->create([
        'address_region' => 'QC',
        'locale' => 'fr',
        'tax_number' => '123456789 RT0001',
    ]);
    $agency = $qcCompany->provincialTaxAgency();
    $agency?->update(['registration_number' => '1234567890 TQ 0001']);

    $customer = Contact::create([
        'company_id' => $qcCompany->id,
        'display_name' => 'Acme Corp',
        'is_customer' => true,
        'locale' => 'fr',
    ]);

    $estimate = Estimate::create([
        'company_id' => $qcCompany->id,
        'contact_id' => $customer->id,
        'estimate_no' => 'EST-QC-001',
        'estimate_date' => CarbonImmutable::create(2026, 5, 24),
    ]);

    $html = Locales::forContactDocument($customer, $qcCompany, fn (): string => view('pdf.estimates.estimate', [
        'company' => $qcCompany,
        'estimate' => $estimate->load('contact', 'lines.taxCode', 'lines.item', 'terms', 'salesRep'),
        'settings' => $qcCompany->invoiceSettingsOrNew(),
        'taxSummary' => [],
    ])->render());

    expect($html)
        ->toContain('N° TPS/TVH')
        ->toContain('123456789 RT0001')
        ->toContain('N° TVQ')
        ->toContain('1234567890 TQ 0001');
});

it('writes a company invitation in the invitee locale', function () {
    $invitee = User::factory()->create(['locale' => 'fr']);
    $invitation = CompanyInvitation::create([
        'company_id' => $this->company->id,
        'email' => $invitee->email,
        'role' => CompanyRole::Admin->value,
        'invited_by' => $this->user->id,
    ]);
    $invitation->setRelation('company', $this->company);
    $invitation->setRelation('inviter', $this->user);

    $mail = (new App\Notifications\Companies\CompanyInvitation($invitation))->toMail($invitee);

    expect($mail->subject)->toContain('invité');
});
