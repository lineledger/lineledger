<?php

use App\Enums\AccountSubtype;
use App\Enums\Country;
use App\Enums\Industry;
use App\Enums\OrganizationType;
use App\Models\Account;
use App\Support\Defaults\ChartTemplateBuilder;

function codes(array $rows): array
{
    return array_column($rows, 'code');
}

test('every jurisdiction x industry x org-type combination produces unique account codes', function () {
    $builder = new ChartTemplateBuilder;

    foreach (Country::cases() as $country) {
        foreach (Industry::cases() as $industry) {
            foreach (OrganizationType::cases() as $orgType) {
                foreach ([true, false] as $minimal) {
                    $rows = $builder->build($country, $industry, $orgType, $minimal);
                    $codes = codes($rows);

                    expect($codes)->toEqual(array_unique($codes),
                        "Duplicate codes for {$country->value}/{$industry->value}/{$orgType->value}/".($minimal ? 'minimal' : 'full'));
                }
            }
        }
    }
});

test('the minimal chart contains only the jurisdiction core and the posting-critical system accounts', function () {
    $rows = (new ChartTemplateBuilder)->build(Country::Canada, Industry::General, OrganizationType::Corporation, minimal: true);

    expect($rows)->toHaveCount(10);

    $subtypes = array_map(fn ($r) => $r['subtype'], $rows);

    foreach ([AccountSubtype::TaxPayable, AccountSubtype::Inventory, AccountSubtype::CostOfGoodsSold, AccountSubtype::AccountsReceivable, AccountSubtype::AccountsPayable] as $required) {
        expect($subtypes)->toContain($required);
    }
});

test('core accounts are locked and selected by default; industry accounts are unlocked', function () {
    $rows = collect((new ChartTemplateBuilder)->build(Country::Canada, Industry::General, OrganizationType::SoleProprietorship));

    $ar = $rows->firstWhere('code', '1100');
    expect($ar['locked'])->toBeTrue()->and($ar['is_system'])->toBeTrue();

    $travel = $rows->firstWhere('code', '6090');
    expect($travel['locked'])->toBeFalse()->and($travel['default_selected'])->toBeTrue();
});

test('jurisdiction core uses jurisdiction-specific naming', function () {
    $builder = new ChartTemplateBuilder;

    $ca = collect($builder->build(Country::Canada, Industry::General, OrganizationType::Other, minimal: true));
    expect($ca->firstWhere('code', '1000')['name'])->toBe('Chequing');
    expect($ca->firstWhere('code', '2200')['name'])->toBe('GST/HST Payable');

    $us = collect($builder->build(Country::UnitedStates, Industry::General, OrganizationType::Other, minimal: true));
    expect($us->firstWhere('code', '1000')['name'])->toBe('Checking');
    expect($us->firstWhere('code', '2200')['name'])->toBe('Sales Tax Payable');
});

test('non-profit org type relabels the core Retained Earnings line to Net Assets', function () {
    $rows = collect((new ChartTemplateBuilder)->build(Country::Canada, Industry::General, OrganizationType::NonProfit));

    expect($rows->firstWhere('code', '3900')['name'])->toBe('Net Assets');
});

test('non-profit org types relabel the opening-balance account to Opening Balance Net Assets', function () {
    foreach ([OrganizationType::NonProfit, OrganizationType::Charity, OrganizationType::Club] as $orgType) {
        $rows = collect((new ChartTemplateBuilder)->build(Country::Canada, Industry::General, $orgType));

        expect($rows->firstWhere('code', '3000')['name'])
            ->toBe(Account::OPENING_BALANCE_NET_ASSETS_NAME);
    }
});

test('for-profit org types keep the Opening Balance Equity name', function () {
    $rows = collect((new ChartTemplateBuilder)->build(Country::Canada, Industry::General, OrganizationType::Corporation));

    expect($rows->firstWhere('code', '3000')['name'])
        ->toBe(Account::OPENING_BALANCE_EQUITY_NAME);
});

