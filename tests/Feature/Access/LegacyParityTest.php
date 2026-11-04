<?php

use App\Enums\CompanyRole;
use App\Enums\Section;
use App\Http\Middleware\EnsureCompanyMembership;
use App\Support\Access\LegacyRoleGrants;
use App\Support\Access\RoutePermissionMap;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * Moving access from section toggles to activity permissions must not change
 * what anyone can open. This pins the new path (RoutePermissionMap + the
 * legacy grants each original role converts to) against a frozen copy of the
 * old one (Section::forRouteName's prefix table + CompanyRole::sections()),
 * for every route and every shape of membership.
 */

/** The section table exactly as it stood before activities (frozen). */
$oldSectionsFor = function (?string $name): array {
    if ($name === null || $name === '') {
        return [];
    }

    return match (explode('.', $name)[0]) {
        'customers', 'estimates', 'sales-orders', 'invoices', 'invoice-templates', 'credit-memos', 'receipts', 'sales-receipts', 'members', 'reminders' => [Section::Customers],
        'vendors', 'bills', 'bill-payments', 'purchase-orders', 'vendor-credits', 'expenses' => [Section::Vendors],
        'donations', 'donation-receipts', 'grants' => [Section::Fundraising],
        'recurring' => [Section::Customers, Section::Vendors],
        'employees', 'reimbursements' => [Section::Employees],
        'payroll', 'pay-runs', 'payroll-schedules', 'payroll-cheques', 'time-entries', 'time-off-policies', 'time-off-requests' => [Section::Payroll],
        'inventory' => [Section::Inventory],
        'accounts', 'journal', 'journal-entry-templates', 'recurring-journal', 'assets', 'opening-balances' => [Section::Accounting],
        'banking', 'cheques', 'deposits', 'transfers' => [Section::Banking],
        'inbox' => [Section::Inbox],
        'reports', 'tax-returns', 'budgets' => [Section::Reports],
        'insights' => [],
        'lists' => [Section::Lists],
        'documents' => [Section::Documents],
        'settings', 'migration' => [Section::Settings],
        default => [],
    };
};

/** The sections a membership reached before activities (frozen). */
$oldReach = fn (CompanyRole $role, ?array $sections): array => match ($role) {
    CompanyRole::Owner, CompanyRole::Admin => Section::cases(),
    CompanyRole::Accountant => array_values(array_filter(Section::cases(), fn (Section $s) => $s !== Section::Settings)),
    CompanyRole::Custom => array_values(array_filter(array_map(fn (string $v) => Section::tryFrom($v), $sections ?? []))),
};

$memberships = [
    'owner' => [CompanyRole::Owner, null],
    'admin' => [CompanyRole::Admin, null],
    'accountant' => [CompanyRole::Accountant, null],
    'custom, nothing ticked' => [CompanyRole::Custom, []],
    'custom, sales and reports' => [CompanyRole::Custom, ['customers', 'reports']],
    'custom, settings and banking' => [CompanyRole::Custom, ['settings', 'banking']],
    'custom, every section' => [CompanyRole::Custom, Section::values()],
    ...collect(Section::cases())->mapWithKeys(fn (Section $s) => ["custom, {$s->value} only" => [CompanyRole::Custom, [$s->value]]])->all(),
];

it('derives the same sections for every route as the old table', function () use ($oldSectionsFor) {
    $diffs = collect(Route::getRoutes()->getRoutes())
        ->map(fn (RoutingRoute $route) => $route->getName())
        ->filter()
        ->reject(fn (string $name) => Section::forRouteName($name) == $oldSectionsFor($name))
        ->values()
        ->all();

    expect($diffs)->toBe([]);
});

it('lets a membership open exactly the company pages it could before', function (CompanyRole $role, ?array $sections) use ($oldSectionsFor, $oldReach) {
    $permissions = LegacyRoleGrants::for($role, $sections);
    $reach = $oldReach($role, $sections);

    $diffs = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $middleware = collect($route->gatherMiddleware())->filter(fn ($m) => is_string($m));

        if (! $middleware->contains(fn (string $m) => str_starts_with($m, EnsureCompanyMembership::class))) {
            continue;
        }

        $name = (string) $route->getName();
        // Owner-only before roles, through the route's `:owner` middleware
        // (frozen: those routes now gate through reserved activities instead).
        $ownerOnly = $name === 'reports.audit-log'
            || str_starts_with($name, 'opening-balances.')
            || $middleware->contains(EnsureCompanyMembership::class.':owner');

        $oldSections = $oldSectionsFor($name);
        $old = ($oldSections === [] || array_intersect(array_map(fn ($s) => $s->value, $oldSections), array_map(fn ($s) => $s->value, $reach)) !== [])
            && (! $ownerOnly || $role === CompanyRole::Owner);

        $requirements = RoutePermissionMap::for($name);
        $new = ($requirements === [] || $permissions->allowsAny($requirements))
            && (! $ownerOnly || $role === CompanyRole::Owner);

        if ($old !== $new) {
            $diffs[] = $name.' (was '.($old ? 'open' : 'refused').')';
        }
    }

    expect($diffs)->toBe([]);
})->with($memberships);

it('reaches the same sidebar areas as before', function (CompanyRole $role, ?array $sections) use ($oldReach) {
    $permissions = LegacyRoleGrants::for($role, $sections);
    $reach = array_map(fn (Section $s) => $s->value, $oldReach($role, $sections));

    $areas = array_values(array_map(
        fn (Section $s) => $s->value,
        array_filter(Section::cases(), fn (Section $s) => $permissions->canAccessArea($s)),
    ));

    expect($areas)->toEqualCanonicalizing($reach);
})->with($memberships);

it('requires two-factor of exactly the owners and admins it did before', function (CompanyRole $role, ?array $sections) {
    expect(LegacyRoleGrants::for($role, $sections)->requiresTwoFactor())
        ->toBe($role->isAtLeast(CompanyRole::Admin));
})->with($memberships);
