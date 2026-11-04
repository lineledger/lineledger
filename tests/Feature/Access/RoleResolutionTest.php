<?php

use App\Enums\Activity;
use App\Enums\CompanyRole;
use App\Enums\PermissionAction;
use App\Enums\PredefinedRole;
use App\Models\Company;
use App\Models\MembershipRole;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Access\Grant;

/**
 * Once a member holds roles, their access is the most permissive combination
 * of those roles' grants; a predefined role follows the catalogue until it is
 * edited.
 */
beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->user = User::factory()->create();
    $this->membership = $this->company->memberships()->create(['user_id' => $this->user->id, 'role' => CompanyRole::Custom, 'sections' => []]);

    $this->assign = function (Role ...$roles): void {
        foreach ($roles as $role) {
            MembershipRole::create(['company_member_id' => $this->membership->id, 'role_id' => $role->id]);
        }
    };

    $this->predefined = fn (PredefinedRole $key): Role => Role::query()->where('system_key', $key->value)->firstOrFail();
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('uses the roles a member holds instead of their original role', function () {
    ($this->assign)(($this->predefined)(PredefinedRole::Sales));

    $permissions = $this->user->permissions($this->company);

    expect($permissions->allows(Activity::Invoices, PermissionAction::Create))->toBeTrue()
        ->and($permissions->allows(Activity::Invoices, PermissionAction::Delete))->toBeFalse()
        ->and($permissions->canView(Activity::Bills))->toBeFalse();
});

it('combines several roles to the most permissive', function () {
    ($this->assign)(($this->predefined)(PredefinedRole::Sales), ($this->predefined)(PredefinedRole::Banking));

    $permissions = $this->user->permissions($this->company);

    expect($permissions->allows(Activity::Invoices, PermissionAction::Create))->toBeTrue()
        ->and($permissions->allows(Activity::Deposits, PermissionAction::Delete))->toBeTrue()
        ->and($permissions->canViewBalances(Activity::Accounts))->toBeTrue()
        ->and($permissions->canView(Activity::Journal))->toBeFalse();
});

it('reads an edited predefined role from its own rows', function () {
    $sales = ($this->predefined)(PredefinedRole::Sales);
    ($this->assign)($sales);

    $sales->update(['customized_at' => now()]);
    RolePermission::create(['role_id' => $sales->id, ...RolePermission::attributesFor(Activity::Invoices, Grant::partial(PermissionAction::View))]);

    $permissions = $this->user->permissions($this->company);

    expect($permissions->allows(Activity::Invoices, PermissionAction::View))->toBeTrue()
        ->and($permissions->allows(Activity::Invoices, PermissionAction::Create))->toBeFalse()
        ->and($permissions->canView(Activity::Estimates))->toBeFalse();
});

it('drops access as soon as a role loses a permission or the member loses the role', function () {
    $role = Role::create(['name' => 'Front desk']);
    $grant = RolePermission::create(['role_id' => $role->id, ...RolePermission::attributesFor(Activity::Customers, Grant::full())]);
    ($this->assign)($role);

    expect($this->user->permissions($this->company)->canView(Activity::Customers))->toBeTrue();

    $grant->delete();
    expect($this->user->permissions($this->company)->canView(Activity::Customers))->toBeFalse();

    RolePermission::create(['role_id' => $role->id, ...RolePermission::attributesFor(Activity::Customers, Grant::full())]);
    MembershipRole::query()->where('role_id', $role->id)->delete();
    MembershipRole::create(['company_member_id' => $this->membership->id, 'role_id' => ($this->predefined)(PredefinedRole::ViewOnly)->id]);

    expect($this->user->permissions($this->company)->allows(Activity::Customers, PermissionAction::Modify))->toBeFalse();
});

it('keeps the Owner all-powerful whatever roles exist', function () {
    $owner = User::factory()->create();
    $this->company->memberships()->create(['user_id' => $owner->id, 'role' => CompanyRole::Owner]);

    expect($owner->permissions($this->company)->isOwner())->toBeTrue();
});