test('a non-profit upgrades the net-asset equity subtypes and seeds deferred-contribution liabilities', function () {
    $rows = collect((new ChartTemplateBuilder)->build(Country::Canada, Industry::NonProfit, OrganizationType::NonProfit));

    expect($rows->firstWhere('code', '3100')['name'])->toBe('Unrestricted Net Assets');
    expect($rows->firstWhere('code', '3100')['subtype'])->toBe(AccountSubtype::UnrestrictedNetAssets);
    expect($rows->firstWhere('code', '3200')['subtype'])->toBe(AccountSubtype::RestrictedNetAssets);
    expect($rows->firstWhere('code', '2500')['subtype'])->toBe(AccountSubtype::CurrentLiability);
    expect($rows->firstWhere('code', '2510'))->not->toBeNull();

    // Retained Earnings keeps its subtype so the current-period excess still rolls forward.
    expect($rows->firstWhere('code', '3900')['subtype'])->toBe(AccountSubtype::RetainedEarnings);
});

test('only a charity gets an endowment net-asset account', function () {
    $charity = collect((new ChartTemplateBuilder)->build(Country::Canada, Industry::NonProfit, OrganizationType::Charity));
    expect($charity->firstWhere('code', '3300')['subtype'])->toBe(AccountSubtype::EndowmentNetAssets);

    $npo = collect((new ChartTemplateBuilder)->build(Country::Canada, Industry::NonProfit, OrganizationType::NonProfit));
    expect($npo->firstWhere('code', '3300'))->toBeNull();
});

test('the non-profit net-asset scaffolding is applied regardless of the chosen industry', function () {
    // A charity that picked a non-NPO industry (Retail) still gets net assets + deferred grants,
    // with the Retail "Owner Contributions/Draws" equity lines upgraded in place.
    $rows = collect((new ChartTemplateBuilder)->build(Country::Canada, Industry::Retail, OrganizationType::Charity));

    expect($rows->firstWhere('code', '3100')['subtype'])->toBe(AccountSubtype::UnrestrictedNetAssets);
    expect($rows->firstWhere('code', '3200')['subtype'])->toBe(AccountSubtype::RestrictedNetAssets);
    expect($rows->firstWhere('code', '3300')['subtype'])->toBe(AccountSubtype::EndowmentNetAssets);
    expect($rows->firstWhere('code', '2500'))->not->toBeNull();
    expect($rows->firstWhere('code', '2510'))->not->toBeNull();

    $codes = $rows->pluck('code')->all();
    expect($codes)->toEqual(array_unique($codes));
});

test('for-profit charts are untouched by the org-type net-asset pass', function () {
    $rows = collect((new ChartTemplateBuilder)->build(Country::Canada, Industry::Retail, OrganizationType::Corporation));

    expect($rows->firstWhere('code', '2510'))->toBeNull();
    expect($rows->firstWhere('code', '3100')['subtype'])->toBe(AccountSubtype::Equity);
});

test('a sole proprietorship names the equity section for a single owner', function () {
    $rows = collect((new ChartTemplateBuilder)->build(Country::UnitedStates, Industry::General, OrganizationType::SoleProprietorship));

    expect($rows->firstWhere('code', '3100')['name'])->toBe('Owner Contributions');
    expect($rows->firstWhere('code', '3200')['name'])->toBe('Owner Draws');
});

test('a partnership names the equity section for partners', function () {
    $rows = collect((new ChartTemplateBuilder)->build(Country::UnitedStates, Industry::General, OrganizationType::Partnership));

    expect($rows->firstWhere('code', '3100')['name'])->toBe('Partner Contributions');
    expect($rows->firstWhere('code', '3200')['name'])->toBe('Partner Draws');
});

