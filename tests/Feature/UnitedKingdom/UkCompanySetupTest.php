<?php

use App\Actions\Companies\CreateCompany;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\Country;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceSetting;
use App\Models\TaxAgency;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\Posting\InvoicePoster;
use App\Services\Reporting\InvoicePdfRenderer;
use App\Support\Reporting\StatementColumns;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| UK company setup and documents
|--------------------------------------------------------------------------
|
| What a UK owner sees from sign-up to their first invoice: the wizard's
| VAT and Companies House fields, the seeded VAT chart and HMRC (or Isle of
| Man) agency, a GBP / day-first invoice, and VAT number checks.
|
*/

function ukOnboardingUser(): User
{
    $user = User::factory()->create();
    $user->companies()->detach();
    $user->forceFill(['current_company_id' => null])->save();

    test()->actingAs($user);

    return $user;
}

/*
| Country helpers
*/

it('normalizes and validates UK VAT registration numbers', function (string $typed, ?string $stored, bool $valid) {
    $uk = Country::UnitedKingdom;

    expect($uk->normalizeTaxNumber($typed))->toBe($stored)
        ->and($uk->isValidTaxNumber($typed))->toBe($valid);
})->with([
    'bare nine digits' => ['123456789', 'GB123456789', true],
    'spaced as printed' => ['GB 123 4567 89', 'GB123456789', true],
    'lower-case prefix' => ['gb123456789', 'GB123456789', true],
    'branch trader (12 digits)' => ['123456789001', 'GB123456789001', true],
    'government department' => ['GBGD001', 'GBGD001', true],
    'health authority' => ['GBHA599', 'GBHA599', true],
    'Northern Ireland' => ['XI 123456789', 'XI123456789', true],
    'blank' => ['   ', null, true],
    'too short' => ['GB12345678', 'GB12345678', false],
    'letters' => ['GB12345678A', 'GB12345678A', false],
    'Irish VAT' => ['IE1234567T', 'IE1234567T', false],
]);

it('leaves Canadian and US tax numbers exactly as typed', function () {
    expect(Country::Canada->normalizeTaxNumber(' 123456789 RT0001 '))->toBe('123456789 RT0001')
        ->and(Country::Canada->isValidTaxNumber('anything'))->toBeTrue()
        ->and(Country::UnitedStates->isValidTaxNumber('12-3456789'))->toBeTrue();
});

it('pads purely numeric Companies House numbers to eight digits', function () {
    $uk = Country::UnitedKingdom;

    expect($uk->normalizeCompanyRegistrationNumber('1234567'))->toBe('01234567')
        ->and($uk->normalizeCompanyRegistrationNumber(' sc 123456 '))->toBe('SC123456')
        ->and($uk->normalizeCompanyRegistrationNumber('oc301234'))->toBe('OC301234')
        // Isle of Man Companies Registry numbers keep their suffix.
        ->and($uk->normalizeCompanyRegistrationNumber('012345c'))->toBe('012345C')
        ->and($uk->normalizeCompanyRegistrationNumber(''))->toBeNull();
});

it('writes UK dates day first and keeps North America month first', function () {
    $date = CarbonImmutable::create(2026, 3, 4);

    expect($date->format(Country::UnitedKingdom->shortDateFormat()))->toBe('04/03/2026')
        ->and($date->format(Country::Canada->shortDateFormat()))->toBe('3/4/2026')
        ->and($date->format(Country::UnitedStates->shortDateFormat()))->toBe('3/4/2026');
});

it('pins the Isle of Man and Belfast from their own time zones', function () {
    expect(Country::UnitedKingdom->regionForTimezone('Europe/Isle_of_Man'))->toBe('IM')
        ->and(Country::UnitedKingdom->regionForTimezone('Europe/Belfast'))->toBe('NIR')
        ->and(Country::UnitedKingdom->regionForTimezone('Europe/London'))->toBeNull();
});

it('uses UK wording for VAT fields and input tax', function () {
    expect(Country::UnitedKingdom->taxNumberLabel())->toBe('VAT registration number')
        ->and(Country::UnitedKingdom->inputTaxLabel())->toBe('Input VAT')
        ->and(Country::Canada->inputTaxLabel())->toBe('ITC')
        ->and(Country::UnitedKingdom->taxAgencyNameForRegion('IM'))->toBe('Isle of Man Customs and Excise')
        ->and(Country::UnitedKingdom->taxAgencyNameForRegion('ENG'))->toBeNull()
        ->and(Country::Canada->taxAgencyNameForRegion('IM'))->toBeNull();
});

/*
| Company creation
*/

