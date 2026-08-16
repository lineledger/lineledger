<?php

namespace App\Enums;

enum CompanyPermission: string
{
    case UpdateCompany = 'company:update';
    case DeleteCompany = 'company:delete';

    case AddMember = 'member:add';
    case UpdateMember = 'member:update';
    case RemoveMember = 'member:remove';

    case CreateInvitation = 'invitation:create';
    case CancelInvitation = 'invitation:cancel';
}
