<?php

namespace App\Actions\Companies\Members;

use App\Enums\CompanyRole;
use App\Enums\SecurityEvent;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use App\Notifications\Companies\CompanyInvitation as CompanyInvitationNotification;
use App\Services\Access\LegacyRoleConverter;
use App\Services\Audit\SecurityLogRecorder;
use Illuminate\Support\Facades\Notification;

/**
 * Invite someone by email to join a company with a role (and, for the Custom
 * role, the sections they may open). The invitation lasts three days and is
 * emailed to the address; accepting it is {@see AcceptInvitation}.
 *
 * Authorization (CompanyPolicy@inviteMember) and input validation are the
 * caller's job — the members page validates the form, a future API would its
 * request. This records the invitation, sends it and writes the security log.
 */
class InviteMember
{
    public const int EXPIRES_AFTER_DAYS = 3;

    public function __construct(
        private readonly SecurityLogRecorder $recorder,
        private readonly LegacyRoleConverter $roles,
    ) {}

    /**
     * @param  list<string>|null  $sections  section values, kept only for the Custom role
     */
    public function handle(Company $company, User $actor, string $email, CompanyRole $role, ?array $sections = null): CompanyInvitation
    {
        abort_if($role === CompanyRole::Owner, 403);

        $invitation = $company->invitations()->create([
            'email' => $email,
            'role' => $role,
            'sections' => $role->usesCustomSections() ? $sections ?? [] : null,
            'invited_by' => $actor->id,
            'expires_at' => now()->addDays(self::EXPIRES_AFTER_DAYS),
        ]);

        // The roles the invitation grants once accepted.
        $this->roles->assignLegacyToInvitation($invitation);

        Notification::route('mail', $invitation->email)
            ->notify(new CompanyInvitationNotification($invitation));

        $this->recorder->record(SecurityEvent::CompanyMemberInvited, $actor, metadata: [
            'company_id' => $company->id,
            'invitation_email' => $invitation->email,
            'role' => $role->value,
        ]);

        return $invitation;
    }
}
