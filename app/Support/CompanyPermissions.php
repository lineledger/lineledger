<?php

namespace App\Support;

readonly class CompanyPermissions
{
    public function __construct(
        public bool $canUpdateCompany,
        public bool $canDeleteCompany,
        public bool $canAddMember,
        public bool $canUpdateMember,
        public bool $canRemoveMember,
        public bool $canCreateInvitation,
        public bool $canCancelInvitation,
    ) {
        //
    }
}
