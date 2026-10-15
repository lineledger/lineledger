<?php

use App\Enums\Country;
use App\Enums\OrganizationType;

test('canada exposes Canadian-flavoured terminology', function () {
    $c = Country::Canada;

    expect($c->label())->toBe('Canada');
    expect($c->defaultCurrencyCode())->toBe('CAD');
    expect($c->regionLabel())->toBe('Province');
    expect($c->postalCodeLabel())->toBe('Postal Code');
    expect($c->taxLabel())->toBe('GST/HST');
    expect($c->cheque('singular'))->toBe('Cheque');
    expect($c->cheque('plural'))->toBe('Cheques');
    expect($c->chequeLabel('number'))->toBe('Cheque #');
    expect($c->chequeLabel('checkbook'))->toBe('Chequebook view');
});

test('united states exposes US-flavoured terminology', function () {
    $c = Country::UnitedStates;

    expect($c->label())->toBe('United States');
    expect($c->defaultCurrencyCode())->toBe('USD');
    expect($c->regionLabel())->toBe('State');
    expect($c->postalCodeLabel())->toBe('ZIP Code');
    expect($c->taxLabel())->toBe('Sales Tax');
    expect($c->cheque('singular'))->toBe('Check');
    expect($c->cheque('plural'))->toBe('Checks');
    expect($c->chequeLabel('number'))->toBe('Check #');
    expect($c->chequeLabel('checkbook'))->toBe('Checkbook view');
});

test('united kingdom exposes British-flavoured terminology', function () {
    $c = Country::UnitedKingdom;

    expect($c->label())->toBe('United Kingdom');
    expect($c->defaultCurrencyCode())->toBe('GBP');
    expect($c->defaultTimezone())->toBe('Europe/London');
    expect($c->regionLabel())->toBe('Nation');
    expect($c->postalCodeLabel())->toBe('Postcode');
    expect($c->taxLabel())->toBe('VAT');
    expect($c->taxNumberDocumentLabel())->toBe('VAT No.');
    expect($c->companyRegistrationNumberLabel())->toBe('Company number');
    expect($c->usesCompanyRegistrationNumber(OrganizationType::Corporation))->toBeTrue();
    expect($c->usesCompanyRegistrationNumber(OrganizationType::Partnership))->toBeTrue();
    expect($c->usesCompanyRegistrationNumber(OrganizationType::SoleProprietorship))->toBeFalse();
    expect($c->cheque('singular'))->toBe('Cheque');
    expect($c->cheque('plural'))->toBe('Cheques');
    expect($c->chequeLabel('number'))->toBe('Cheque #');
    expect($c->chequeLabel('checkbook'))->toBe('Chequebook view');
});

test('regions include BC for Canada, WA for the United States, and ENG for the United Kingdom', function () {
    expect(Country::Canada->regions())->toHaveKey('BC');
    expect(Country::Canada->regions()['BC'])->toBe('British Columbia');

    expect(Country::UnitedStates->regions())->toHaveKey('WA');
    expect(Country::UnitedStates->regions()['WA'])->toBe('Washington');

    expect(Country::UnitedKingdom->regions())->toHaveKey('ENG');
    expect(Country::UnitedKingdom->regions()['ENG'])->toBe('England');
    expect(Country::UnitedKingdom->regions())->toHaveKey('SCT');
    expect(Country::UnitedKingdom->regions())->toHaveKey('WLS');
    expect(Country::UnitedKingdom->regions())->toHaveKey('NIR');
    expect(Country::UnitedKingdom->regions())->toHaveKey('IM');
    expect(Country::UnitedKingdom->regions()['IM'])->toBe('Isle of Man');
});

test('options exposes each case as a label/value pair', function () {
    $options = Country::options();

    expect($options)->toHaveCount(3);
    expect($options[0])->toBe(['value' => 'CA', 'label' => 'Canada']);
    expect($options[1])->toBe(['value' => 'US', 'label' => 'United States']);
    expect($options[2])->toBe(['value' => 'GB', 'label' => 'United Kingdom']);
});

test('each case exposes its flag emoji', function () {
    expect(Country::Canada->flag())->toBe('🇨🇦');
    expect(Country::UnitedStates->flag())->toBe('🇺🇸');
    expect(Country::UnitedKingdom->flag())->toBe('🇬🇧');
});

