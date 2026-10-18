<?php

use App\Enums\Country;
use App\Enums\OrganizationType;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\TaxAgency;
use App\Models\TaxCode;
use App\Services\Reporting\InvoicePdfRenderer;
use App\Support\Locales;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| A UK organization in French
|--------------------------------------------------------------------------
|
| French was built for Canada and the UK for English, so this pins where the
| two meet: UK wording, the British starter chart and a French-language UK
| invoice.
|
*/

afterEach(function () {
    app()->forgetInstance('current_company');
    Locales::apply('en');
});

it('translates the UK organization types into French', function () {
    Locales::apply('fr');

    expect(OrganizationType::SoleProprietorship->label(Country::UnitedKingdom))->toBe('Entreprise individuelle (sole trader)')
        ->and(OrganizationType::Corporation->label(Country::UnitedKingdom))->toBe('Société à responsabilité limitée (Ltd)')
        ->and(OrganizationType::Partnership->description(Country::UnitedKingdom))->toContain('LLP');
});

it('does not promise Gift Aid receipts to a UK charity', function () {
    expect(OrganizationType::Charity->helpText(Country::UnitedKingdom))->not->toContain('Gift Aid');
});

it('shows the British starter chart, VAT codes, HMRC and payment methods in French', function () {
    $company = Company::factory()->forCountry(Country::UnitedKingdom, 'ENG')->create();
    app()->instance('current_company', $company);
    Locales::apply('fr');

    expect(Account::where('code', '1000')->firstOrFail()->displayName())->toBe('Compte courant')
        ->and(Account::where('code', '2200')->firstOrFail()->displayName())->toBe('TVA à payer')
        ->and(TaxCode::where('code', 'VAT')->firstOrFail()->displayName())->toBe('TVA normale (20 %)')
        ->and(TaxCode::where('code', 'VAT-R')->firstOrFail()->label())->toBe('TVA-R')
        ->and(TaxAgency::firstOrFail()->label())->toBe('HM Revenue & Customs (HMRC)')
        ->and(PaymentMethod::where('name', 'Direct Debit')->firstOrFail()->displayName())->toBe('Prélèvement automatique');

    // Stored names stay English, so lookups by name keep working.
    expect(Account::where('code', '2200')->value('name'))->toBe('VAT Payable');
});

it('keeps day-first dates on a French invoice from a UK organization', function () {
    $company = Company::factory()->forCountry(Country::UnitedKingdom, 'ENG')->create(['locale' => 'en']);
    app()->instance('current_company', $company);

    $customer = Contact::create(['display_name' => 'Client SARL', 'is_customer' => true, 'locale' => 'fr']);
    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-UK-FR-1',
        'invoice_date' => '2026-03-04',
        'due_date' => '2026-04-03',
    ]);

    $html = app(InvoicePdfRenderer::class)->html($company, $invoice->fresh(['contact']));

    expect($html)->toContain('FACTURE')
        ->and($html)->toContain('04/03/2026')
        ->and($html)->toContain('03/04/2026')
        ->and($html)->not->toContain('2026-03-04');
});

it('still prints ISO dates on a French invoice from a Canadian organization', function () {
    expect(Locales::using('fr', fn () => Locales::formatDate(CarbonImmutable::parse('2026-03-04'), Country::Canada->shortDateFormat())))
        ->toBe('2026-03-04')
        ->and(Locales::using('fr', fn () => Locales::formatDate(CarbonImmutable::parse('2026-03-04'), Country::UnitedKingdom->shortDateFormat())))
        ->toBe('04/03/2026');
});
