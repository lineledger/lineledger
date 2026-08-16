<?php

use App\Enums\OrganizationType;
use App\Models\Company;
use App\Support\Reporting\StatementLabels;

test('non-profit organization types speak the net-asset vocabulary', function () {
    foreach ([OrganizationType::Club, OrganizationType::NonProfit, OrganizationType::Charity] as $type) {
        $labels = StatementLabels::forType($type);

        expect($labels->isNonProfit())->toBeTrue();
        expect($labels->equityHeading())->toBe('Net Assets');
        expect($labels->equityShort())->toBe('Net Assets');
        expect($labels->totalLiabilitiesAndEquity())->toBe('Total Liabilities & Net Assets');
        expect($labels->retainedEarnings())->toBe('Unrestricted Net Assets');
        expect($labels->netIncome())->toBe('Excess (deficiency) of revenue over expenses');
        expect($labels->netIncomeYtd())->toBe('Excess (deficiency) of revenue over expenses');
        expect($labels->grossProfit())->toBe('Gross surplus');
        expect($labels->profitBridge())->toBe('Surplus bridge');
    }
});

test('for-profit organization types keep entity-specific equity wording', function () {
    expect(StatementLabels::forType(OrganizationType::SoleProprietorship)->equityHeading())->toBe("Owner's Equity");
    expect(StatementLabels::forType(OrganizationType::Partnership)->equityHeading())->toBe("Partners' Equity");
    expect(StatementLabels::forType(OrganizationType::Corporation)->equityHeading())->toBe("Shareholders' Equity");

    $corp = StatementLabels::forType(OrganizationType::Corporation);
    expect($corp->isNonProfit())->toBeFalse();
    expect($corp->equityShort())->toBe('Equity');
    expect($corp->totalLiabilitiesAndEquity())->toBe('Total Liabilities & Equity');
    expect($corp->netIncome())->toBe('Net Income');
    expect($corp->retainedEarnings())->toBe('Retained Earnings');
});

test('a null organization type falls back to the generic for-profit wording', function () {
    $labels = StatementLabels::forType(null);

    expect($labels->isNonProfit())->toBeFalse();
    expect($labels->equityHeading())->toBe('Equity');
    expect($labels->totalLiabilitiesAndEquity())->toBe('Total Liabilities & Equity');
});

test('a combined group reads as non-profit only when every member is', function () {
    $charity = new Company(['organization_type' => OrganizationType::Charity]);
    $club = new Company(['organization_type' => OrganizationType::Club]);
    $corp = new Company(['organization_type' => OrganizationType::Corporation]);

    expect(StatementLabels::forGroup([$charity, $club])->equityShort())->toBe('Net Assets');
    expect(StatementLabels::forGroup([$charity, $corp])->equityShort())->toBe('Equity');
    expect(StatementLabels::forGroup([])->equityShort())->toBe('Equity');
});
