<?php

use App\Actions\Companies\CreateCompany;
use App\Enums\Country;
use App\Enums\OrganizationType;
use App\Models\NavPreference;
use App\Models\User;
use App\Support\Navigation\SidebarNavCatalog;

$hiddenKeys = ['sales.customers', 'sales.sales_receipts', 'sales.credit_memos', 'purchases.purchase_orders', 'purchases.vendor_credits'];

test('a new non-profit hides the sales-oriented nav items for the owner', function () use ($hiddenKeys) {
    $user = User::factory()->create();

    $company = app(CreateCompany::class)->handle(
        user: $user,
        name: 'Helping Hands Society',
        country: Country::Canada,
        attributes: ['organization_type' => OrganizationType::NonProfit->value],
    );

    $stored = NavPreference::withoutGlobalScopes()
        ->where('company_id', $company->id)
        ->where('user_id', $user->id)
        ->pluck('item_key')
        ->all();

    expect($stored)->toEqualCanonicalizing($hiddenKeys);
});

test('a charity and a club also get the hidden defaults', function () use ($hiddenKeys) {
    foreach ([OrganizationType::Charity, OrganizationType::Club] as $type) {
        $user = User::factory()->create();

        $company = app(CreateCompany::class)->handle(
            user: $user,
            name: 'NP '.$type->value,
            country: Country::Canada,
            attributes: ['organization_type' => $type->value],
        );

        $count = NavPreference::withoutGlobalScopes()->where('company_id', $company->id)->count();

        expect($count)->toBe(count($hiddenKeys));
    }
});

test('a for-profit company gets no hidden nav defaults', function () {
    $user = User::factory()->create();

    $company = app(CreateCompany::class)->handle(
        user: $user,
        name: 'Acme Corp',
        country: Country::Canada,
        attributes: ['organization_type' => OrganizationType::Corporation->value],
    );

    $count = NavPreference::withoutGlobalScopes()->where('company_id', $company->id)->count();

    expect($count)->toBe(0);
});

test('the Sales group is labelled Revenues for non-profits', function () {
    $user = User::factory()->create();

    $company = app(CreateCompany::class)->handle(
        user: $user,
        name: 'Green Earth NPO',
        country: Country::Canada,
        attributes: ['organization_type' => OrganizationType::NonProfit->value],
    );

    $groups = SidebarNavCatalog::forUser($company->fresh(), $user);
    $salesGroup = collect($groups)->firstWhere('key', 'customers');

    expect($salesGroup['label'])->toBe('Revenues');
});

test('the Sales group keeps the Sales label for for-profits', function () {
    $user = User::factory()->create();

    $company = app(CreateCompany::class)->handle(
        user: $user,
        name: 'Acme Corp',
        country: Country::Canada,
        attributes: ['organization_type' => OrganizationType::Corporation->value],
    );

    $groups = SidebarNavCatalog::forUser($company->fresh(), $user);
    $salesGroup = collect($groups)->firstWhere('key', 'customers');

    expect($salesGroup['label'])->toBe('Sales');
});
