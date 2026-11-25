<?php

namespace App\Console\Concerns;

use App\Contracts\CompanyAccess;
use App\Models\Company;

/**
 * Per-organization scheduled work leaves a read-only organization
 * ({@see CompanyAccess}) alone: it is skipped, never failed, so the rest of
 * the run goes on. The queued job each command dispatches checks again when
 * it runs, in case the organization became read-only in between.
 */
trait SkipsReadOnlyCompanies
{
    /**
     * Whether to leave the organization out of this run, saying so when it is.
     */
    protected function skipsReadOnly(Company $company): bool
    {
        if (! app(CompanyAccess::class)->isReadOnly($company)) {
            return false;
        }

        $this->line(sprintf('%s — skipped: read-only.', $company->slug));

        return true;
    }
}
