<?php

use App\Livewire\Attributes\GuardsEditLock;
use App\Livewire\Concerns\GuardsEditLockedForm;
use App\Livewire\Concerns\HoldsEditLock;
use App\Livewire\Concerns\ShowsEditLock;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/*
 * Guard rails: a new edit page, show page or edit dialog that forgets the edit
 * lock fails here rather than silently letting two people overwrite each other.
 */

/**
 * The Livewire component class behind a route, or null for a non-Livewire route.
 *
 * @return class-string|null
 */
$componentFor = function (RoutingRoute $route): ?string {
    $name = $route->action['livewire_component'] ?? null;

    return is_string($name) ? app('livewire.factory')->resolveComponentClass($name) : null;
};

it('locks every full-page edit form under {company}', function () use ($componentFor) {
    $checked = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uri = $route->uri();

        if (! str_starts_with($uri, '{company}/') || ! preg_match('#/(edit|setup)$#', $uri)) {
            continue;
        }

        $class = $componentFor($route);

        if ($class === null) {
            continue;
        }

        expect(class_uses_recursive($class))->toHaveKey(GuardsEditLockedForm::class, message: "{$route->getName()} does not use GuardsEditLockedForm");

        $checked[] = $route->getName();
    }

    expect($checked)->toHaveCount(30);
});

it('makes every show page that has an edit page aware of the lock, with at least one guarded action', function () use ($componentFor) {
    $checked = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = (string) $route->getName();

        if (! str_starts_with($route->uri(), '{company}/') || ! str_ends_with($name, '.show')) {
            continue;
        }

        if (! Route::has(substr($name, 0, -strlen('.show')).'.edit')) {
            continue;
        }

        $class = $componentFor($route);

        expect($class)->not->toBeNull()
            ->and(class_uses_recursive($class))->toHaveKey(ShowsEditLock::class, message: "{$name} does not use ShowsEditLock");

        $guarded = collect((new ReflectionClass($class))->getMethods())
            ->filter(fn (ReflectionMethod $m) => $m->getAttributes(GuardsEditLock::class) !== []);

        expect($guarded->isNotEmpty())->toBeTrue("{$name} guards no actions");

        $checked[] = $name;
    }

    expect($checked)->toHaveCount(25);
});

it('locks every master-data edit dialog', function (string $component) {
    $class = app('livewire.factory')->resolveComponentClass($component);

    expect(class_uses_recursive($class))->toContain(HoldsEditLock::class);

    // The reflected file is Livewire's compiled class, which has no template —
    // read the single-file component's own source instead.
    $segments = explode('.', substr($component, strlen('pages::')));
    $name = array_pop($segments);
    $path = resource_path('views/pages/'.implode('/', $segments).'/⚡'.$name.'.blade.php');

    expect($path)->toBeFile();

    $source = (string) file_get_contents($path);

    expect($source)->toContain('acquireEditLock(')
        ->toContain('ensureEditLockForSave(')
        ->toContain('completeEditLockSave()')
        ->toContain('<x-edit-lock.keeper')
        ->toContain('wire:close="releaseEditLock"')
        ->toContain('<x-edit-lock.takeover-modal');
})->with([
    'pages::accounts.index',
    'pages::customers.index',
    'pages::vendors.index',
    'pages::employees.index',
    'pages::banking.rules',
    'pages::settings.lists.items',
    'pages::settings.lists.item-categories',
    'pages::settings.lists.tax-codes',
    'pages::settings.lists.payment-methods',
    'pages::settings.lists.payment-terms',
    'pages::settings.lists.classifications',
    'pages::settings.lists.locations',
    'pages::settings.lists.funds',
    'pages::settings.lists.asset-categories',
    'pages::settings.lists.membership-levels',
    'pages::settings.lists.other-names',
    'pages::settings.lists.form-styles',
    'pages::payroll.schedules.index',
    'pages::payroll.time-off-policies.index',
    'pages::opening-balances.cheques',
    'pages::opening-balances.deposits',
]);
