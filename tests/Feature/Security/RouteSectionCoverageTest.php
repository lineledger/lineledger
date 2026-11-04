<?php

use App\Enums\Section;
use App\Http\Middleware\EnsureCompanyMembership;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * Section access only protects a tenant route that Section::forRouteName() maps
 * to a section: an unmapped name resolves to [] and EnsureSectionAccess lets
 * every member through. So every route behind EnsureCompanyMembership must map
 * to a section, or be on this reviewed list of routes deliberately open to all
 * members (each with the reason it is safe). A new route that forgets its
 * mapping fails here instead of silently opening to everyone (R07, audit G-001).
 */
$reviewedUngated = [
    'dashboard' => 'the landing page every member sees after signing in',
    'insights.index' => 'insight history: the same aggregate data class as the dashboard cards',
    'attachments.download' => 'one route for every record type; each download checks the record\'s section (AttachmentAccess)',
    'companies.edit' => 'organization settings; its actions are gated by CompanyPolicy',
];

it('maps every tenant route to a section unless it is reviewed as open to all members', function () use ($reviewedUngated) {
    $tenantRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => collect($route->gatherMiddleware())->contains(
            fn (mixed $middleware): bool => is_string($middleware) && str_starts_with($middleware, EnsureCompanyMembership::class),
        ))
        ->map(fn (RoutingRoute $route): string => $route->getName() ?? $route->uri());

    $unmapped = $tenantRoutes
        ->reject(fn (string $name): bool => Section::forRouteName($name) !== [] || array_key_exists($name, $reviewedUngated))
        ->values()
        ->all();

    expect($tenantRoutes)->not->toBeEmpty()
        ->and($unmapped)->toBe([]);
});

it('keeps the reviewed list free of stale or since-mapped routes', function (string $name) {
    expect(Route::has($name))->toBeTrue("{$name} no longer exists — drop it from the reviewed list")
        ->and(Section::forRouteName($name))->toBe([], "{$name} now maps to a section — drop it from the reviewed list");
})->with(array_keys($reviewedUngated));
