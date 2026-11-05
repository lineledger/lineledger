<?php

use App\Actions\Access\AssignMemberRoles;
use App\Actions\Access\DeleteRole;
use App\Actions\Access\ResetRoleToDefault;
use App\Actions\Access\SaveRole;
use App\Actions\Companies\Members\AcceptInvitation;
use App\Actions\Companies\Members\InviteMember;
use App\Enums\Activity;
use App\Enums\CompanyRole;
use App\Enums\PermissionAction;
use App\Enums\PredefinedRole;
use App\Enums\SecurityEvent;
use App\Exceptions\Access\RoleNarrowingNeedsConfirmation;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Role;
use App\Models\SecurityLog;
use App\Models\User;
use App\Support\Access\Grant;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Managing roles and who holds them: unique names, locked predefined names,
 * no escalation, confirmation before taking access away, and sign-out for
 * whoever loses something.
 */
beforeEach(function () {
    Notification::fake();
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->owner = User::factory()->create();
    $this->company->memberships()->create(['user_id' => $this->owner->id, 'role' => CompanyRole::Owner]);

    $this->member = User::factory()->create(['name' => 'Priya']);
    $this->company->memberships()->create(['user_id' => $this->member->id, 'role' => CompanyRole::Accountant]);

    $this->predefined = fn (PredefinedRole $key): Role => Role::query()->where('system_key', $key->value)->firstOrFail();
    $this->assign = fn (User $user, Role ...$roles) => app(AssignMemberRoles::class)->handle($this->company, $this->owner, $user, array_map(fn (Role $r) => $r->id, $roles));
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('gives a member several roles and combines them', function () {
    ($this->assign)($this->member, ($this->predefined)(PredefinedRole::Sales), ($this->predefined)(PredefinedRole::Banking));

    $permissions = $this->member->permissions($this->company);

    expect($permissions->allows(Activity::Invoices, PermissionAction::Create))->toBeTrue()
        ->and($permissions->allows(Activity::Deposits, PermissionAction::Delete))->toBeTrue()
        ->and($permissions->canView(Activity::Journal))->toBeFalse()
        ->and(SecurityLog::query()->where('event', SecurityEvent::CompanyMemberRoleChanged->value)->latest('id')->first()->metadata['to_roles'])
        ->toBe(['Banking', 'Sales']);
});

it('signs a member out when their new roles take something away', function () {
    $key = CompanyApiKey::mint($this->company, 'Key', $this->member->id)['key'];

    ($this->assign)($this->member, ($this->predefined)(PredefinedRole::ViewOnly));

    expect($key->fresh()->revoked_at)->not->toBeNull();
});

it('refuses no roles, another company\'s role, and changing the Owner', function (Closure $act) {
    $act($this);
})->with([
    'no roles' => [fn ($t) => app(AssignMemberRoles::class)->handle($t->company, $t->owner, $t->member, [])],
    'foreign role' => [fn ($t) => app(AssignMemberRoles::class)->handle($t->company, $t->owner, $t->member, [
        Role::query()->withoutGlobalScopes()->where('company_id', Company::factory()->create()->id)->value('id'),
    ])],
])->throws(ValidationException::class);

it('never lets someone hand out more than they hold, or change their own roles', function () {
    $manager = User::factory()->create();
    $this->company->memberships()->create(['user_id' => $manager->id, 'role' => CompanyRole::Custom, 'sections' => []]);
    $managerRole = app(SaveRole::class)->handle($this->company, $this->owner, null, 'People manager', null, [
        Activity::Users->value => Grant::full(),
        Activity::Customers->value => Grant::full(),
    ]);
    ($this->assign)($manager, $managerRole);

    expect(fn () => app(AssignMemberRoles::class)->handle($this->company, $manager, $this->member, [($this->predefined)(PredefinedRole::Banking)->id]))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(AssignMemberRoles::class)->handle($this->company, $manager, $manager, [$managerRole->id]))
        ->toThrow(ValidationException::class);
});

it('creates a role with only the actions each activity offers', function () {
    $role = app(SaveRole::class)->handle($this->company, $this->owner, null, 'Front desk', 'Greets people', [
        Activity::Customers->value => Grant::partial(PermissionAction::View, PermissionAction::Create),
        Activity::TaxCodes->value => Grant::partial(PermissionAction::Print),
        'not-an-activity' => Grant::full(),
    ]);

    // Print isn't offered on tax codes, so only the View it implies remains.
    expect(array_keys($role->grants()))->toBe([Activity::Customers->value, Activity::TaxCodes->value])
        ->and($role->permissionSet()->allows(Activity::Customers, PermissionAction::Create))->toBeTrue()
        ->and($role->grants()[Activity::TaxCodes->value]->actionsFor(Activity::TaxCodes))->toBe([PermissionAction::View]);
});

it('keeps role names unique ignoring case, and predefined names fixed', function () {
    app(SaveRole::class)->handle($this->company, $this->owner, null, 'Front desk', null, []);

    expect(fn () => app(SaveRole::class)->handle($this->company, $this->owner, null, 'FRONT DESK', null, []))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(SaveRole::class)->handle($this->company, $this->owner, ($this->predefined)(PredefinedRole::Sales), 'Sellers', null, []))
        ->toThrow(ValidationException::class);
});

