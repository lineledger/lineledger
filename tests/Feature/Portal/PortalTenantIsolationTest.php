<?php

use App\Enums\Country;
use App\Http\Middleware\ResolvePortalCompany;
use App\Models\Company;
use App\Models\Contact;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

/*
 * The portals authenticate a Contact on the shared `customer` session guard.
 * ResolvePortalCompany must bind the URL's company *before* that guard resolves
 * the Contact (and before route-model binding), so CompanyScope confines the
 * lookup: a session carrying a contact id from another organization must
 * resolve to nobody. These tests sign in through the session, not actingAs(),
 * because actingAs() hands the guard a user and skips the scoped lookup.
 */

beforeEach(function () {
    $this->home = Company::factory()->forCountry(Country::Canada, 'ON')->create();
    $this->other = Company::factory()->forCountry(Country::Canada, 'ON')->create();
});

afterEach(fn () => app()->forgetInstance('current_company'));

function portalContactIn(Company $company, array $attributes): Contact
{
    app()->instance('current_company', $company);
    $contact = Contact::create(['display_name' => 'Portal contact', 'email' => uniqid().'@example.test', ...$attributes]);
    app()->forgetInstance('current_company');

    return $contact;
}

function portalSession(Contact $contact): array
{
    return [Auth::guard('customer')->getName() => $contact->id];
}

it('does not authenticate another organization’s customer on the customer portal', function () {
    $outsider = portalContactIn($this->other, ['is_customer' => true]);

    $this->withSession(portalSession($outsider))
        ->get(route('portal.dashboard', ['company' => $this->home->slug]))
        ->assertRedirect(route('portal.login', ['company' => $this->home->slug]));

    expect(Auth::guard('customer')->user())->toBeNull();
});

it('does not authenticate another organization’s employee on the employee portal', function () {
    $outsider = portalContactIn($this->other, ['is_employee' => true]);

    $this->withSession(portalSession($outsider))
        ->get(route('employee-portal.dashboard', ['company' => $this->home->slug]))
        ->assertRedirect(route('employee-portal.login', ['company' => $this->home->slug]));

    expect(Auth::guard('customer')->user())->toBeNull();
});

it('still signs a customer in to their own organization’s portal', function () {
    $customer = portalContactIn($this->home, ['is_customer' => true]);

    $this->withSession(portalSession($customer))
        ->get(route('portal.dashboard', ['company' => $this->home->slug]))
        ->assertOk();

    expect(Auth::guard('customer')->user()?->is($customer))->toBeTrue();
});

it('binds the portal company before the customer guard and route-model binding, on page loads and Livewire updates', function () {
    foreach (['portal.dashboard', 'portal.invoices.show', 'employee-portal.dashboard'] as $name) {
        $route = app('router')->getRoutes()->getByName($name);
        $order = array_map(
            fn ($m) => is_string($m) ? explode(':', $m)[0] : $m::class,
            app('router')->gatherRouteMiddleware($route),
        );

        $resolve = array_search(ResolvePortalCompany::class, $order, true);

        expect($resolve)->not->toBeFalse()
            ->and($resolve)->toBeLessThan(array_search(Authenticate::class, $order, true))
            ->and($resolve)->toBeLessThan(array_search(SubstituteBindings::class, $order, true));
    }

    // Livewire re-runs only "persistent" route middleware on /livewire/update.
    expect(Livewire::getPersistentMiddleware())->toContain(ResolvePortalCompany::class);
});
