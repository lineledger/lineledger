<?php

use App\Actions\Companies\Members\UpdateMemberAccess;
use App\Enums\Activity;
use App\Enums\CompanyRole;
use App\Enums\PermissionAction;
use App\Enums\Section;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\DocumentFolder;
use App\Models\MembershipRole;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\EditLocks\EditLockManager;
use App\Support\Access\Grant;
use Illuminate\Support\Facades\Gate;

/**
 * What used to be hard-wired to the Owner or Admin now follows activities a
 * role carries: a custom role can be given the audit log, opening balances,
 * every document folder, taking over an edit, API keys, the closing date or
 * inviting people — and someone without it is refused.
 */
beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->member = User::factory()->create(['email_verified_at' => now()]);
    $this->membership = $this->company->memberships()->create([
        'user_id' => $this->member->id, 'role' => CompanyRole::Custom, 'sections' => [Section::Customers->value],
    ]);

    $this->grant = function (Activity $activity, Grant $grant): void {
        $role = Role::firstOrCreate(['name' => 'Office manager']);
        RolePermission::updateOrCreate(['role_id' => $role->id, 'activity' => $activity->value], RolePermission::attributesFor($activity, $grant));
        MembershipRole::firstOrCreate(['company_member_id' => $this->membership->id, 'role_id' => $role->id]);
    };
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('opens the audit log and opening balances only to a role that carries them', function (string $route, Activity $activity) {
    ($this->grant)(Activity::Customers, Grant::full());

    $this->actingAs($this->member)->get(route($route, ['company' => $this->company->slug]))->assertForbidden();

    ($this->grant)($activity, Grant::partial(PermissionAction::View));

    $this->actingAs($this->member)->get(route($route, ['company' => $this->company->slug]))->assertOk();
})->with([
    'audit log' => ['reports.audit-log', Activity::AuditLog],
    'opening balances' => ['opening-balances.index', Activity::OpeningBalances],
]);

it('lets a role see every document folder', function () {
    $folder = DocumentFolder::create(['name' => 'Board minutes']);

    expect($folder->isVisibleTo($this->membership))->toBeFalse();

    ($this->grant)(Activity::DocumentsAllFolders, Grant::partial(PermissionAction::View));

    expect($folder->isVisibleTo($this->membership))->toBeTrue()
        ->and($folder->isManageableBy($this->membership))->toBeFalse();
});

it('lets a role take over someone else\'s edit', function () {
    $record = CompanyApiKey::mint($this->company, 'Key', null)['key'];

    expect(app(EditLockManager::class)->canTakeOver($this->member, $record))->toBeFalse();

    ($this->grant)(Activity::EditLockTakeover, Grant::full());

    expect(app(EditLockManager::class)->canTakeOver($this->member, $record))->toBeTrue();
});

it('lets a role invite people and change organization settings, but only the Owner deletes it', function () {
    expect(Gate::forUser($this->member)->allows('inviteMember', $this->company))->toBeFalse()
        ->and(Gate::forUser($this->member)->allows('update', $this->company))->toBeFalse();

    ($this->grant)(Activity::Invitations, Grant::full());
    ($this->grant)(Activity::CompanySettings, Grant::full());

    expect(Gate::forUser($this->member)->allows('inviteMember', $this->company))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('update', $this->company))->toBeTrue()
        ->and(Gate::forUser($this->member)->allows('updateMember', $this->company))->toBeFalse()
        ->and(Gate::forUser($this->member)->allows('delete', $this->company))->toBeFalse();
});

it('requires two-factor of a role that manages API keys when the organization asks for it', function () {
    $this->company->forceFill(['require_two_factor' => true])->save();
    ($this->grant)(Activity::Customers, Grant::full());

    $this->actingAs($this->member)->get(route('customers.index', ['company' => $this->company->slug]))->assertOk();

    ($this->grant)(Activity::ApiKeys, Grant::full());

    $this->actingAs($this->member)->get(route('customers.index', ['company' => $this->company->slug]))
        ->assertRedirect(route('security.edit'));
});

it('treats moving from Custom with Settings to Accountant as a downgrade', function () {
    $owner = User::factory()->create();
    $this->company->memberships()->create(['user_id' => $owner->id, 'role' => CompanyRole::Owner]);
    $this->membership->update(['sections' => [Section::Settings->value, Section::Customers->value]]);
    $key = CompanyApiKey::mint($this->company, 'Member key', $this->member->id)['key'];

    app(UpdateMemberAccess::class)->handle($this->company, $owner, $this->member, CompanyRole::Accountant);

    // Accountant reaches more sections but not Settings: the key goes.
    expect($key->fresh()->revoked_at)->not->toBeNull();
});
