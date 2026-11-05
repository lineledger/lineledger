<?php

namespace App\Actions\Access;

use App\Models\Company;
use App\Models\Membership;
use App\Models\MembershipRole;
use App\Models\User;
use App\Services\Access\PermissionResolver;
use App\Services\Security\AccessRevoker;
use App\Support\Access\PermissionSet;
use Illuminate\Support\Collection as BaseCollection;

/**
 * Before-and-after bookkeeping for a change that affects everyone holding a
 * role: capture each holder's permissions first, then after the change sign
 * out (and revoke the API keys of) anyone who lost something. A holder whose
 * other roles still cover the change keeps their session.
 */
class RoleChangeEffects
{
    public function __construct(
        private readonly AccessRevoker $revoker,
        private readonly PermissionResolver $resolver,
    ) {}

    /**
     * The members holding the role, with what they may do right now.
     *
     * @return BaseCollection<int, array{user: User, before: PermissionSet}>
     */
    public function capture(Company $company, int $roleId): BaseCollection
    {
        $memberIds = MembershipRole::query()->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('role_id', $roleId)
            ->pluck('company_member_id');

        $userIds = Membership::query()->whereIn('id', $memberIds)->pluck('user_id');

        return User::query()
            ->whereIn('id', $userIds)
            ->get()
            ->map(fn (User $user): array => ['user' => $user, 'before' => $user->permissions($company)])
            ->values();
    }

    /**
     * Names of the captured holders who would lose something under the given
     * permissions (used to ask for confirmation before saving).
     *
     * @param  BaseCollection<int, array{user: User, before: PermissionSet}>  $holders
     * @param  callable(User): PermissionSet  $after
     * @return list<string>
     */
    public function losers(BaseCollection $holders, callable $after): array
    {
        return $holders
            ->filter(fn (array $holder): bool => $holder['before']->lostIn($after($holder['user'])) !== [])
            ->map(fn (array $holder): string => $holder['user']->name)
            ->values()
            ->all();
    }

    /**
     * After the change: revoke access for every captured holder who lost
     * something. Returns how many were signed out.
     *
     * @param  BaseCollection<int, array{user: User, before: PermissionSet}>  $holders
     */
    public function apply(Company $company, BaseCollection $holders): int
    {
        $this->resolver->flush();
        $signedOut = 0;

        foreach ($holders as $holder) {
            if ($holder['before']->lostIn($holder['user']->permissions($company)) !== []) {
                $this->revoker->revokeForDowngrade($holder['user'], $company);
                $signedOut++;
            }
        }

        return $signedOut;
    }
}
