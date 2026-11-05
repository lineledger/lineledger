<?php

use App\Enums\Activity;
use App\Enums\CompanyRole;
use App\Enums\PredefinedRole;
use App\Models\Company;
use App\Models\Role;
use App\Services\Access\PredefinedRoleSeeder;
use App\Support\Access\LegacyRoleGrants;
use App\Support\Access\PermissionSet;
use App\Support\Translation\RoleCopy;

/**
 * Every company starts with the QuickBooks-style predefined roles. Full Access
 * and Accountant are exactly today's Admin and Accountant; no predefined role
 * grants an Owner-only (reserved) activity.
 */
it('gives a new company every predefined role, once', function () {
    $company = Company::factory()->create();

    $roles = Role::query()->withoutGlobalScopes()->where('company_id', $company->id)->get();

    expect($roles->pluck('system_key')->all())->toEqualCanonicalizing(PredefinedRole::cases())
        ->and($roles->every(fn (Role $role) => $role->followsCatalogue()))->toBeTrue()
        ->and(app(PredefinedRoleSeeder::class)->seed($company))->toBe(0);
});

it('makes Full Access and Accountant exactly the original Admin and Accountant', function () {
    expect(PermissionSet::fromGrants(PredefinedRole::FullAccess->grants())->grants())
        ->toEqual(LegacyRoleGrants::for(CompanyRole::Admin)->grants())
        ->and(PermissionSet::fromGrants(PredefinedRole::Accountant->grants())->grants())
        ->toEqual(LegacyRoleGrants::for(CompanyRole::Accountant)->grants());
});

it('never grants an Owner-only activity or an action an activity lacks', function (PredefinedRole $role) {
    foreach ($role->grants() as $activity => $grant) {
        $activity = Activity::from($activity);

        expect($activity->isReserved())->toBeFalse("{$role->value} grants reserved {$activity->value}")
            ->and($grant->effectiveMask($activity))->toBeGreaterThan(0);
    }
})->with(PredefinedRole::cases());

it('keeps only Full Access and Accountant able to see payroll detail among the general roles', function () {
    $sees = array_values(array_map(
        fn (PredefinedRole $role) => $role->value,
        array_filter(PredefinedRole::cases(), fn (PredefinedRole $role) => PermissionSet::fromGrants($role->grants())->seesPayrollDetail()),
    ));

    expect($sees)->toEqualCanonicalizing(['full_access', 'accountant', 'external_accountant', 'payroll_manager', 'payroll_processor']);
});

it('registers every predefined name and description for translation', function () {
    expect(RoleCopy::names())->toBe(array_map(fn (PredefinedRole $r) => $r->defaultName(), PredefinedRole::cases()))
        ->and(RoleCopy::descriptions())->toBe(array_map(fn (PredefinedRole $r) => $r->defaultDescription(), PredefinedRole::cases()));
});

it('shows a predefined role\'s name translated, and a renamed one as typed', function () {
    $company = Company::factory()->create();
    $role = Role::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('system_key', 'sales')->first();

    app()->setLocale('fr');
    expect($role->displayName())->toBe(__('Sales'));

    $role->name = 'Ventes et devis';
    expect($role->displayName())->toBe('Ventes et devis');
    app()->setLocale('en');
});