it('asks before narrowing a role people hold, then signs them out', function () {
    $sales = ($this->predefined)(PredefinedRole::Sales);
    ($this->assign)($this->member, $sales);
    $key = CompanyApiKey::mint($this->company, 'Key', $this->member->id)['key'];

    $narrower = [Activity::Customers->value => Grant::partial(PermissionAction::View)];

    expect(fn () => app(SaveRole::class)->handle($this->company, $this->owner, $sales, $sales->name, null, $narrower))
        ->toThrow(RoleNarrowingNeedsConfirmation::class);
    expect($key->fresh()->revoked_at)->toBeNull();

    $saved = app(SaveRole::class)->handle($this->company, $this->owner, $sales, $sales->name, null, $narrower, confirmed: true);

    expect($saved->followsCatalogue())->toBeFalse()
        ->and($this->member->permissions($this->company)->allows(Activity::Invoices, PermissionAction::Create))->toBeFalse()
        ->and($key->fresh()->revoked_at)->not->toBeNull();
});

it('resets an edited predefined role to the catalogue', function () {
    $sales = ($this->predefined)(PredefinedRole::Sales);
    app(SaveRole::class)->handle($this->company, $this->owner, $sales, $sales->name, null, [Activity::Customers->value => Grant::full()]);

    $reset = app(ResetRoleToDefault::class)->handle($this->company, $this->owner, $sales->fresh());

    expect($reset->followsCatalogue())->toBeTrue()
        ->and($reset->permissionSet()->allows(Activity::Invoices, PermissionAction::Create))->toBeTrue();
});

it('deletes a custom role, moving people who held only it', function () {
    $desk = app(SaveRole::class)->handle($this->company, $this->owner, null, 'Front desk', null, [Activity::Customers->value => Grant::full()]);
    ($this->assign)($this->member, $desk);

    expect(fn () => app(DeleteRole::class)->handle($this->company, $this->owner, $desk))->toThrow(ValidationException::class);

    app(DeleteRole::class)->handle($this->company, $this->owner, $desk, ($this->predefined)(PredefinedRole::Sales), confirmed: true);

    expect(Role::query()->find($desk->id))->toBeNull()
        ->and($this->member->permissions($this->company)->allows(Activity::Invoices, PermissionAction::Create))->toBeTrue()
        ->and(fn () => app(DeleteRole::class)->handle($this->company, $this->owner, ($this->predefined)(PredefinedRole::Sales)))
        ->toThrow(ValidationException::class);
});

it('invites with chosen roles that the new member receives', function () {
    $invitation = app(InviteMember::class)->handle($this->company, $this->owner, 'ana@example.com', CompanyRole::Accountant, null, [($this->predefined)(PredefinedRole::Bookkeeper)->id]);
    $ana = User::factory()->create(['email' => 'ana@example.com']);

    app(AcceptInvitation::class)->handle($invitation, $ana);

    expect($ana->permissions($this->company)->canView(Activity::Journal))->toBeTrue()
        ->and($ana->permissions($this->company)->canView(Activity::PayRuns))->toBeFalse();
});
