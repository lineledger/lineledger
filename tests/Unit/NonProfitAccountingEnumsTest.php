<?php

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\ContributionMethod;
use App\Enums\LegalStructure;
use App\Enums\NormalBalance;
use App\Enums\OrganizationType;
use App\Support\Gifi\GifiCatalog;

test('legal structure flags only the registered charity for CRA registration and T3010', function () {
    expect(LegalStructure::RegisteredCharity->requiresCharityRegistration())->toBeTrue();
    expect(LegalStructure::RegisteredCharity->surfacesT3010())->toBeTrue();

    foreach ([LegalStructure::UnincorporatedAssociation, LegalStructure::NonProfitCorporation] as $tier) {
        expect($tier->requiresCharityRegistration())->toBeFalse();
        expect($tier->surfacesT3010())->toBeFalse();
    }
});

test('legal structure maps sensibly from organization type', function () {
    expect(LegalStructure::fromOrganizationType(OrganizationType::Charity))->toBe(LegalStructure::RegisteredCharity);
    expect(LegalStructure::fromOrganizationType(OrganizationType::NonProfit))->toBe(LegalStructure::NonProfitCorporation);
    expect(LegalStructure::fromOrganizationType(OrganizationType::Club))->toBe(LegalStructure::UnincorporatedAssociation);
    expect(LegalStructure::fromOrganizationType(OrganizationType::Corporation))->toBeNull();
});

test('a club is the lightest non-profit tier', function () {
    expect(OrganizationType::Club->isNonProfit())->toBeTrue();
    expect(OrganizationType::Club->isClub())->toBeTrue();
    expect(OrganizationType::NonProfit->isClub())->toBeFalse();
    expect(OrganizationType::Charity->isClub())->toBeFalse();
    expect(OrganizationType::Club->label())->toBe('Club / Association');
});

test('the equity section is relabelled to match the entity type', function () {
    expect(OrganizationType::SoleProprietorship->equitySectionLabel())->toBe("Owner's Equity");
    expect(OrganizationType::Partnership->equitySectionLabel())->toBe("Partners' Equity");
    expect(OrganizationType::Corporation->equitySectionLabel())->toBe("Shareholders' Equity");
    expect(OrganizationType::Club->equitySectionLabel())->toBe('Net Assets');
    expect(OrganizationType::NonProfit->equitySectionLabel())->toBe('Net Assets');
    expect(OrganizationType::Charity->equitySectionLabel())->toBe('Net Assets');

    // "Other" keeps the generic heading.
    expect(OrganizationType::Other->equitySectionLabel())->toBe('Equity');
});

test('contribution method defaults to deferral and exposes both methods', function () {
    expect(ContributionMethod::default())->toBe(ContributionMethod::Deferral);
    expect(ContributionMethod::options())->toHaveCount(2);
});

test('net-asset subtypes are credit-normal equity', function () {
    foreach ([
        AccountSubtype::UnrestrictedNetAssets,
        AccountSubtype::RestrictedNetAssets,
        AccountSubtype::EndowmentNetAssets,
    ] as $subtype) {
        expect($subtype->type())->toBe(AccountType::Equity);
        expect($subtype->type()->normalBalance())->toBe(NormalBalance::Credit);
    }

    expect(AccountSubtype::UnrestrictedNetAssets->label())->toBe('Unrestricted Net Assets');
});

test('every net-asset subtype has a GIFI default so the GIFI report still classifies it', function () {
    foreach ([
        AccountSubtype::UnrestrictedNetAssets,
        AccountSubtype::RestrictedNetAssets,
        AccountSubtype::EndowmentNetAssets,
    ] as $subtype) {
        expect(GifiCatalog::defaultForSubtype($subtype))->not->toBeNull();
    }
});
