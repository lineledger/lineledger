<?php

use App\Enums\Activity;
use App\Enums\CompanyBackupStatus;
use App\Enums\CompanyRestoreStatus;
use App\Enums\CompanyRole;
use App\Enums\PermissionAction;
use App\Enums\PredefinedRole;
use App\Models\Company;
use App\Models\CompanyBackup;
use App\Models\CompanyRestore;
use App\Models\MembershipRole;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Backup\CompanyExporter;
use App\Services\Restore\CompanyImporter;
use App\Support\Access\Grant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A restore carries the company's roles, their permissions and who holds them.
 * A member whose user doesn't exist on this instance falls onto the person
 * restoring and is skipped — and so are their role assignments.
 */
beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');

    $this->alice = User::factory()->create(['email' => 'alice@acme.test']);
    $this->bob = User::factory()->create(['email' => 'bob@acme.test']);
    $this->carol = User::factory()->create(['email' => 'carol@acme.test']);

    $this->companyA = Company::factory()->create(['name' => 'Acme Inc.']);
    app()->instance('current_company', $this->companyA);

    $this->companyA->memberships()->create(['user_id' => $this->alice->id, 'role' => CompanyRole::Owner]);
    $bob = $this->companyA->memberships()->create(['user_id' => $this->bob->id, 'role' => CompanyRole::Custom, 'sections' => []]);
    $carol = $this->companyA->memberships()->create(['user_id' => $this->carol->id, 'role' => CompanyRole::Custom, 'sections' => []]);

    $sales = Role::query()->where('system_key', PredefinedRole::Sales->value)->firstOrFail();
    $frontDesk = Role::create(['name' => 'Front desk']);
    RolePermission::create(['role_id' => $frontDesk->id, ...RolePermission::attributesFor(Activity::Customers, Grant::partial(PermissionAction::View))]);

    MembershipRole::create(['company_member_id' => $bob->id, 'role_id' => $sales->id]);
    MembershipRole::create(['company_member_id' => $bob->id, 'role_id' => $frontDesk->id]);
    MembershipRole::create(['company_member_id' => $carol->id, 'role_id' => $sales->id]);
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('restores roles, their permissions and members\' assignments', function () {
    $backup = CompanyBackup::create([
        'status' => CompanyBackupStatus::Pending,
        'requested_by_user_id' => $this->alice->id,
        'app_version' => config('version.app'),
        'schema_version' => config('version.schema'),
    ]);
    app(CompanyExporter::class)->export($backup);
    expect($backup->refresh()->status)->toBe(CompanyBackupStatus::Ready);

    $relative = 'restores/'.basename($backup->file_path);
    Storage::disk('local')->put($relative, Storage::disk('local')->get($backup->file_path));

    // Carol's account doesn't exist on the instance the bundle lands on.
    $this->carol->delete();
    app()->forgetInstance('current_company');

    $restore = CompanyRestore::create([
        'requested_by_user_id' => $this->alice->id,
        'status' => CompanyRestoreStatus::Pending,
        'file_path' => $relative,
        'file_size_bytes' => Storage::disk('local')->size($relative),
        'sha256' => hash_file('sha256', Storage::disk('local')->path($relative)),
    ]);
    app(CompanyImporter::class)->import($restore);
    expect($restore->refresh()->status)->toBe(CompanyRestoreStatus::Completed);

    $companyB = Company::findOrFail($restore->company_id);

    $roles = Role::query()->withoutGlobalScopes()->where('company_id', $companyB->id)->pluck('name');
    expect($roles)->toHaveCount(count(PredefinedRole::cases()) + 1)
        ->and($roles)->toContain('Front desk');

    $frontDesk = Role::query()->withoutGlobalScopes()->where('company_id', $companyB->id)->where('name', 'Front desk')->firstOrFail();
    expect(RolePermission::query()->withoutGlobalScopes()->where('role_id', $frontDesk->id)->value('activity'))->toBe(Activity::Customers);

    $bobB = $companyB->memberships()->where('user_id', $this->bob->id)->firstOrFail();
    $bobRoles = Role::query()->withoutGlobalScopes()
        ->whereIn('id', MembershipRole::query()->withoutGlobalScopes()->where('company_member_id', $bobB->id)->select('role_id'))
        ->pluck('name')->sort()->values()->all();

    expect($bobRoles)->toBe(['Front desk', 'Sales'])
        // Carol's assignment is gone with her membership; nothing points elsewhere.
        ->and(DB::table('company_member_roles')->where('company_id', $companyB->id)->count())->toBe(2)
        ->and(DB::table('company_member_roles')->where('company_id', $companyB->id)
            ->whereNotIn('company_member_id', $companyB->memberships()->select('id'))->count())->toBe(0)
        ->and($this->bob->permissions($companyB)->canView(Activity::Customers))->toBeTrue();
});