test('a corporation gets share-capital equity with jurisdiction-specific terminology', function () {
    $us = collect((new ChartTemplateBuilder)->build(Country::UnitedStates, Industry::General, OrganizationType::Corporation));
    expect($us->firstWhere('code', '3100')['name'])->toBe('Common Stock');
    expect($us->firstWhere('code', '3200')['name'])->toBe('Shareholder Distributions');

    $ca = collect((new ChartTemplateBuilder)->build(Country::Canada, Industry::General, OrganizationType::Corporation));
    expect($ca->firstWhere('code', '3100')['name'])->toBe('Common Shares');
});

test('the org-type equity pass overrides an industry that guessed a different entity', function () {
    // Manufacturing hardcodes "Owner Draws / Dividends" — wrong for a sole proprietor.
    $rows = collect((new ChartTemplateBuilder)->build(Country::UnitedStates, Industry::Manufacturing, OrganizationType::SoleProprietorship));

    expect($rows->firstWhere('code', '3200')['name'])->toBe('Owner Draws');
    expect($rows->firstWhere('code', '3100')['name'])->toBe('Owner Contributions');
});

test('Other falls back to the proprietor equity model', function () {
    $rows = collect((new ChartTemplateBuilder)->build(Country::UnitedStates, Industry::General, OrganizationType::Other));

    expect($rows->firstWhere('code', '3100')['name'])->toBe('Owner Contributions');
    expect($rows->firstWhere('code', '3200')['name'])->toBe('Owner Draws');
});

test('the equity pass never injects accounts into a minimal chart', function () {
    // Rename-in-place only: minimal has no 3100/3200, so they must stay absent.
    $rows = collect((new ChartTemplateBuilder)->build(Country::UnitedStates, Industry::General, OrganizationType::Corporation, minimal: true));

    expect($rows->firstWhere('code', '3100'))->toBeNull();
    expect($rows->firstWhere('code', '3200'))->toBeNull();
});

test('a club gets a lighter member-dues chart without grants, restricted funds, or endowment', function () {
    $rows = collect((new ChartTemplateBuilder)->build(Country::Canada, Industry::General, OrganizationType::Club));

    expect($rows->firstWhere('code', '3900')['name'])->toBe('Net Assets');
    expect($rows->firstWhere('code', '3100')['subtype'])->toBe(AccountSubtype::UnrestrictedNetAssets);
    expect($rows->firstWhere('code', '2510')['name'])->toBe('Deferred Membership Dues');
    expect($rows->firstWhere('code', '4200')['name'])->toBe('Membership Dues');
    expect($rows->firstWhere('code', '4200')['subtype'])->toBe(AccountSubtype::Income);

    // None of the heavier non-profit/charity scaffolding.
    expect($rows->firstWhere('code', '2500'))->toBeNull();   // no deferred grants
    expect($rows->firstWhere('code', '3300'))->toBeNull();   // no endowment
    expect($rows->firstWhere('code', '3200')['subtype'])->not->toBe(AccountSubtype::RestrictedNetAssets);
});

test('full charts include an unlocked Bank Loan long-term liability at 2700', function () {
    $builder = new ChartTemplateBuilder;

    $ca = collect($builder->build(Country::Canada, Industry::General, OrganizationType::Corporation));
    $bankLoan = $ca->firstWhere('code', '2700');

    expect($bankLoan)->not->toBeNull();
    expect($bankLoan['name'])->toBe('Bank Loan');
    expect($bankLoan['subtype'])->toBe(AccountSubtype::LongTermLiability);
    expect($bankLoan['locked'])->toBeFalse();
    expect($bankLoan['is_system'])->toBeFalse();

    // Present regardless of jurisdiction or industry.
    $us = collect($builder->build(Country::UnitedStates, Industry::Retail, OrganizationType::SoleProprietorship));
    expect($us->firstWhere('code', '2700')['name'])->toBe('Bank Loan');
});

