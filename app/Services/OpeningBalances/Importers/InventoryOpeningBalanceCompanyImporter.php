<?php

namespace App\Services\OpeningBalances\Importers;

use App\Models\Company;
use App\Models\DataMigrationRun;
use App\Models\OpeningBalanceState;
use App\Services\Migration\ImportContext;
use App\Services\Migration\Importers\CompanyCsvImporter;
use App\Services\Migration\Importers\InventoryOpeningBalanceImporter;
use App\Services\Migration\ImportResult;

/**
 * Runs the QuickBooks wizard's inventory opening-balance importer standalone
 * from the workspace. The importers never read $ctx->run, so an UNSAVED
 * DataMigrationRun satisfies the ImportContext shape without touching the
 * one-per-company data_migration_runs row the wizard owns.
 */
class InventoryOpeningBalanceCompanyImporter implements CompanyCsvImporter
{
    public function __construct(protected InventoryOpeningBalanceImporter $importer) {}

    public function templateHeaders(): array
    {
        return $this->importer->templateHeaders();
    }

    public function templateExampleRows(): array
    {
        return $this->importer->templateExampleRows();
    }

    public function previewForCompany(string $csvPath, Company $company): ImportResult
    {
        return $this->importer->preview($csvPath, $this->context($company));
    }

    public function commitForCompany(string $csvPath, Company $company): ImportResult
    {
        return $this->importer->commit($csvPath, $this->context($company));
    }

    protected function context(Company $company): ImportContext
    {
        $state = OpeningBalanceState::for($company);
        $asOf = $state?->asOf() ?? OpeningBalanceState::defaultAsOfDate($company);

        return new ImportContext(
            company: $company,
            run: new DataMigrationRun(['conversion_date' => $asOf->toDateString()]),
            conversionDate: $asOf,
        );
    }
}
