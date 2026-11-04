<?php

use App\Enums\Activity;
use App\Enums\PermissionAction;
use App\Enums\Section;
use App\Support\Access\Grant;
use App\Support\Access\PermissionSet;

/**
 * The QuickBooks-style grant rules: None / Full / Partial per activity, any
 * action implies View, an activity offers only the actions that make sense,
 * and several roles combine to the most permissive.
 */
it('lets the Owner do anything the activity offers', function () {
    $owner = PermissionSet::owner();

    expect($owner->allows(Activity::Invoices, PermissionAction::Delete))->toBeTrue()
        ->and($owner->canAccessArea(Section::Settings))->toBeTrue()
        // An action an activity doesn't offer is refused even to the Owner.
        ->and($owner->allows(Activity::TaxCodes, PermissionAction::Print))->toBeFalse();
});

it('allows nothing to a non-member', function () {
    expect(PermissionSet::none()->allows(Activity::Invoices, PermissionAction::View))->toBeFalse()
        ->and(PermissionSet::none()->canAccessArea(Section::Customers))->toBeFalse();
});

it('gives Full every action the activity offers', function () {
    $set = PermissionSet::fromGrants([Activity::Accounts->value => Grant::full()]);

    foreach (Activity::Accounts->actions() as $action) {
        expect($set->allows(Activity::Accounts, $action))->toBeTrue();
    }

    expect($set->allows(Activity::Journal, PermissionAction::View))->toBeFalse();
});

it('makes any Partial action imply View', function () {
    $set = PermissionSet::fromGrants([Activity::Invoices->value => Grant::partial(PermissionAction::Create)]);

    expect($set->allows(Activity::Invoices, PermissionAction::View))->toBeTrue()
        ->and($set->allows(Activity::Invoices, PermissionAction::Create))->toBeTrue()
        ->and($set->allows(Activity::Invoices, PermissionAction::Modify))->toBeFalse()
        ->and($set->allows(Activity::Invoices, PermissionAction::Delete))->toBeFalse();
});

it('treats an empty Partial as None', function () {
    expect(Grant::partial()->isNone())->toBeTrue()
        ->and(PermissionSet::fromGrants([Activity::Invoices->value => Grant::partial()])->canAccessArea(Section::Customers))->toBeFalse();
});

it('combines several roles to the most permissive', function () {
    $sales = PermissionSet::fromGrants([
        Activity::Invoices->value => Grant::partial(PermissionAction::View, PermissionAction::Create),
        Activity::Customers->value => Grant::partial(PermissionAction::View),
    ]);
    $collections = PermissionSet::fromGrants([
        Activity::Invoices->value => Grant::partial(PermissionAction::Print),
        Activity::Customers->value => Grant::full(),
    ]);

    $both = PermissionSet::union($sales, $collections);

    expect($both->allows(Activity::Invoices, PermissionAction::Create))->toBeTrue()
        ->and($both->allows(Activity::Invoices, PermissionAction::Print))->toBeTrue()
        ->and($both->allows(Activity::Invoices, PermissionAction::Delete))->toBeFalse()
        ->and($both->allows(Activity::Customers, PermissionAction::Delete))->toBeTrue()
        ->and(PermissionSet::union($sales, PermissionSet::owner())->isOwner())->toBeTrue();
});

it('sees balances only where granted and the activity shows them', function () {
    $set = PermissionSet::fromGrants([
        Activity::Banking->value => Grant::partial(PermissionAction::View),
        Activity::Accounts->value => Grant::partial(PermissionAction::View, PermissionAction::ViewBalance),
    ]);

    expect($set->canViewBalances(Activity::Banking))->toBeFalse()
        ->and($set->canViewBalances(Activity::Accounts))->toBeTrue()
        ->and(Activity::Invoices->supports(PermissionAction::ViewBalance))->toBeFalse();
});

it('shows payroll detail only to members who can open pay runs', function () {
    expect(PermissionSet::fromGrants([Activity::Journal->value => Grant::full()])->seesPayrollDetail())->toBeFalse()
        ->and(PermissionSet::fromGrants([Activity::PayRuns->value => Grant::partial(PermissionAction::View)])->seesPayrollDetail())->toBeTrue();
});

it('requires two-factor of members who run the organization, not those who only view settings', function () {
    expect(PermissionSet::fromGrants([Activity::ApiKeys->value => Grant::partial(PermissionAction::View)])->requiresTwoFactor())->toBeFalse()
        ->and(PermissionSet::fromGrants([Activity::ApiKeys->value => Grant::partial(PermissionAction::Create)])->requiresTwoFactor())->toBeTrue()
        ->and(PermissionSet::fromGrants([Activity::Roles->value => Grant::full()])->requiresTwoFactor())->toBeTrue()
        ->and(PermissionSet::fromGrants([Activity::Invoices->value => Grant::full()])->requiresTwoFactor())->toBeFalse();
});

it('places every activity in an area with at least one action', function (Activity $activity) {
    expect($activity->actions())->not->toBeEmpty()
        ->and(Activity::forArea($activity->area()))->toContain($activity);
})->with(Activity::cases());