it('creates a UK company on GBP, London time and an April fiscal year', function () {
    $user = User::factory()->create();

    $company = app(CreateCompany::class)->handle($user, 'Thames Trading Ltd', country: Country::UnitedKingdom, regionCode: 'ENG');

    expect($company->currency_code)->toBe('GBP')
        ->and($company->timezone)->toBe('Europe/London')
        ->and($company->fiscal_year_start_month)->toBe(4)
        ->and($company->jurisdiction)->toBe(Country::UnitedKingdom);

    $agency = TaxAgency::withoutGlobalScopes()->where('company_id', $company->id)->sole();
    $codes = TaxCode::withoutGlobalScopes()->where('company_id', $company->id)->orderBy('code')->get()->keyBy('code');

    expect($agency->name)->toBe('HM Revenue & Customs')
        ->and($agency->payableAccount->name)->toBe('VAT Payable')
        ->and((int) $codes['VAT']->rate_basis_points)->toBe(2000)
        ->and((int) $codes['VAT-R']->rate_basis_points)->toBe(500)
        ->and((int) $codes['ZR']->rate_basis_points)->toBe(0)
        ->and($codes['ZR']->is_recoverable)->toBeTrue()
        ->and($codes['EX']->is_recoverable)->toBeFalse()
        ->and($codes->pluck('agency_id')->unique()->all())->toBe([$agency->id]);
});

it('keeps Canadian companies on a calendar fiscal year', function () {
    $company = app(CreateCompany::class)->handle(User::factory()->create(), 'Maple Co', country: Country::Canada, regionCode: 'ON');

    expect($company->fiscal_year_start_month)->toBe(1);
});

it('seeds Isle of Man Customs and Excise as the VAT agency for a Manx company', function () {
    $company = app(CreateCompany::class)->handle(User::factory()->create(), 'Laxey Ltd', country: Country::UnitedKingdom, regionCode: 'IM');

    $agency = TaxAgency::withoutGlobalScopes()->where('company_id', $company->id)->sole();

    expect($agency->name)->toBe('Isle of Man Customs and Excise')
        ->and(TaxCode::withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'VAT')->value('agency_id'))->toBe($agency->id);
});

/*
| Setup wizard
*/

it('suggests an April fiscal year for the UK until the owner picks one', function () {
    ukOnboardingUser();

    Livewire::test('pages::welcome.setup-wizard')
        ->set('country', 'GB')
        ->assertSet('fiscalYearStartMonth', 4)
        ->assertSet('currency', 'GBP')
        ->set('country', 'CA')
        ->assertSet('fiscalYearStartMonth', 1)
        ->set('fiscalYearStartMonth', 7)
        ->set('country', 'GB')
        ->assertSet('fiscalYearStartMonth', 7);
});

it('labels the wizard tax number as a VAT registration number and rejects bad ones', function () {
    ukOnboardingUser();

    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'Bad VAT Ltd')
        ->set('country', 'GB')
        ->set('region', 'ENG')
        ->set('currency', 'GBP')
        ->set('organizationType', 'corporation')
        ->set('orgCategory', 'corporation')
        ->set('chargesTax', true)
        ->set('step', 5)
        ->assertSee('VAT registration number')
        ->set('taxNumber', 'GB12345')
        ->call('next')
        ->assertHasErrors(['taxNumber']);
});

it('creates a UK company from the wizard with a normalized VAT and company number', function () {
    $user = ukOnboardingUser();

    Livewire::test('pages::welcome.setup-wizard')
        ->set('companyName', 'Normalised Ltd')
        ->set('country', 'GB')
        ->set('region', 'SCT')
        ->set('currency', 'GBP')
        ->set('organizationType', 'corporation')
        ->set('orgCategory', 'corporation')
        ->set('companyRegistrationNumber', 'sc 123456')
        ->set('chargesTax', true)
        ->set('taxNumber', '123 4567 89')
        ->call('createCompany')
        ->assertHasNoErrors()
        ->assertRedirect();

    $company = Company::where('name', 'Normalised Ltd')->firstOrFail();

    expect($company->tax_number)->toBe('GB123456789')
        ->and($company->company_registration_number)->toBe('SC123456')
        ->and($company->fiscal_year_start_month)->toBe(4)
        ->and(TaxAgency::withoutGlobalScopes()->where('company_id', $company->id)->first()->registration_number)->toBe('GB123456789')
        ->and($user->fresh()->current_company_id)->toBe($company->id);
});

/*
| Settings
*/

it('validates and normalizes the VAT number in invoice settings', function () {
    $user = User::factory()->create();
    $company = Company::factory()->forCountry(Country::UnitedKingdom, 'ENG')->create();
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($user);
    app()->instance('current_company', $company);

    Livewire::test('pages::settings.invoices', ['company' => $company])
        ->assertSee('Tax / VAT registration number')
        ->set('taxNumber', 'GB 999')
        ->call('save')
        ->assertHasErrors(['taxNumber']);

    Livewire::test('pages::settings.invoices', ['company' => $company])
        ->set('taxNumber', 'gb 987 6543 21')
        ->call('save')
        ->assertHasNoErrors();

    expect($company->fresh()->tax_number)->toBe('GB987654321');
});

