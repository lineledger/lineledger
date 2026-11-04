<?php

use App\Enums\CompanyRole;
use App\Enums\Section;
use App\Models\Company;
use App\Models\MembershipRole;
use App\Models\Role;
use App\Models\User;
use App\Services\Access\PermissionResolver;
use App\Support\Access\LegacyRoleGrants;
use Illuminate\Support\Facades\DB;

/**
 * Converting the original roles into role assignments changes nobody's
 * access: Admin → Full Access, Accountant → Accountant, each distinct set of
 * Custom sections → one generated role; the Owner holds none.
 */
beforeEach(function () {
    $this->company = Company::factory()->create();

    $this->member = function (CompanyRole $role, ?array $sections = null): User {
        $user = User::factory()->create();
        $this->company->memberships()->create(['user_id' => $user->id, 'role' => $role, 'sections' => $sections]);

        return $user;
    };

    $this->rolesOf = fn (User $user): array => Role::query()->withoutGlobalScopes()
        ->whereIn('id', MembershipRole::query()->withoutGlobalScopes()
            ->where('company_member_id', $user->companyMembership($this->company)->id)
            ->select('role_id'))
        ->orderBy('name')
        ->pluck('name')
        ->all();
});

it('converts each original role into the matching role', function () {
    $owner = ($this->member)(CompanyRole::Owner);
    $admin = ($this->member)(CompanyRole::Admin);
    $accountant = ($this->member)(CompanyRole::Accountant);
    $custom = ($this->member)(CompanyRole::Custom, ['reports', 'customers']);
    $sameTicks = ($this->member)(CompanyRole::Custom, ['customers', 'reports']);
    $none = ($this->member)(CompanyRole::Custom, []);

    $this->artisan('access:sync-roles')->assertSuccessful();

    expect(($this->rolesOf)($owner))->toBe([])
        ->and(($this->rolesOf)($admin))->toBe(['Full Access'])
        ->and(($this->rolesOf)($accountant))->toBe(['Accountant'])
        ->and(($this->rolesOf)($custom))->toBe(['Custom: Customers, Reports'])
        ->and(($this->rolesOf)($sameTicks))->toBe(['Custom: Customers, Reports'])
        ->and(($this->rolesOf)($none))->toBe(['Custom: no sections'])
        ->and(Role::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->whereNotNull('legacy_key')->count())->toBe(2);
});

it('leaves every member with exactly the access they had', function (CompanyRole $role, ?array $sections) {
    $user = ($this->member)($role, $sections);

    $before = $user->permissions($this->company)->grants();
    $this->artisan('access:sync-roles')->assertSuccessful();
    app(PermissionResolver::class)->flush();

    expect(MembershipRole::query()->withoutGlobalScopes()->count() > 0 || $role === CompanyRole::Owner)->toBeTrue()
        ->and($user->permissions($this->company)->grants())->toEqual($before)
        ->and($before)->toEqual(LegacyRoleGrants::for($role, $sections)->grants());
})->with([
    'owner' => [CompanyRole::Owner, null],
    'admin' => [CompanyRole::Admin, null],
    'accountant' => [CompanyRole::Accountant, null],
    'custom, settings and banking' => [CompanyRole::Custom, [Section::Settings->value, Section::Banking->value]],
    'custom, payroll' => [CompanyRole::Custom, [Section::Payroll->value]],
    'custom, nothing' => [CompanyRole::Custom, []],
]);

it('converts invitations too', function () {
    $invitation = $this->company->invitations()->create([
        'email' => 'ana@example.com', 'role' => CompanyRole::Custom, 'sections' => ['inbox'],
        'invited_by' => User::factory()->create()->id, 'expires_at' => now()->addDay(),
    ]);

    $this->artisan('access:sync-roles')->assertSuccessful();

    expect(DB::table('company_invitation_roles')->where('company_invitation_id', $invitation->id)->count())->toBe(1);
});

it('is idempotent and writes nothing on a dry run', function () {
    ($this->member)(CompanyRole::Accountant);

    $this->artisan('access:sync-roles', ['--dry-run' => true])->assertSuccessful();
    expect(MembershipRole::query()->withoutGlobalScopes()->count())->toBe(0);

    $this->artisan('access:sync-roles')->assertSuccessful();
    $this->artisan('access:sync-roles')->assertSuccessful();
    expect(MembershipRole::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('converts one company when named', function () {
    $other = Company::factory()->create();
    $user = User::factory()->create();
    $other->memberships()->create(['user_id' => $user->id, 'role' => CompanyRole::Accountant]);
    ($this->member)(CompanyRole::Accountant);

    $this->artisan('access:sync-roles', ['company' => $other->slug])->assertSuccessful();

    expect(MembershipRole::query()->withoutGlobalScopes()->pluck('company_id')->all())->toBe([$other->id]);
});

it('runs as part of app:upgrade', function () {
    ($this->member)(CompanyRole::Accountant);

    $this->artisan('app:upgrade')->assertSuccessful();

    expect(MembershipRole::query()->withoutGlobalScopes()->count())->toBe(1);
});
