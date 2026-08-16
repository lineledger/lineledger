<?php

namespace App\Services\Migration;

use App\Models\Company;
use App\Models\DataMigrationRun;
use Carbon\CarbonImmutable;

/**
 * Per-step inputs supplied by the migration wizard.
 * Importers should pull all environmental state from this rather than
 * reaching into the request or session.
 */
final class ImportContext
{
    public function __construct(
        public Company $company,
        public DataMigrationRun $run,
        public CarbonImmutable $conversionDate,
        public bool $useOriginalDates = true,
        // Full-history (GL replay) inputs:
        public string $sourceFormat = 'csv',
        public bool $autoCreateAccounts = false,
        public bool $linkContactNames = true,
        public bool $reconstructDocuments = false,
        // Absolute path to a QuickBooks Account Listing CSV used to type accounts the
        // CSV replay auto-creates (CSV exports carry no per-account type). Optional.
        public ?string $accountTypesPath = null,
    ) {}
}