it('normalizes the Companies House number on the company settings page', function () {
    $user = User::factory()->create();
    $company = Company::factory()->forCountry(Country::UnitedKingdom, 'ENG')->create(['organization_type' => 'corporation']);
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($user);
    app()->instance('current_company', $company);

    Livewire::test('pages::companies.edit', ['company' => $company])
        ->assertSee('Company number')
        ->set('companyRegistrationNumber', '1234567')
        ->call('updateCompany')
        ->assertHasNoErrors();

    expect($company->fresh()->company_registration_number)->toBe('01234567');
});

/*
| Documents
*/

it('prints a UK invoice in pounds with day-first dates, the VAT number and company number', function () {
    $company = Company::factory()->forCountry(Country::UnitedKingdom, 'ENG')->create([
        'tax_number' => 'GB123456789',
        'company_registration_number' => '01234567',
    ]);
    app()->instance('current_company', $company);

    $customer = Contact::create(['display_name' => 'Customer Ltd', 'is_customer' => true]);
    $vat = TaxCode::where('code', 'VAT')->firstOrFail();

    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-UK-1',
        'invoice_date' => '2026-03-04',
        'due_date' => '2026-04-03',
    ]);
    $invoice->lines()->create([
        'account_id' => Account::where('code', '4000')->value('id'),
        'tax_code_id' => $vat->id,
        'description' => 'Consulting',
        'quantity' => '1',
        'unit_price_cents' => 100000,
        'line_subtotal_cents' => 100000,
        'line_tax_cents' => 20000,
        'line_total_cents' => 120000,
        'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($invoice);

    $renderer = app(InvoicePdfRenderer::class);
    $html = view('pdf.invoices.invoice', (new ReflectionMethod($renderer, 'data'))->invoke($renderer, $company, $invoice->fresh()))->render();

    expect($html)
        ->toContain('£1,200.00')
        ->toContain('04/03/2026')
        ->toContain('03/04/2026')
        ->toContain('VAT No.')
        ->toContain('GB123456789')
        ->toContain('Company number')
        ->toContain('01234567')
        ->not->toContain('$1,200.00')
        ->not->toContain('3/4/2026');

    app()->forgetInstance('current_company');
});

it('keeps Canadian invoices in dollars with month-first dates', function () {
    $company = Company::factory()->create();
    app()->instance('current_company', $company);

    $customer = Contact::create(['display_name' => 'Client Inc', 'is_customer' => true]);
    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-CA-1',
        'invoice_date' => '2026-03-04',
        'due_date' => '2026-03-04',
    ]);
    $invoice->lines()->create([
        'account_id' => Account::where('subtype', AccountSubtype::Income->value)->value('id'),
        'description' => 'Work',
        'quantity' => '1',
        'unit_price_cents' => 5000,
        'line_subtotal_cents' => 5000,
        'line_tax_cents' => 0,
        'line_total_cents' => 5000,
        'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($invoice);

    $html = view('pdf.invoices.invoice', [
        'company' => $company,
        'invoice' => $invoice->fresh()->load('lines.taxCode', 'lines.item', 'contact', 'terms'),
        'settings' => new InvoiceSetting([...InvoiceSetting::defaults(), 'company_id' => $company->id]),
        'taxSummary' => [],
        'logoData' => null,
    ])->render();

    expect($html)->toContain('$50.00')->toContain('3/4/2026');

    app()->forgetInstance('current_company');
});

it('formats statement dates for the jurisdiction', function () {
    $row = ['date' => '2026-03-04'];

    expect(StatementColumns::cell('date', $row, Country::UnitedKingdom->shortDateFormat()))->toBe('04/03/2026')
        ->and(StatementColumns::cell('date', $row))->toBe('3/4/2026');
});

it('shows dashboard totals in pounds for a UK company', function () {
    $user = User::factory()->create();
    $company = Company::factory()->forCountry(Country::UnitedKingdom, 'ENG')->create();
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);
    $user->forceFill(['current_company_id' => $company->id])->save();
    $this->actingAs($user);
    app()->instance('current_company', $company);

    Livewire::test('pages::dashboard.index', ['company' => $company])
        ->assertSee('£0')
        ->assertDontSee('$0');
});

it('words a UK tax-code form for VAT', function () {
    $user = User::factory()->create();
    $company = Company::factory()->forCountry(Country::UnitedKingdom, 'ENG')->create();
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($user);
    app()->instance('current_company', $company);

    Livewire::test('pages::settings.lists.tax-codes', ['company' => $company])
        ->call('openCreate')
        ->assertSee('Recoverable (input VAT)')
        ->assertSee('20% standard, 5% reduced, 0% zero-rated');
});
