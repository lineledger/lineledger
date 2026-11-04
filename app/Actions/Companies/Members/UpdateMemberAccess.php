<?php

namespace App\Actions\Companies\Members;

use App\Enums\CompanyRole;
use App\Enums\SecurityEvent;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Services\Audit\SecurityLogRecorder;
use App\Services\Security\AccessRevoker;

/**
 * Change a member's role (and, for the Custom role, their sections). The Owner
 * can't be changed here. An actual change is written to the security log, and
 * a reduction tears down access minted under the old level (sessions and the
 * API keys they created) through {@see AccessRevoker}.
 *
 * Authorization (CompanyPolicy@updateMember) and input validation are the
 * caller's job.
 */
class UpdateMemberAccess
{
    public function __construct(
        private readonly SecurityLogRecorder $recorder,
        private readonly AccessRevoker $revoker,
    ) {}

    /**
     * @param  list<string>|null  $sections  section values, kept only for the Custom role
     */
    public function handle(Company $company, User $actor, User $member, CompanyRole $role, ?array $sections = null): Membership
    {
        abort_if($role === CompanyRole::Owner, 403);

        $membership = $company->memberships()->where('user_id', $member->id)->firstOrFail();

        abort_if($membership->role === CompanyRole::Owner, 403);

        $previousRole = $membership->role;
        $previousSections = $membership->sections;
        $newSections = $role->usesCustomSections() ? $sections ?? [] : null;

        $membership->update(['role' => $role, 'sections' => $newSections]);

        // Log only an actual privilege change, not a no-op save of the form.
        if ($previousRole !== $role || $previousSections !== $newSections) {
            $this->recorder->record(SecurityEvent::CompanyMemberRoleChanged, $actor, metadata: [
                'company_id' => $company->id,
                'target_user_id' => $member->id,
                'from_role' => $previousRole->value,
                'to_role' => $role->value,
                'from_sections' => $previousSections,
                'to_sections' => $newSections,
            ]);
        }

        // On a privilege reduction — a lower role, or a narrower set of custom
        // sections — invalidate access provisioned under the old level so it
        // doesn't outlive the downgrade.
        $isDowngrade = $role->level() < $previousRole->level()
            || ($previousSections !== null && $newSections !== null
                && array_diff($previousSections, $newSections) !== []);

        if ($isDowngrade) {
            $this->revoker->revokeForDowngrade($member, $company);
        }

        return $membership;
    }
}
