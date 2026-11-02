<?php

namespace App\Livewire\Hooks;

use App\Http\Middleware\EnsureCompanyMembership;
use App\Http\Middleware\EnsureSiteAdmin;
use App\Http\Middleware\ResolvePortalCompany;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\ComponentHook;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Re-binds `current_company` in the container on every Livewire request.
 *
 * Initial GET requests bind it via the EnsureCompanyMembership HTTP middleware.
 * Livewire's AJAX update endpoint (/livewire-a56fc212/update) is a global route
 * that does not run a page's route middleware: it re-runs only the persistent
 * ones (see AppServiceProvider), which bind the page's {company} on tenant and
 * portal pages and nothing elsewhere. Without re-binding, the BelongsToCompany
 * trait can't auto-fill company_id on new models and CompanyScope can't
 * scope queries to the current company.
 *
 * Any Livewire component with a `public Company $company` property gets
 * the binding for free. Multi-tab safe — each request's component carries
 * its own company in the snapshot.
 *
 * Binding the tenant is also authorizing it. The persistent gates authorize the
 * {company} in the page's URL, and many pages have none: settings, the setup
 * wizard, and the layout's search and navigation on them. So before binding the
 * company a snapshot carries, this checks that the request may still act in it,
 * by the kind of page the component was rendered on:
 *  - a customer or employee portal ({@see ResolvePortalCompany}): it must be
 *    the portal's company;
 *  - the site-admin portal ({@see EnsureSiteAdmin}): the user must be a site
 *    admin, who may work in any company;
 *  - anywhere else: the signed-in user must be an active member of it (the
 *    rule {@see EnsureCompanyMembership} applies), and on a tenant page it must
 *    be the page's {company}, the one the gates just authorized.
 * Anything else gets a 403, as the page would.
 */
class BindCurrentCompanyHook extends ComponentHook
{
    public function mount($params, $parent): void
    {
        $this->bindFromComponent();
    }

    /**
     * @param  array<string, mixed>  $memo
     */
    public function hydrate($memo): void
    {
        $this->authorizeHydratedCompany($memo);

        $this->bindFromComponent();
    }

    public function call($method, $params, $returnEarly, $metadata, $componentContext)
    {
        $this->bindFromComponent();
    }

    public function update($property, $path, $value)
    {
        $this->bindFromComponent();
    }

    public function render($view, $data)
    {
        $this->bindFromComponent();
    }

    protected function bindFromComponent(): void
    {
        $company = $this->componentCompany();

        if ($company !== null) {
            app()->instance('current_company', $company);
        }
    }

    protected function componentCompany(): ?Company
    {
        $component = $this->component;

        if ($component === null) {
            return null;
        }

        if (! property_exists($component, 'company') || ! isset($component->company)) {
            return null;
        }

        return $component->company instanceof Company ? $component->company : null;
    }

    /**
     * A hydrated component's company comes from its checksummed snapshot, and
     * the client cannot swap it (Livewire restores a model by the snapshot's
     * key), so one check here covers the whole update. Components mounted
     * during the update get their company from server code instead.
     *
     * @param  array<string, mixed>  $memo
     */
    protected function authorizeHydratedCompany(array $memo): void
    {
        $company = $this->componentCompany();

        // With route middleware switched off (withoutMiddleware(), which
        // Livewire::test() uses) a page load would skip the page's gates too.
        if ($company === null || app()->shouldSkipMiddleware()) {
            return;
        }

        $route = $this->pageRoute($memo);
        $gates = $route === null ? [] : $this->gatesOf($route);

        // A portal page works in the company in its URL: the one
        // ResolvePortalCompany bound for the portal guard.
        if (in_array(ResolvePortalCompany::class, $gates, true)) {
            abort_unless($this->isPageCompany($route, $company), 403);

            return;
        }

        $user = Auth::guard('web')->user();
        $user = $user instanceof User ? $user : null;

        // The site-admin portal works across tenants: its company page mounts
        // the company it shows.
        if (in_array(EnsureSiteAdmin::class, $gates, true)) {
            abort_unless($user !== null && $user->site_admin, 403);

            return;
        }

        // Anywhere else only an active member may work in the company, and on a
        // tenant page only in the {company} its gates have just authorized.
        abort_unless(
            $user !== null && ! $user->isDisabled() && $user->belongsToCompany($company)
                && (! in_array(EnsureCompanyMembership::class, $gates, true) || $this->isPageCompany($route, $company)),
            403,
        );
    }

    /**
     * The route of the page the component was rendered on, from the path and
     * method in its snapshot, as Livewire's persistent middleware resolves it.
     *
     * @param  array<string, mixed>  $memo
     */
    protected function pageRoute(array $memo): ?Route
    {
        $path = $memo['path'] ?? null;
        $method = $memo['method'] ?? null;

        if (! is_string($path) || ! is_string($method)) {
            return null;
        }

        // On this request's scheme and host, not a bare path: a bare path makes
        // the host localhost, and cached routes (every deployment runs
        // route:cache) read the host while matching, so outside local and
        // testing localhost is refused as an untrusted host (a 400) unless
        // APP_URL happens to be localhost. This request's host has already
        // passed that check.
        try {
            return app('router')->getRoutes()->match(
                Request::create(request()->getSchemeAndHttpHost().'/'.ltrim($path, '/'), $method),
            );
        } catch (HttpExceptionInterface) {
            return null;
        }
    }

    /**
     * The middleware classes guarding $route, parameters dropped.
     *
     * @return list<mixed>
     */
    protected function gatesOf(Route $route): array
    {
        return array_map(
            fn (mixed $middleware): mixed => is_string($middleware) ? Str::before($middleware, ':') : $middleware,
            array_values(app('router')->gatherRouteMiddleware($route)),
        );
    }

    protected function isPageCompany(?Route $route, Company $company): bool
    {
        return $route !== null && $route->parameter('company') === $company->slug;
    }
}
