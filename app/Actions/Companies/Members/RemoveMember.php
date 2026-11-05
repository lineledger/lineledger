<?php

namespace App\Actions\Companies\Members;

use App\Enums\CompanyRole;
use App\Enums\SecurityEvent;
use App\Models\Company;
use App\Models\User;
use App\Services\Audit\SecurityLogRecorder;
use App\Services\Security\AccessRevoker;

/**
 * Remove someone from a company. The Owner can't be removed. Their access is
 * torn down at once through {@see AccessRevoker} (sessions, the company's API
 * keys they created, OAuth tokens, their report schedules there) and they are
 * moved off the company if it was their current one.
 *
 * Authorization (CompanyPolicy@removeMember) is the caller's job.
 */
class RemoveMember
{
    public function __construct(
        private readonly SecurityLogRecorder $recorder,
        private readonly AccessRevoker $revoker,
    ) {}

    public function handle(Company $company, User $actor, User $member): void
    {
        $membership = $company->memberships()->where('user_id', $member->id)->firstOrFail();

        abort_if($membership->role === CompanyRole::Owner, 403);

        $membership->delete();

        $this->revoker->revokeForRemoval($member, $company);

        $this->recorder->record(SecurityEvent::CompanyMemberRemoved, $actor, metadata: [
            'company_id' => $company->id,
            'removed_user_id' => $member->id,
            'removed_user_email' => $member->email,
        ]);
    }
}
