<?php

namespace App\Http\Controllers\OpeningBalances;

use App\Models\Company;
use App\Services\Migration\Importers\CompanyCsvImporter;
use App\Services\OpeningBalances\Importers\DepositsInTransitCsvImporter;
use App\Services\OpeningBalances\Importers\FixedAssetsCompanyImporter;
use App\Services\OpeningBalances\Importers\InventoryOpeningBalanceCompanyImporter;
use App\Services\OpeningBalances\Importers\OpeningCustomerBalancesCsvImporter;
use App\Services\OpeningBalances\Importers\OpeningTrialBalanceCsvImporter;
use App\Services\OpeningBalances\Importers\OpeningVendorBalancesCsvImporter;
use App\Services\OpeningBalances\Importers\OutstandingChequesCsvImporter;
use App\Services\Reporting\CsvExporter;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class OpeningBalanceTemplateController
{
    public function __construct(protected CsvExporter $exporter) {}

    public function __invoke(Company $company, string $step): StreamedResponse
    {
        $importer = $this->resolveImporter($step);

        $headers = $importer->templateHeaders();

        $rows = array_map(
            fn (array $row) => array_map(
                fn (string $header) => $row[$header] ?? '',
                $headers,
            ),
            $importer->templateExampleRows(),
        );

        return $this->exporter->stream(
            filename: "opening-balances-{$step}-template.csv",
            headers: $headers,
            rows: $rows,
        );
    }

    protected function resolveImporter(string $step): CompanyCsvImporter
    {
        return match ($step) {
            'trial_balance' => app(OpeningTrialBalanceCsvImporter::class),
            'customer_balances' => app(OpeningCustomerBalancesCsvImporter::class),
            'vendor_balances' => app(OpeningVendorBalancesCsvImporter::class),
            'outstanding_cheques' => app(OutstandingChequesCsvImporter::class),
            'deposits_in_transit' => app(DepositsInTransitCsvImporter::class),
            'inventory' => app(InventoryOpeningBalanceCompanyImporter::class),
            'fixed_assets' => app(FixedAssetsCompanyImporter::class),
            default => throw new NotFoundHttpException("No template for step '{$step}'."),
        };
    }
}
