<?php

use App\Http\Middleware\EnsureCompanyMembership;
use App\Support\Access\RoutePermissionMap;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * Every company page must say what loading it needs. A route the map doesn't
 * know resolves to nothing, and EnsureSectionAccess lets every member through.
 */
$tenantRoutes = fn (): array => collect(Route::getRoutes()->getRoutes())
    ->filter(fn (RoutingRoute $route): bool => collect($route->gatherMiddleware())->contains(
        fn (mixed $m): bool => is_string($m) && str_starts_with($m, EnsureCompanyMembership::class),
    ))
    ->map(fn (RoutingRoute $route): string => (string) ($route->getName() ?? $route->uri()))
    ->values()
    ->all();

it('maps every company route to an activity unless it is open to every member', function () use ($tenantRoutes) {
    $unmapped = array_values(array_filter(
        $tenantRoutes(),
        fn (string $name): bool => RoutePermissionMap::for($name) === [] && ! in_array($name, RoutePermissionMap::UNGATED, true),
    ));

    expect($unmapped)->toBe([]);
});

it('only ever asks for an action the activity offers', function () use ($tenantRoutes) {
    $impossible = [];

    foreach ($tenantRoutes() as $name) {
        foreach (RoutePermissionMap::for($name) as $requirement) {
            if (! $requirement->activity->supports($requirement->action)) {
                $impossible[] = "{$name} → {$requirement}";
            }
        }
    }

    expect($impossible)->toBe([]);
});

it('keeps the ungated list to routes that exist', function (string $name) {
    expect(Route::has($name))->toBeTrue();
})->with(RoutePermissionMap::UNGATED);

it('reads the action from the route name', function (string $name, string $requirement) {
    expect(array_map('strval', RoutePermissionMap::for($name)))->toBe([$requirement]);
})->with([
    ['invoices.index', 'invoices:view'],
    ['invoices.create', 'invoices:create'],
    ['invoices.edit', 'invoices:modify'],
    ['invoices.print', 'invoices:print'],
    ['bill-payments.batch', 'bill-payments:create'],
    ['bill-payments.print-cheque', 'bill-payments:print'],
    ['payroll.reports.t4', 'payroll-reports:view'],
    ['payroll.employees.setup', 'payroll-setup:modify'],
    ['banking.reconcile', 'reconciliation:view'],
    ['inventory.adjustments.index', 'inventory-adjustments:view'],
    ['reports.ar-aging', 'reports-sales:view'],
    ['reports.balance-sheet.sections', 'reports-financial:modify'],
    ['reports.statutory-accounts.pdf', 'reports-financial:print'],
    ['tax-returns.payments.create', 'tax-returns:create'],
    ['migration.import', 'data-import:create'],
    ['settings.backup.download', 'backup:view'],
]);
