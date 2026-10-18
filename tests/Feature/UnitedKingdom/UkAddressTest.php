<?php

use App\Enums\Country;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Services\Reporting\InvoicePdfRenderer;
use App\Support\Contacts\AddressLines;

/*
|--------------------------------------------------------------------------
| UK addresses on documents
|--------------------------------------------------------------------------
|
| A UK organization and its contacts store their nation (ENG, SCT, …) as the
| region. British addresses never print it, so the locality line is town and
| postcode only; North American addresses keep their province or state.
|
*/

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('leaves the nation out of a UK locality line and keeps a province or state', function () {
    $uk = Company::factory()->forCountry(Country::UnitedKingdom, 'ENG')->create();
    $ca = Company::factory()->create();

    expect(AddressLines::locality('Reading', 'ENG', 'RG1 2EQ', 'GB', $uk))->toBe('Reading, RG1 2EQ')
        // No country of its own: the address takes the company's.
        ->and(AddressLines::locality('Reading', 'ENG', 'RG1 2EQ', null, $uk))->toBe('Reading, RG1 2EQ')
        // A Canadian customer of a UK company keeps its province.
        ->and(AddressLines::locality('Toronto', 'ON', 'M5V 2T6', 'CA', $uk))->toBe('Toronto, ON, M5V 2T6')
        ->and(AddressLines::locality('Toronto', 'ON', 'M5V 2T6', null, $ca))->toBe('Toronto, ON, M5V 2T6')
        ->and(AddressLines::locality('Toronto', 'ON', 'M5V 2T6', null, $ca, ' '))->toBe('Toronto ON M5V 2T6')
        ->and(AddressLines::locality(null, null, null, null, $ca))->toBe('');

    expect(AddressLines::oneLine(['line1' => '1 High St', 'city' => 'Reading', 'region' => 'ENG', 'postal_code' => 'RG1 2EQ', 'country' => 'GB'], $uk))
        ->toBe('1 High St, Reading, RG1 2EQ')
        ->and(AddressLines::format(['line1' => '1 High St', 'city' => 'Reading', 'region' => 'ENG', 'postal_code' => 'RG1 2EQ'], $uk))
        ->toBe(['1 High St', 'Reading  RG1 2EQ']);
});

it('prints a UK invoice without the nation code in either address', function () {
    $company = Company::factory()->forCountry(Country::UnitedKingdom, 'ENG')->create([
        'address_line1' => '4 Market Place',
        'address_city' => 'Reading',
        'address_region' => 'ENG',
        'address_postal_code' => 'RG1 2EQ',
    ]);
    app()->instance('current_company', $company);

    $customer = Contact::create([
        'display_name' => 'Harbour Lights Hotel',
        'is_customer' => true,
        'billing_line1' => '2 Esplanade',
        'billing_city' => 'Weymouth',
        'billing_region' => 'ENG',
        'billing_postal_code' => 'DT4 8DN',
        'billing_country' => 'GB',
    ]);
    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-UK-ADDR',
        'invoice_date' => '2026-03-04',
        'due_date' => '2026-04-03',
    ]);

    $html = app(InvoicePdfRenderer::class)->html($company, $invoice->fresh(['contact']));

    expect($html)->toContain('Weymouth, DT4 8DN')
        ->and($html)->toContain('Reading, RG1 2EQ')
        ->and($html)->not->toContain('ENG');
});
