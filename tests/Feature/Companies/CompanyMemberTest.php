<?php

use App\Enums\Activity;
use App\Enums\CompanyRole;
use App\Enums\PermissionAction;
use App\Enums\PredefinedRole;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\Access\LegacyRoleConverter;
use Livewire\Livewire;

test('company member roles can be changed by the owner', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $company = Company::factory()->create();

    $company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);
    $company->members()->attach($member, ['role' => CompanyRole::Accountant->value]);

    $this->actingAs($owner);
    session(['auth.password_confirmed_at' => time()]);
    $fullAccess = Role::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('system_key', PredefinedRole::FullAccess->value)->value('id');

    Livewire::test('pages::settings.users-and-roles', ['company' => $company])
        ->call('editRoles', $member->id)
        ->set('editRoleIds', [$fullAccess])
        ->call('saveRoles')
        ->assertHasNoErrors();

    expect($member->permissions($company)->allows(Activity::CompanySettings, PermissionAction::Modify))->toBeTrue();
});

test('company member roles cannot be changed by someone whose roles do not manage users', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $company = Company::factory()->create();

    $company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);
    $company->members()->attach($admin, ['role' => CompanyRole::Admin->value]);
    $company->members()->attach($member, ['role' => CompanyRole::Accountant->value]);

    $this->actingAs($admin);
    session(['auth.password_confirmed_at' => time()]);

    Livewire::test('pages::settings.users-and-roles', ['company' => $company])
        ->call('editRoles', $member->id)
        ->call('saveRoles')
        ->assertForbidden();
});

test('company member can be removed by owner', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $company = Company::factory()->create();

    $company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);
    $company->members()->attach($member, ['role' => CompanyRole::Accountant->value]);

    $this->actingAs($owner);

    Livewire::test('pages::companies.remove-member-modal', ['company' => $company, 'memberId' => $member->id])
        ->call('removeMember')
        ->assertHasNoErrors();

    expect($member->fresh()->belongsToCompany($company))->toBeFalse();
});

test('company member cannot be removed by non owners', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $member = User::factory()->create();
    $company = Company::factory()->create();

    $company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);
    $company->members()->attach($admin, ['role' => CompanyRole::Admin->value]);
    $company->members()->attach($member, ['role' => CompanyRole::Accountant->value]);

    $this->actingAs($admin);

    Livewire::test('pages::companies.remove-member-modal', ['company' => $company, 'memberId' => $member->id])
        ->call('removeMember')
        ->assertForbidden();
});

test('removed members current company is set to personal company', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $personalCompany = $member->personalCompany();
    $company = Company::factory()->create();

    $company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);
    $company->members()->attach($member, ['role' => CompanyRole::Accountant->value]);

    $member->forceFill(['current_company_id' => $company->id])->save();

    $this->actingAs($owner);

    Livewire::test('pages::companies.remove-member-modal', ['company' => $company, 'memberId' => $member->id])
        ->call('removeMember')
        ->assertHasNoErrors();

    expect($member->fresh()->current_company_id)->toEqual($personalCompany->id);
});

it('opens the roles dialog with the member\'s roles and closes it on save', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create(['name' => 'Morgan Member']);
    $company = Company::factory()->create();

    $company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);
    $company->members()->attach($member, ['role' => CompanyRole::Accountant->value]);
    app(LegacyRoleConverter::class)->convertCompany($company);
    $accountant = Role::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('system_key', PredefinedRole::Accountant->value)->value('id');
    $sales = Role::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('system_key', PredefinedRole::Sales->value)->value('id');

    session(['auth.password_confirmed_at' => time()]);

    // The Roles button calls editRoles(); the dialog is a Flux modal, so it
    // must be opened (and closed after saving) through Flux, not a bare event.
    Livewire::actingAs($owner)
        ->test('pages::settings.users-and-roles', ['company' => $company])
        ->call('editRoles', $member->id)
        ->assertDispatched('modal-show', name: 'edit-user-roles')
        ->assertSet('editRoleIds', [$accountant])
        ->set('editRoleIds', [$sales])
        ->call('saveRoles')
        ->assertHasNoErrors()
        ->assertDispatched('modal-close', name: 'edit-user-roles');

    expect($member->permissions($company)->allows(Activity::Invoices, PermissionAction::Create))->toBeTrue()
        ->and($member->permissions($company)->canView(Activity::Journal))->toBeFalse();
});