test('fromHost maps national TLDs to their countries and everything else to the United States', function () {
    expect(Country::fromHost('books.lineledger.ca'))->toBe(Country::Canada);
    expect(Country::fromHost('lineledger.ca'))->toBe(Country::Canada);
    expect(Country::fromHost('BOOKS.LINELEDGER.CA'))->toBe(Country::Canada);

    expect(Country::fromHost('books.lineledger.co.uk'))->toBe(Country::UnitedKingdom);
    expect(Country::fromHost('lineledger.co.uk'))->toBe(Country::UnitedKingdom);
    expect(Country::fromHost('books.lineledger.uk'))->toBe(Country::UnitedKingdom);

    expect(Country::fromHost('books.lineledger.com'))->toBe(Country::UnitedStates);
    expect(Country::fromHost('localhost'))->toBe(Country::UnitedStates);
    expect(Country::fromHost(''))->toBe(Country::UnitedStates);
    expect(Country::fromHost(null))->toBe(Country::UnitedStates);
});

test('regionForTimezone pins a province from a region-specific Canadian zone', function () {
    expect(Country::Canada->regionForTimezone('America/Vancouver'))->toBe('BC');
    expect(Country::Canada->regionForTimezone('America/Edmonton'))->toBe('AB');
    expect(Country::Canada->regionForTimezone('America/Winnipeg'))->toBe('MB');
    expect(Country::Canada->regionForTimezone('America/Regina'))->toBe('SK');
    expect(Country::Canada->regionForTimezone('America/Toronto'))->toBe('ON');
    expect(Country::Canada->regionForTimezone('America/Montreal'))->toBe('QC');
    expect(Country::Canada->regionForTimezone('America/Halifax'))->toBe('NS');
    expect(Country::Canada->regionForTimezone('America/Moncton'))->toBe('NB');
    expect(Country::Canada->regionForTimezone('America/St_Johns'))->toBe('NL');
    expect(Country::Canada->regionForTimezone('America/Yellowknife'))->toBe('NT');
    expect(Country::Canada->regionForTimezone('America/Iqaluit'))->toBe('NU');
    expect(Country::Canada->regionForTimezone('America/Whitehorse'))->toBe('YT');
});

test('regionForTimezone pins a state from an unambiguous US zone', function () {
    expect(Country::UnitedStates->regionForTimezone('America/Phoenix'))->toBe('AZ');
    expect(Country::UnitedStates->regionForTimezone('Pacific/Honolulu'))->toBe('HI');
    expect(Country::UnitedStates->regionForTimezone('America/Anchorage'))->toBe('AK');
    expect(Country::UnitedStates->regionForTimezone('America/Detroit'))->toBe('MI');
    expect(Country::UnitedStates->regionForTimezone('America/Boise'))->toBe('ID');
});

test('regionForTimezone returns null for a zone that spans several regions', function () {
    // Eastern/Central/Mountain/Pacific cover too many jurisdictions to guess —
    // a wrong tax region is worse than leaving the field for the owner.
    expect(Country::Canada->regionForTimezone('America/New_York'))->toBeNull();
    expect(Country::Canada->regionForTimezone('America/Chicago'))->toBeNull();
    expect(Country::UnitedStates->regionForTimezone('America/Los_Angeles'))->toBeNull();
    expect(Country::UnitedStates->regionForTimezone('America/New_York'))->toBeNull();
    // Europe/London covers all four UK nations.
    expect(Country::UnitedKingdom->regionForTimezone('Europe/London'))->toBeNull();
});

test('regionForTimezone returns null for unknown zones or zones from another country', function () {
    expect(Country::Canada->regionForTimezone('Mars/Phobos'))->toBeNull();
    expect(Country::Canada->regionForTimezone(''))->toBeNull();
    // A US-only zone is not one of Canada's regions, and vice versa.
    expect(Country::UnitedStates->regionForTimezone('America/Vancouver'))->toBeNull();
    expect(Country::Canada->regionForTimezone('America/Phoenix'))->toBeNull();
    expect(Country::UnitedKingdom->regionForTimezone('America/Toronto'))->toBeNull();
});
