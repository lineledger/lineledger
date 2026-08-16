<?php

namespace App\Actions\Companies;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Soft-delete a company, from either the owner-facing danger zone or the site
 * admin portal. Authorization is the caller's job — the tenant modal runs
 * CompanyPolicy@delete, the admin portal requires a site admin.
 *
 * Membership and invitation rows are deliberately left intact. Soft-deleted
 * companies are already unreachable three times over — EnsureCompanyMembership
 * resolves the slug through the SoftDeletes scope, every switcher/picker reads
 * $user->companies() (likewise scoped), and SetCompanyUrlDefaults reads the
 * scoped currentCompany relation — so keeping the pivot rows leaks nothing, and
 * it is what makes {@see RestoreCompany} lossless. Destroying them (as this
 * flow used to) would hand back an ownerless company nobody could reach.
 */
class DeleteCompany
{
    /**
     * @param  User|null  $actor  The member performing the delete, when there is
     *                            one. They are moved to their next company by
     *                            name; everyone else falls back to their
     *                            personal company. The admin portal passes null.
     */
    public function handle(Company $company, ?User $actor = null): void
    {
        $actorFallback = $actor?->isCurrentCompany($company)
            ? $actor->fallbackCompany($company)
            : null;

        DB::transaction(function () use ($company, $actor) {
            User::query()
                ->where('current_company_id', $company->id)
                ->when($actor, fn ($query) => $query->where('id', '!=', $actor->id))
                ->each(function (User $affected) {
                    $personal = $affected->personalCompany();

                    if ($personal === null) {
                        // Nothing left to point at — clear the pointer rather
                        // than leave it aimed at a deleted company.
                        $affected->forceFill(['current_company_id' => null])->save();

                        return;
                    }

                    $affected->switchCompany($personal);
                });

            $company->delete();
        });

        if ($actor !== null && $actorFallback !== null) {
            $actor->switchCompany($actorFallback);
        }
    }
}
