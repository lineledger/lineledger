<?php

namespace App\Actions\Access;

use App\Enums\CompanyRole;
use App\Enums\SecurityEvent;
use App\Models\Company;
use App\Models\Membership;
use App\Models\MembershipRole;
use App\Models\Role;
use App\Models\User;
use App\Services\Access\PermissionResolver;
use App\Services\Audit\SecurityLogRecorder;
use App\Services\Security\AccessRevoker;
use App\Support\Access\PermissionSet;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Set the roles a member holds (one or more; their access is the most
 * permissive combination). The Owner holds no roles and can't be changed
 * here. Someone other than the Owner may only hand out permissions they hold
 * themselves, and may not change their own roles. Losing any permission
 * signs the member out and revokes the API keys they made.
 *
 * Authorization (Users: Modify) is the caller's job.
 */
class AssignMemberRoles
{
    public function __construct(
        private readonly SecurityLogRecorder $recorder,
        private readonly AccessRevoker $revoker,
        private readonly PermissionResolver $resolver,
    ) {}

    /**
     * @param  list<int>  $roleIds
     */
    public function handle(Company $company, User $actor, User $member, array $roleIds): Membership
    {
        $membership = Membership::query()->where('company_id', $company->id)->where('user_id', $member->id)->firstOrFail();

        abort_if($membership->role === CompanyRole::Owner, 403);

        $mine = $actor->permissions($company);

        if (! $mine->isOwner() && $member->is($actor)) {
            throw ValidationException::withMessages(['roles' => __('You can’t change your own roles. Ask the owner or someone else who manages users.')]);
        }

        $roles = self::rolesIn($company, $roleIds);
        $proposed = PermissionSet::union(...$roles->map(fn (Role $role): PermissionSet => $role->permissionSet())->all());

        if (! $mine->isOwner() && ! $proposed->isSubsetOf($mine)) {
            throw ValidationException::withMessages(['roles' => __('You can only give someone permissions you hold yourself.')]);
        }

        $before = $member->permissions($company);
        $fromRoles = $this->roleNames($membership);

        DB::transaction(function () use ($company, $actor, $membership, $roles): void {
            MembershipRole::query()->withoutGlobalScopes()->where('company_member_id', $membership->id)->delete();

            $now = now();
            MembershipRole::query()->insert($roles->map(fn (Role $role): array => [
                'company_id' => $company->id,
                'company_member_id' => $membership->id,
                'role_id' => $role->id,
                'assigned_by_user_id' => $actor->id,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());

            // The member is managed by roles now; their original role and
            // section ticks no longer apply.
            $membership->update(['role' => CompanyRole::Custom, 'sections' => null]);
        });

        $this->resolver->flush();
        $after = $member->permissions($company);
        $toRoles = $this->roleNames($membership);

        if ($fromRoles !== $toRoles) {
            $this->recorder->record(SecurityEvent::CompanyMemberRoleChanged, $actor, metadata: [
                'company_id' => $company->id,
                'target_user_id' => $member->id,
                'from_roles' => $fromRoles,
                'to_roles' => $toRoles,
                'lost' => array_map('strval', $before->lostIn($after)),
                'gained' => array_map('strval', $after->lostIn($before)),
                'escalated' => $after->lostIn($before) !== [],
            ]);
        }

        if ($before->lostIn($after) !== []) {
            $this->revoker->revokeForDowngrade($member, $company);
        }

        return $membership->refresh();
    }

    /**
     * The company's roles with these ids; at least one, all of them real.
     *
     * @param  list<int>  $roleIds
     * @return Collection<int, Role>
     */
    public static function rolesIn(Company $company, array $roleIds): Collection
    {
        $roleIds = array_values(array_unique(array_map('intval', $roleIds)));

        $roles = Role::query()->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereIn('id', $roleIds)
            ->with(['permissions' => fn ($query) => $query->withoutGlobalScopes()])
            ->get();

        if ($roleIds === [] || $roles->count() !== count($roleIds)) {
            throw ValidationException::withMessages(['roles' => __('Choose at least one of this organization’s roles.')]);
        }

        return $roles;
    }

    /**
     * @return list<string>
     */
    private function roleNames(Membership $membership): array
    {
        return Role::query()->withoutGlobalScopes()
            ->whereIn('id', MembershipRole::query()->withoutGlobalScopes()->where('company_member_id', $membership->id)->select('role_id'))
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }
}
