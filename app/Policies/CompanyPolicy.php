<?php

namespace App\Policies;

use App\Enums\CompanyPermission;
use App\Models\Company;
use App\Models\User;

class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Company $company): bool
    {
        return $user->belongsToCompany($company);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Company $company): bool
    {
        return $user->hasCompanyPermission($company, CompanyPermission::UpdateCompany);
    }

    public function addMember(User $user, Company $company): bool
    {
        return $user->hasCompanyPermission($company, CompanyPermission::AddMember);
    }

    public function updateMember(User $user, Company $company): bool
    {
        return $user->hasCompanyPermission($company, CompanyPermission::UpdateMember);
    }

    public function removeMember(User $user, Company $company): bool
    {
        return $user->hasCompanyPermission($company, CompanyPermission::RemoveMember);
    }

    public function inviteMember(User $user, Company $company): bool
    {
        return $user->hasCompanyPermission($company, CompanyPermission::CreateInvitation);
    }

    public function cancelInvitation(User $user, Company $company): bool
    {
        return $user->hasCompanyPermission($company, CompanyPermission::CancelInvitation);
    }

    public function delete(User $user, Company $company): bool
    {
        return ! $company->is_personal && $user->hasCompanyPermission($company, CompanyPermission::DeleteCompany);
    }
}
