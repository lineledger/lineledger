<?php

use App\Enums\LegalStructure;
use App\Enums\OrganizationType;
use App\Enums\TaxForm;
use App\Models\Company;
use App\Support\Tax\FilingProfile;

function profileFor(OrganizationType $org, ?LegalStructure $tier = null, string $country = 'CA'): FilingProfile
{
    $company = Company::factory()->make([
        'address_country' => $country,
        'organization_type' => $org->value,
        'legal_structure' => $tier?->value,
    ]);

    return FilingProfile::for($company);
}

it('routes a for-profit corporation to the T2 / GIFI statement', function () {
    $p = profileFor(OrganizationType::Corporation);

    expect($p->filesGifiStatement())->toBeTrue()
        ->and($p->mapsGifiCodes())->toBeTrue()
        ->and($p->primaryForm())->toBe(TaxForm::T2)
        ->and($p->filesT5013())->toBeFalse()
        ->and($p->filesT2125())->toBeFalse();
});

it('routes a non-profit corporation to the T2 plus the T1044 info return', function () {
    $p = profileFor(OrganizationType::NonProfit, LegalStructure::NonProfitCorporation);

    expect($p->filesGifiStatement())->toBeTrue()
        ->and($p->filesT1044())->toBeTrue()
        ->and($p->primaryForm())->toBe(TaxForm::T2)
        ->and(collect($p->forms())->pluck('form'))->toContain(TaxForm::T1044);
});

it('does not apply GIFI to an unincorporated association', function () {
    $p = profileFor(OrganizationType::NonProfit, LegalStructure::UnincorporatedAssociation);

    expect($p->filesGifiStatement())->toBeFalse()
        ->and($p->mapsGifiCodes())->toBeFalse()
        ->and($p->primaryForm())->toBeNull()
        ->and($p->filesT1044())->toBeTrue();
});

it('treats a club as an unincorporated association with no GIFI', function () {
    $p = profileFor(OrganizationType::Club);

    expect($p->filesGifiStatement())->toBeFalse()
        ->and($p->mapsGifiCodes())->toBeFalse()
        ->and($p->filesT1044())->toBeTrue();
});

it('routes a registered charity to the T3010 and not GIFI', function () {
    $p = profileFor(OrganizationType::Charity, LegalStructure::RegisteredCharity);

    expect($p->filesGifiStatement())->toBeFalse()
        ->and($p->filesT3010())->toBeTrue()
        ->and($p->filesT1044())->toBeFalse()
        ->and($p->mapsGifiCodes())->toBeFalse()
        ->and($p->primaryForm())->toBe(TaxForm::T3010);
});

it('routes a partnership to the T5013', function () {
    $p = profileFor(OrganizationType::Partnership);

    expect($p->filesT5013())->toBeTrue()
        ->and($p->filesGifiStatement())->toBeFalse()
        ->and($p->mapsGifiCodes())->toBeTrue()
        ->and($p->primaryForm())->toBe(TaxForm::T5013);
});

it('routes a sole proprietor to the T2125', function () {
    $p = profileFor(OrganizationType::SoleProprietorship);

    expect($p->filesT2125())->toBeTrue()
        ->and($p->filesGifiStatement())->toBeFalse()
        ->and($p->mapsGifiCodes())->toBeTrue()
        ->and($p->primaryForm())->toBe(TaxForm::T2125);
});

it('applies no CRA forms to a non-Canadian company', function () {
    $p = profileFor(OrganizationType::Corporation, country: 'US');

    expect($p->filesGifiStatement())->toBeFalse()
        ->and($p->filesT5013())->toBeFalse()
        ->and($p->filesT2125())->toBeFalse()
        ->and($p->mapsGifiCodes())->toBeFalse()
        ->and($p->primaryForm())->toBeNull()
        ->and($p->forms())->toBe([]);
});