test('the minimal chart does not include the Bank Loan account', function () {
    $rows = collect((new ChartTemplateBuilder)->build(Country::Canada, Industry::General, OrganizationType::Corporation, minimal: true));

    expect($rows->firstWhere('code', '2700'))->toBeNull();
});

test('omitting the features map keeps the full feature-gated core (non-wizard callers)', function () {
    $rows = collect((new ChartTemplateBuilder)->build(Country::Canada, Industry::General, OrganizationType::SoleProprietorship));

    expect($rows->firstWhere('code', '1400'))->not->toBeNull(); // Inventory Asset
    expect($rows->firstWhere('code', '5000'))->not->toBeNull(); // Cost of Goods Sold
    expect($rows->firstWhere('code', '2200'))->not->toBeNull(); // Tax Payable
    expect($rows->firstWhere('code', '2300'))->not->toBeNull(); // Employee Reimbursements Payable
});

test('unchecking inventory drops the Inventory Asset and COGS core accounts', function () {
    $rows = collect((new ChartTemplateBuilder)->build(
        Country::Canada, Industry::General, OrganizationType::SoleProprietorship,
        features: ['inventory' => false, 'sales_tax' => true, 'employees' => true],
    ));

    expect($rows->firstWhere('code', '1400'))->toBeNull();
    expect($rows->firstWhere('code', '5000'))->toBeNull();

    // The rest of the core is untouched.
    expect($rows->firstWhere('code', '2200'))->not->toBeNull();
    expect($rows->firstWhere('code', '2300'))->not->toBeNull();
    expect($rows->firstWhere('code', '1100'))->not->toBeNull();
});

test('unchecking sales tax drops the tax-payable core account', function () {
    $rows = collect((new ChartTemplateBuilder)->build(
        Country::Canada, Industry::General, OrganizationType::SoleProprietorship,
        features: ['inventory' => true, 'sales_tax' => false, 'employees' => true],
    ));

    expect($rows->firstWhere('code', '2200'))->toBeNull();
    expect($rows->firstWhere('code', '1400'))->not->toBeNull();
});

test('disabling employees drops the employee-reimbursements core account', function () {
    $rows = collect((new ChartTemplateBuilder)->build(
        Country::Canada, Industry::General, OrganizationType::SoleProprietorship,
        features: ['inventory' => true, 'sales_tax' => true, 'employees' => false],
    ));

    expect($rows->firstWhere('code', '2300'))->toBeNull();
});

test('disabling fixed assets drops every fixed-asset and accumulated-depreciation account', function () {
    $rows = collect((new ChartTemplateBuilder)->build(
        Country::Canada, Industry::General, OrganizationType::SoleProprietorship,
        features: ['fixed_assets' => false],
    ));

    // 1500 Office Equipment + 1510 Accumulated Depreciation are gone...
    expect($rows->firstWhere('code', '1500'))->toBeNull();
    expect($rows->firstWhere('code', '1510'))->toBeNull();
    // ...and no FixedAsset-subtype row of any kind remains.
    expect($rows->where('subtype', AccountSubtype::FixedAsset))->toBeEmpty();

    // Non-asset accounts are untouched.
    expect($rows->firstWhere('code', '1000'))->not->toBeNull(); // bank
    expect($rows->firstWhere('code', '4000'))->not->toBeNull(); // income
});

test('industry fixed-asset accounts are dropped too when fixed assets are off', function () {
    // The Contractor set carries several fixed-asset lines (equipment, vehicles).
    $rows = collect((new ChartTemplateBuilder)->build(
        Country::Canada, Industry::Contractor, OrganizationType::SoleProprietorship,
        features: ['fixed_assets' => false],
    ));

    expect($rows->where('subtype', AccountSubtype::FixedAsset))->toBeEmpty();
    expect($rows->firstWhere('code', '1600'))->toBeNull(); // Construction Equipment
    expect($rows->firstWhere('code', '1700'))->toBeNull(); // Vehicles
});

