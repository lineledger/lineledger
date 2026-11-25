<?php

namespace App\Support\Access;

use App\Contracts\CompanyAccess;
use App\Models\Company;

/**
 * A self-hosted installation's answer: every organization can change its
 * books, and there is nothing to announce.
 */
final class DefaultCompanyAccess implements CompanyAccess
{
    public function isReadOnly(Company $company): bool
    {
        return false;
    }

    public function readOnlyNotice(Company $company): ?string
    {
        return null;
    }
}
