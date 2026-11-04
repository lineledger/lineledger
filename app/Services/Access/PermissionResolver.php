<?php

namespace App\Services\Access;

use App\Models\Company;
use App\Models\Membership;
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
 */
class PermissionResolver
{
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

        return $this->sets[$key] ??= $this->resolve($this->membership($user, $company));
    }

    /**
     * Forget everything resolved so far (a membership or role changed).
     */
    public function flush(): void
    {
        $this->memberships = [];
        $this->sets = [];
    }

    protected function resolve(?Membership $membership): PermissionSet
    {
        if ($membership === null) {
            return PermissionSet::none();
        }

        return LegacyRoleGrants::for($membership->role, $membership->sectionValues());
    }

    private function key(User $user, Company $company): string
    {
        return $user->getKey().':'.$company->getKey();
    }
}
