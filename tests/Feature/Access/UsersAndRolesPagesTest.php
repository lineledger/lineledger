<?php

use App\Enums\Activity;
use App\Enums\CompanyRole;
use App\Enums\PermissionAction;
use App\Enums\PredefinedRole;
use App\Enums\Section;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\Role;
use App\Models\User;
use App\Services\Access\LegacyRoleConverter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * Settings → Users and roles, the role editor and the Permission Access by
 * Roles report.
 */
beforeEach(function () {
    Notification::fake();
    $this->company = Company::factory()->create();
    $this->owner = User::factory()->create(['email_verified_at' => now()]);
    $this->company->members()->attach($this->owner, ['role' => CompanyRole::Owner->value]);
    $this->member = User::factory()->create(['name' => 'Marc', 'email_verified_at' => now()]);
    $this->company->members()->attach($this->member, ['role' => CompanyRole::Accountant->value]);
    app(LegacyRoleConverter::class)->convertCompany($this->company);

    session(['auth.password_confirmed_at' => time()]);
    $this->roleId = fn (PredefinedRole $key): int => (int) Role::query()->withoutGlobalScopes()
        ->where('company_id', $this->company->id)->where('system_key', $key->value)->value('id');
});

it('lists users with their roles, and roles with their holders', function () {
    $this->actingAs($this->owner)
        ->get(route('settings.users-and-roles', ['company' => $this->company->slug]))
        ->assertOk()
        ->assertSee('Marc')
        ->assertSee('Accountant');

    $this->actingAs($this->owner)
        ->get(route('settings.users-and-roles', ['company' => $this->company->slug, 'tab' => 'roles']))
        ->assertOk()
        ->assertSee('Full Access *')
        ->assertSee('Payroll Processor');
});

it('keeps the page from members whose roles don\'t manage users', function () {
    $this->actingAs($this->member)
        ->get(route('settings.users-and-roles', ['company' => $this->company->slug]))
        ->assertForbidden();
});

it('invites someone with the roles chosen', function () {
    Livewire::actingAs($this->owner)
        ->test('pages::settings.users-and-roles', ['company' => $this->company])
        ->set('inviteEmail', 'priya@example.com')
        ->set('inviteRoleIds', [($this->roleId)(PredefinedRole::Bookkeeper), ($this->roleId)(PredefinedRole::Sales)])
        ->call('invite')
        ->assertHasNoErrors();

    $invitation = CompanyInvitation::query()->where('email', 'priya@example.com')->firstOrFail();

    expect(DB::table('company_invitation_roles')->where('company_invitation_id', $invitation->id)->count())->toBe(2);
});

it('asks for at least one role when inviting', function () {
    Livewire::actingAs($this->owner)
        ->test('pages::settings.users-and-roles', ['company' => $this->company])
        ->set('inviteEmail', 'priya@example.com')
        ->call('invite')
        ->assertHasErrors('inviteRoleIds');
});

it('creates a role from the editor', function () {
    Livewire::actingAs($this->owner)
        ->test('pages::settings.roles.form', ['company' => $this->company])
        ->set('name', 'Front desk')
        ->set('levels.customers', 'partial')
        ->set('ticks.customers.view', true)
        ->set('ticks.customers.create', true)
        ->set('levels.invoices', 'full')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('settings.users-and-roles', ['company' => $this->company->slug, 'tab' => 'roles']));

    $role = Role::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('name', 'Front desk')->firstOrFail();

    expect($role->permissionSet()->allows(Activity::Customers, PermissionAction::Create))->toBeTrue()
        ->and($role->permissionSet()->allows(Activity::Customers, PermissionAction::Delete))->toBeFalse()
        ->and($role->permissionSet()->allows(Activity::Invoices, PermissionAction::Delete))->toBeTrue();
});

it('ticks the actions to match the level chosen for an activity', function () {
    Livewire::actingAs($this->owner)
        ->test('pages::settings.roles.form', ['company' => $this->company])
        ->set('levels.credit-memos', 'full')
        ->assertSet('ticks.credit-memos', ['view' => true, 'create' => true, 'modify' => true, 'delete' => true, 'print' => true])
        ->set('levels.credit-memos', 'partial')
        ->assertSet('ticks.credit-memos.delete', true)
        ->set('levels.credit-memos', 'none')
        ->assertSet('ticks.credit-memos', ['view' => false, 'create' => false, 'modify' => false, 'delete' => false, 'print' => false])
        ->set('levels.credit-memos', 'partial')
        ->assertSet('ticks.credit-memos.view', true)
        ->assertSet('ticks.credit-memos.create', false);
});

it('duplicates a role into a new draft', function () {
    $component = Livewire::actingAs($this->owner)
        ->withQueryParams(['from' => ($this->roleId)(PredefinedRole::Sales)])
        ->test('pages::settings.roles.form', ['company' => $this->company]);

    $component->assertSet('name', 'Copy of Sales')
        ->assertSet('levels.estimates', 'partial')
        ->assertSet('ticks.estimates.delete', false);
});

it('confirms before narrowing a role people hold', function () {
    $accountant = Role::query()->withoutGlobalScopes()->findOrFail(($this->roleId)(PredefinedRole::Accountant));

    $page = Livewire::actingAs($this->owner)
        ->test('pages::settings.roles.form', ['company' => $this->company, 'role' => $accountant])
        ->call('setArea', Section::Banking->value, 'none')
        ->call('save')
        ->assertSet('affected', ['Marc'])
        ->assertDispatched('modal-show', name: 'confirm-narrowing');

    expect($this->member->permissions($this->company)->canView(Activity::Deposits))->toBeTrue();

    $page->call('confirmPending')->assertHasNoErrors();

    expect($this->member->fresh()->permissions($this->company)->canView(Activity::Deposits))->toBeFalse();
});

it('keeps a predefined role\'s name and resets it to the catalogue', function () {
    $sales = Role::query()->withoutGlobalScopes()->findOrFail(($this->roleId)(PredefinedRole::Sales));

    Livewire::actingAs($this->owner)
        ->test('pages::settings.roles.form', ['company' => $this->company, 'role' => $sales])
        ->set('name', 'Sellers')
        ->call('save')
        ->assertHasErrors('name');

    Livewire::actingAs($this->owner)
        ->test('pages::settings.roles.form', ['company' => $this->company, 'role' => $sales])
        ->set('levels.receipts', 'full')
        ->call('save')
        ->assertHasNoErrors();

    expect($sales->fresh()->followsCatalogue())->toBeFalse();

    Livewire::actingAs($this->owner)
        ->test('pages::settings.roles.form', ['company' => $this->company, 'role' => $sales->fresh()])
        ->call('resetToDefault')
        ->assertHasNoErrors();

    expect($sales->fresh()->followsCatalogue())->toBeTrue();
});

it('shows each role\'s access in the permissions report and exports it', function () {
    $sales = ($this->roleId)(PredefinedRole::Sales);

    Livewire::actingAs($this->owner)
        ->test('pages::reports.role-permissions', ['company' => $this->company])
        ->set('roleIds', [$sales])
        ->assertSee('Partial: View, Create, Modify, Print')
        ->call('exportCsv')
        ->assertFileDownloaded('role-permissions.csv');
});
