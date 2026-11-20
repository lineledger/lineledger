<?php

namespace App\Actions\Companies;

use App\Models\Company;

/**
 * Undo a soft delete from the site admin portal.
 *
 * Lossless for anything deleted by {@see DeleteCompany}: the membership rows
 * survived, so the company comes back with its owner and roles intact and is
 * immediately reachable again. Companies deleted before that behaviour changed
 * had their memberships hard-deleted and come back ownerless — the admin page
 * warns when that is the case.
 *
 * Restoring also cancels any purge an owner's delete scheduled, so the
 * company cannot be erased later on a stale date.
 */
class RestoreCompany
{
    public function handle(Company $company): void
    {
        if (! $company->trashed()) {
            return;
        }

        // restore() saves the model, so the cleared purge date goes with it.
        $company->forceFill(['purge_at' => null]);
        $company->restore();
    }
}
