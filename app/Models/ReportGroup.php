<?php

namespace App\Models;

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
     * Whether the given user may view/run this group: they must belong to every
     * member company (shared with co-members). Re-checked at render time.
     */
    public function isVisibleTo(User $user): bool
    {
        $companies = $this->companies()->get();

        return $companies->isNotEmpty()
            && $companies->every(fn (Company $company) => $user->belongsToCompany($company));
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
