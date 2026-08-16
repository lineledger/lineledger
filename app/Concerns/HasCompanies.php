<?php

namespace App\Concerns;

use App\Enums\CompanyPermission;
use App\Enums\CompanyRole;
use App\Enums\Section;
use App\Models\Company;
use App\Models\Membership;
use App\Models\ReportGroup;
use App\Support\CompanyPermissions;
use App\Support\UserCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

trait HasCompanies
{
    /**
     * @return BelongsToMany<Company, $this>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_members', 'user_id', 'company_id')
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /**
     * @return HasManyThrough<Company, Membership, $this>
     */
    public function ownedCompanies(): HasManyThrough
    {
        return $this->hasManyThrough(
            Company::class,
            Membership::class,
            'user_id',
            'id',
            'id',
            'company_id',
        )->where('company_members.role', CompanyRole::Owner->value);
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function companyMemberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'user_id');
    }

    /**
     * Combined-report groups created by this user.
     *
     * @return HasMany<ReportGroup, $this>
     */
    public function reportGroups(): HasMany
    {
        return $this->hasMany(ReportGroup::class);
    }

    /**
     * The user's most-recently-used company (used as a redirect hint, not for scoping).
     *
     * @return BelongsTo<Company, $this>
     */
    public function currentCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'current_company_id');
    }

    public function personalCompany(): ?Company
    {
        return $this->companies()
            ->where('is_personal', true)
            ->first();
    }

    /**
     * Persist the given company as the user's "current" company (most-recently-used hint).
     */
    public function switchCompany(Company $company): bool
    {
        if (! $this->belongsToCompany($company)) {
            return false;
        }

        // current_company_id is not mass-assignable (tenant pointer); set it directly.
        $this->forceFill(['current_company_id' => $company->id])->save();
        $this->setRelation('currentCompany', $company);

        URL::defaults(['company' => $company->slug]);

        return true;
    }

    public function belongsToCompany(Company $company): bool
    {
        return $this->companies()->where('companies.id', $company->id)->exists();
    }

    public function isCurrentCompany(Company $company): bool
    {
        return $this->current_company_id === $company->id;
    }

    public function ownsCompany(Company $company): bool
    {
        return $this->companyRole($company) === CompanyRole::Owner;
    }

    public function companyMembership(Company $company): ?Membership
    {
        return $this->companyMemberships()
            ->where('company_id', $company->id)
            ->first();
    }

    public function companyRole(Company $company): ?CompanyRole
    {
        return $this->companyMembership($company)?->role;
    }

    public function canAccessSection(Company $company, Section $section): bool
    {
        return $this->companyMembership($company)?->canAccessSection($section) ?? false;
    }

    /**
     * Alphabetical (case-insensitive) by the name shown in the UI.
     *
     * @return Collection<int, UserCompany>
     */
    public function toUserCompanies(bool $includeCurrent = false): Collection
    {
        return $this->companies()
            ->get()
            ->map(fn (Company $company) => ! $includeCurrent && $this->isCurrentCompany($company) ? null : $this->toUserCompany($company))
            ->filter()
            ->sortBy(fn (UserCompany $company) => Str::lower($company->displayName), SORT_NATURAL)
            ->values();
    }

    public function toUserCompany(Company $company): UserCompany
    {
        $role = $this->companyRole($company);

        return new UserCompany(
            id: $company->id,
            name: $company->name,
            displayName: $company->brandDisplayName(),
            slug: $company->slug,
            isPersonal: $company->is_personal,
            role: $role?->value,
            roleLabel: $role?->label(),
            isCurrent: $this->isCurrentCompany($company),
        );
    }

    public function toCompanyPermissions(Company $company): CompanyPermissions
    {
        $role = $this->companyRole($company);

        return new CompanyPermissions(
            canUpdateCompany: $role?->hasPermission(CompanyPermission::UpdateCompany) ?? false,
            canDeleteCompany: $role?->hasPermission(CompanyPermission::DeleteCompany) ?? false,
            canAddMember: $role?->hasPermission(CompanyPermission::AddMember) ?? false,
            canUpdateMember: $role?->hasPermission(CompanyPermission::UpdateMember) ?? false,
            canRemoveMember: $role?->hasPermission(CompanyPermission::RemoveMember) ?? false,
            canCreateInvitation: $role?->hasPermission(CompanyPermission::CreateInvitation) ?? false,
            canCancelInvitation: $role?->hasPermission(CompanyPermission::CancelInvitation) ?? false,
        );
    }

    public function fallbackCompany(?Company $excluding = null): ?Company
    {
        return $this->companies()
            ->when($excluding, fn ($query) => $query->where('companies.id', '!=', $excluding->id))
            ->orderByRaw('LOWER(companies.name)')
            ->first();
    }

    public function hasCompanyPermission(Company $company, CompanyPermission $permission): bool
    {
        return $this->companyRole($company)?->hasPermission($permission) ?? false;
    }
}
