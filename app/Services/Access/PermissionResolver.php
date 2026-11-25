<?php

namespace App\Services\Access;

use App\Contracts\CompanyAccess;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Membership;
use App\Models\MembershipRole;
use App\Models\Role;
use App\Models\User;
use App\Support\Access\LegacyRoleGrants;
use App\Support\Access\PermissionSet;

/**
 * Resolves what a user may do in a company, once per request.
 *
 * Bound scoped (flushed per request and per queued job), and flushed whenever
 * a membership changes, so a check never answers from access the member has
 * since lost. Looks memberships up without the company global scope: callers
 * ask about companies other than the bound one (report groups, the company
 * switcher, edit locks).
 *
 * In a read-only organization ({@see CompanyAccess}) every member, the Owner
 * included, resolves to view-only permissions. Every gate asks here — pages
 * (EnsureSectionAccess), Livewire methods (MethodPermissionGate), /api/v1
 * (EnsureApiPermission) and MCP tools — so that one change refuses every write.
 */
class PermissionResolver
{
    public function __construct(private readonly CompanyAccess $access) {}

    /** @var array<string, Membership|null> user:company => membership */
    private array $memberships = [];

    /** @var array<string, PermissionSet> user:company => permissions */
    private array $sets = [];

    public function membership(User $user, Company $company): ?Membership
    {
        $key = $this->key($user, $company);

        if (! array_key_exists($key, $this->memberships)) {
            $this->memberships[$key] = Membership::query()
                ->where('user_id', $user->getKey())
                ->where('company_id', $company->getKey())
                ->first();
        }

        return $this->memberships[$key];
    }

    public function for(User $user, Company $company): PermissionSet
    {
        $key = $this->key($user, $company);

        return $this->sets[$key] ??= $this->restrict($company, $this->resolve($this->membership($user, $company)));
    }

    /**
     * Everything a membership may do — for callers holding the membership
     * rather than the user (document folder visibility).
     */
    public function forMembership(Membership $membership): PermissionSet
    {
        $key = $membership->user_id.':'.$membership->company_id;

        $this->memberships[$key] ??= $membership;

        return $this->sets[$key] ??= $this->restrict($membership->company, $this->resolve($membership));
    }

    /**
     * The cap on a connection that acts for nobody in particular (an API key
     * with no recorded creator): null, meaning its own scopes are the only
     * limit, or view-only while the organization is read-only.
     */
    public function forUnattributed(Company $company): ?PermissionSet
    {
        return $this->access->isReadOnly($company) ? PermissionSet::owner()->viewOnly() : null;
    }

    /**
     * Forget everything resolved so far (a membership or role changed).
     */
    public function flush(): void
    {
        $this->memberships = [];
        $this->sets = [];
    }

    /**
     * Everything the membership may do: all of it for the Owner; otherwise
     * the most permissive combination of the roles it holds; a membership
     * that holds no role yet (not converted by `access:sync-roles`) keeps
     * what its original role and sections gave it.
     */
    protected function resolve(?Membership $membership): PermissionSet
    {
        if ($membership === null) {
            return PermissionSet::none();
        }

        if ($membership->role === CompanyRole::Owner) {
            return PermissionSet::owner();
        }

        $roles = Role::query()->withoutGlobalScopes()
            ->where('company_id', $membership->company_id)
            ->whereIn('id', MembershipRole::query()->withoutGlobalScopes()
                ->where('company_member_id', $membership->getKey())
                ->select('role_id'))
            ->with(['permissions' => fn ($query) => $query->withoutGlobalScopes()])
            ->get();

        if ($roles->isEmpty()) {
            return LegacyRoleGrants::for($membership->role, $membership->sectionValues());
        }

        return PermissionSet::union(...$roles->map(fn (Role $role): PermissionSet => $role->permissionSet())->all());
    }

    /**
     * View-only while the organization is read-only.
     */
    private function restrict(?Company $company, PermissionSet $permissions): PermissionSet
    {
        return $company !== null && $this->access->isReadOnly($company) ? $permissions->viewOnly() : $permissions;
    }

    private function key(User $user, Company $company): string
    {
        return $user->getKey().':'.$company->getKey();
    }
}
