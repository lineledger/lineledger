<?php

namespace App\Actions\Companies\Members;

use App\Enums\SecurityEvent;
use App\Models\CompanyInvitation;
use App\Models\Membership;
use App\Models\User;
use App\Services\Access\LegacyRoleConverter;
use App\Services\Audit\SecurityLogRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Join a company through an invitation: the signed-in user's email must match
 * the invitation, which must be neither accepted nor expired. The invitation's
 * access becomes the membership's; someone already a member keeps the access
 * they have (accepting again never changes it). The user is switched to the
 * company they joined.
 */
class AcceptInvitation
{
    public function __construct(
        private readonly SecurityLogRecorder $recorder,
        private readonly LegacyRoleConverter $roles,
    ) {}

    /**
     * @throws ValidationException when the invitation can't be accepted by this user
     */
    public function handle(CompanyInvitation $invitation, User $user): Membership
    {
        $this->ensureAcceptable($invitation, $user);

        $company = $invitation->company;

        /** @var Membership $membership */
        $membership = DB::transaction(function () use ($invitation, $user, $company): Membership {
            $membership = $company->memberships()->firstOrCreate(
                ['user_id' => $user->id],
                ['role' => $invitation->role, 'sections' => $invitation->sections],
            );

            // A new member takes the roles the invitation grants.
            if ($membership->wasRecentlyCreated) {
                $this->grantInvitationRoles($invitation, $membership);
            }

            $invitation->update(['accepted_at' => now()]);

            $user->switchCompany($company);

            return $membership;
        });

        // Only a genuinely new membership is a provisioning event; re-accepting
        // an already-joined invitation should not log a spurious "joined".
        if ($membership->wasRecentlyCreated) {
            $this->recorder->record(SecurityEvent::CompanyMemberJoined, $user, metadata: [
                'company_id' => $company->id,
                'role' => $invitation->role->value,
            ]);
        }

        return $membership;
    }

    private function grantInvitationRoles(CompanyInvitation $invitation, Membership $membership): void
    {
        $roleIds = DB::table('company_invitation_roles')
            ->where('company_invitation_id', $invitation->getKey())
            ->pluck('role_id');

        if ($roleIds->isEmpty()) {
            $this->roles->assignLegacy($membership);

            return;
        }

        $now = now();
        DB::table('company_member_roles')->insert($roleIds->map(fn ($roleId): array => [
            'company_id' => $membership->company_id,
            'company_member_id' => $membership->getKey(),
            'role_id' => (int) $roleId,
            'assigned_by_user_id' => $invitation->invited_by,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());
    }

    private function ensureAcceptable(CompanyInvitation $invitation, User $user): void
    {
        $problem = match (true) {
            $invitation->isAccepted() => __('This invitation has already been accepted.'),
            $invitation->isExpired() => __('This invitation has expired.'),
            Str::lower($invitation->email) !== Str::lower($user->email) => __('This invitation was sent to a different email address.'),
            default => null,
        };

        if ($problem !== null) {
            throw ValidationException::withMessages(['invitation' => [$problem]]);
        }
    }
}
