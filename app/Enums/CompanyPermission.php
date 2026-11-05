<?php

namespace App\Enums;

use App\Support\Access\Requirement;

/**
 * Managing the organization and its people. Each maps to an administrative or
 * reserved activity a role can carry (deleting the organization stays with
 * the Owner).
 */
enum CompanyPermission: string
{
    case UpdateCompany = 'company:update';
    case DeleteCompany = 'company:delete';

    case AddMember = 'member:add';
    case UpdateMember = 'member:update';
    case RemoveMember = 'member:remove';

    case CreateInvitation = 'invitation:create';
    case CancelInvitation = 'invitation:cancel';

    /**
     * What a member needs to hold this permission, or null when only the Owner
     * may (deleting the organization).
     */
    public function requirement(): ?Requirement
    {
        return match ($this) {
            self::UpdateCompany => new Requirement(Activity::CompanySettings, PermissionAction::Modify),
            self::DeleteCompany => null,
            self::AddMember, self::UpdateMember => new Requirement(Activity::Users, PermissionAction::Modify),
            self::RemoveMember => new Requirement(Activity::Users, PermissionAction::Delete),
            self::CreateInvitation => new Requirement(Activity::Invitations, PermissionAction::Create),
            self::CancelInvitation => new Requirement(Activity::Invitations, PermissionAction::Delete),
        };
    }
}