test('fixed assets are kept by default and when explicitly enabled', function () {
    $default = collect((new ChartTemplateBuilder)->build(
        Country::Canada, Industry::General, OrganizationType::SoleProprietorship,
    ));
    $enabled = collect((new ChartTemplateBuilder)->build(
        Country::Canada, Industry::General, OrganizationType::SoleProprietorship,
        features: ['fixed_assets' => true],
    ));

    expect($default->firstWhere('code', '1500'))->not->toBeNull();
    expect($enabled->firstWhere('code', '1500'))->not->toBeNull();
});

test('a PST province gets a provincial sales-tax payable account only when charging it', function () {
    $charging = collect((new ChartTemplateBuilder)->build(
        Country::Canada, Industry::General, OrganizationType::SoleProprietorship,
        features: ['pst' => true], region: 'BC',
    ));
    expect($charging->firstWhere('code', '2210'))->not->toBeNull();
    expect($charging->firstWhere('code', '2210')['name'])->toBe('PST Payable');
    expect($charging->firstWhere('code', '2210')['locked'])->toBeTrue();

    // Manitoba labels it RST.
    $manitoba = collect((new ChartTemplateBuilder)->build(
        Country::Canada, Industry::General, OrganizationType::SoleProprietorship,
        features: ['pst' => true], region: 'MB',
    ));
    expect($manitoba->firstWhere('code', '2210')['name'])->toBe('RST Payable');

    // Quebec labels it QST.
    $quebec = collect((new ChartTemplateBuilder)->build(
        Country::Canada, Industry::General, OrganizationType::SoleProprietorship,
        features: ['pst' => true], region: 'QC',
    ));
    expect($quebec->firstWhere('code', '2210')['name'])->toBe('QST Payable');

    // Not charging it (or no pst gate) leaves the account out.
    $notCharging = collect((new ChartTemplateBuilder)->build(
        Country::Canada, Industry::General, OrganizationType::SoleProprietorship,
        features: ['pst' => false], region: 'BC',
    ));
    expect($notCharging->firstWhere('code', '2210'))->toBeNull();
});

test('an HST or GST-only province never gets the provincial sales-tax account', function () {
    foreach (['ON', 'AB', 'NS'] as $region) {
        $rows = collect((new ChartTemplateBuilder)->build(
            Country::Canada, Industry::General, OrganizationType::SoleProprietorship,
            features: ['pst' => true], region: $region,
        ));
        expect($rows->firstWhere('code', '2210'))->toBeNull("region {$region}");
    }
});

test('feature gating drops accounts on the minimal (import) chart too', function () {
    $rows = collect((new ChartTemplateBuilder)->build(
        Country::Canada, Industry::General, OrganizationType::Corporation, minimal: true,
        features: ['inventory' => false, 'sales_tax' => false, 'employees' => false],
    ));

    expect($rows->firstWhere('code', '1400'))->toBeNull();
    expect($rows->firstWhere('code', '5000'))->toBeNull();
    expect($rows->firstWhere('code', '2200'))->toBeNull();
    expect($rows->firstWhere('code', '2300'))->toBeNull();

    // Required, non-gated core survives.
    expect($rows->firstWhere('code', '1000'))->not->toBeNull(); // bank
    expect($rows->firstWhere('code', '1100'))->not->toBeNull(); // AR
    expect($rows->firstWhere('code', '3000'))->not->toBeNull(); // Opening Balance Equity
});

test('toSeedRows keeps only selected codes and strips preview-only keys', function () {
    $preview = (new ChartTemplateBuilder)->build(Country::Canada, Industry::General, OrganizationType::SoleProprietorship);

    $seed = ChartTemplateBuilder::toSeedRows($preview, ['1100', '6090']);

    expect($seed)->toHaveCount(2);
    expect(array_column($seed, 'code'))->toEqualCanonicalizing(['1100', '6090']);
    expect($seed[0])->not->toHaveKey('locked');
    expect($seed[0])->not->toHaveKey('default_selected');
});
