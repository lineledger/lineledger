<?php

namespace App\Services\Migration\Importers;

use App\Models\Company;
use App\Services\Migration\ImportResult;

/**
 * A CSV importer that can run standalone against a company — outside the
 * QuickBooks migration wizard (e.g. the Chart of Accounts page, the settings
 * lists). preview/commit here take a Company directly instead of an
 * ImportContext.
 *
 * Importers implementing this also implement {@see Importer} for the wizard.
 */
interface CompanyCsvImporter
{
    /**
     * @return list<string>
     */
    public function templateHeaders(): array;

    /**
     * @return list<array<string, string>>
     */
    public function templateExampleRows(): array;

    /**
     * Parse + validate without persisting, so the caller can preview and let
     * the user fix the CSV before committing.
     */
    public function previewForCompany(string $csvPath, Company $company): ImportResult;

    /**
     * Persist inside a DB::transaction (any failure rolls back the whole import).
     * Callers should check ImportResult::hasErrors() after.
     */
    public function commitForCompany(string $csvPath, Company $company): ImportResult;
}
