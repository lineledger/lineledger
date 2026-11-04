<?php

namespace App\Models;

use App\Concerns\AuthorizesReportGroup;
use App\Enums\Section;
use Database\Factories\ReportGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A user-owned, cross-tenant grouping of companies whose reports are combined.
 *
 * Deliberately does NOT use the BelongsToCompany trait — a group spans companies,
 * so the per-tenant global scope must never touch it.
 */
#[Fillable(['user_id', 'name', 'currency_code'])]
class ReportGroup extends Model
{
    /** @use HasFactory<ReportGroupFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<Company, $this>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'report_group_companies')
            ->withTimestamps();
    }

    /**
     * @return HasMany<ReportGroupLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ReportGroupLine::class);
    }

    /**
     * @return HasMany<ReportGroupAccountMap, $this>
     */
    public function accountMaps(): HasMany
    {
        return $this->hasMany(ReportGroupAccountMap::class);
    }

    /**
     * @return HasMany<ReportGroupSection, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(ReportGroupSection::class);
    }

    /**
     * @return Collection<int, int>
     */
    public function companyIds(): Collection
    {
        return $this->companies()->pluck('companies.id');
    }

    /**
     * Whether the given user may view/run this group: they must be able to open
     * Reports in every member company (shared with co-members). A combined
     * statement is a financial report, so a member walled off from Reports in
     * any one of the companies may not read it here either. Soft-deleted
     * companies are not members (the SoftDeletes scope drops them, and the
     * calculator skips them too). Re-checked on every Livewire request by
     * {@see AuthorizesReportGroup}.
     */
    public function isVisibleTo(User $user): bool
    {
        $companies = $this->companies()->get();

        return $companies->isNotEmpty()
            && $companies->every(fn (Company $company) => self::canReportOn($user, $company));
    }

    /**
     * Whether a company's ledger may go into a group this user views or builds:
     * they belong to it, and its Reports section is both enabled (site-admin
     * kill switch) and open to them — the pair EnsureSectionEnabled and
     * EnsureSectionAccess check on the company's own report pages.
     */
    public static function canReportOn(User $user, Company $company): bool
    {
        return $user->belongsToCompany($company)
            && $company->sectionEnabled(Section::Reports)
            && $user->canAccessSection($company, Section::Reports);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'currency_code' => 'string',
        ];
    }
}
