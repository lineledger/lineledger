<?php

use App\Enums\Country;
use App\Models\Company;
use App\Support\Tax\TaxAuthorityCatalog;

it('lists CRA and the provincial sales-tax authorities for a Canadian company', function () {
    $company = Company::factory()->create(['address_country' => Country::Canada->value]);

    $names = array_column(TaxAuthorityCatalog::forCompany($company), 'name');

    expect($names)
        ->toContain('Canada Revenue Agency')
        ->toContain('Revenu Québec')
        ->toContain('BC Ministry of Finance')
        ->toContain('Saskatchewan Ministry of Finance')
        ->toContain('Manitoba Finance');
});

it('suggests a key and payable-account name for each Canadian entry', function () {
    $company = Company::factory()->create(['address_country' => Country::Canada->value]);

    $cra = collect(TaxAuthorityCatalog::forCompany($company))->firstWhere('name', 'Canada Revenue Agency');

    expect($cra)->not->toBeNull()
        ->and($cra['key'])->toBe('CRA')
        ->and($cra['account_name'])->toBe('GST/HST Payable');
});

it('lists every state revenue department for a US company', function () {
    $company = Company::factory()->create([
        'address_country' => Country::UnitedStates->value,
        'currency_code' => 'USD',
    ]);

    $entries = TaxAuthorityCatalog::forCompany($company);
    $names = array_column($entries, 'name');

    expect($entries)->toHaveCount(count(Country::UnitedStates->regions()))
        ->and($names)->toContain('California Department of Revenue')
        ->and($names)->toContain('New York Department of Revenue');

    $california = collect($entries)->firstWhere('key', 'US-CA');
    expect($california['account_name'])->toBe('California Sales Tax Payable');
});

it('lists HMRC for a UK company', function () {
    $company = Company::factory()->create([
        'address_country' => Country::UnitedKingdom->value,
        'currency_code' => 'GBP',
    ]);

    $entries = TaxAuthorityCatalog::forCompany($company);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['key'])->toBe('HMRC')
        ->and($entries[0]['name'])->toBe('HM Revenue & Customs')
        ->and($entries[0]['account_name'])->toBe('VAT Payable');
});
