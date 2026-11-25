<?php

namespace Tests\Support\Access;

use App\Contracts\CompanyAccess;
use App\Models\Company;

/**
 * A CompanyAccess for tests: the organizations listed are read-only and carry
 * the notice given, as a hosting layer would make them.
 */
final class ReadOnlyCompanies implements CompanyAccess
{
    /** @var array<int, string|null> company id => notice */
    private array $readOnly = [];

    public function add(Company $company, ?string $notice = null): void
    {
        $this->readOnly[(int) $company->getKey()] = $notice;
    }

    public function isReadOnly(Company $company): bool
    {
        return array_key_exists((int) $company->getKey(), $this->readOnly);
    }

    public function readOnlyNotice(Company $company): ?string
    {
        return $this->readOnly[(int) $company->getKey()] ?? null;
    }
}
