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
 */
class RestoreCompany
{
    public function handle(Company $company): void
    {
        if (! $company->trashed()) {
            return;
        }

        $company->restore();
    }
}
