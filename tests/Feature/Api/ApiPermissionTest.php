<?php

use App\Enums\Activity;
use App\Enums\CompanyRole;
use App\Enums\PermissionAction;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\MembershipRole;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Access\ApiRoutePermissionMap;
use App\Support\Access\Grant;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * An API key can never do more than the person who created it may do in the
 * organization, in the same activity terms as the web app.
 */
it('maps every API route to what it needs', function () {
    $unmapped = collect(Route::getRoutes()->getRoutes())
        ->map(fn (RoutingRoute $route) => (string) $route->getName())
        ->filter(fn (string $name) => str_starts_with($name, 'api.v1.') && $name !== 'api.v1.openapi')
        ->reject(fn (string $name) => ApiRoutePermissionMap::for($name) !== null)
        ->values()
        ->all();

    expect($unmapped)->toBe([]);
});

it('only asks for actions the activities offer', function () {
    $impossible = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        foreach (ApiRoutePermissionMap::for($route->getName()) ?? [] as $anyOf) {
            foreach ($anyOf as $requirement) {
                if (! $requirement->activity->supports($requirement->action)) {
                    $impossible[] = $route->getName().' → '.$requirement;
                }
            }
        }
    }

    expect($impossible)->toBe([]);
});

describe('a key made by a member', function () {
    beforeEach(function () {
        $this->company = Company::factory()->create();
        app()->instance('current_company', $this->company);

        $this->member = User::factory()->create();
        $membership = $this->company->memberships()->create(['user_id' => $this->member->id, 'role' => CompanyRole::Custom, 'sections' => []]);
        $this->role = Role::create(['name' => 'API clerk']);
        MembershipRole::create(['company_member_id' => $membership->id, 'role_id' => $this->role->id]);
        app()->forgetInstance('current_company');

        $this->token = CompanyApiKey::mint($this->company, 'Clerk key', $this->member->id)['plaintext'];

        $this->grant = function (Activity $activity, Grant $grant): void {
            app()->instance('current_company', $this->company);
            RolePermission::updateOrCreate(['role_id' => $this->role->id, 'activity' => $activity->value], RolePermission::attributesFor($activity, $grant));
            app()->forgetInstance('current_company');
        };
    });

    it('is refused what its creator may not do, whatever its scopes', function () {
        $this->withToken($this->token)->getJson('/api/v1/invoices')->assertForbidden();

        ($this->grant)(Activity::Invoices, Grant::partial(PermissionAction::View));

        $this->withToken($this->token)->getJson('/api/v1/invoices')->assertOk();
        $this->withToken($this->token)->postJson('/api/v1/invoices', [])->assertForbidden();
    });

    it('stops working the moment its creator loses the permission', function () {
        ($this->grant)(Activity::Customers, Grant::full());
        $this->withToken($this->token)->getJson('/api/v1/customers')->assertOk();

        ($this->grant)(Activity::Customers, Grant::none());
        $this->withToken($this->token)->getJson('/api/v1/customers')->assertForbidden();
    });
});

it('keeps a key with no recorded creator limited by its scopes alone', function () {
    $company = Company::factory()->create();
    $token = CompanyApiKey::mint($company, 'Console key')['plaintext'];

    $this->withToken($token)->getJson('/api/v1/customers')->assertOk();
});
