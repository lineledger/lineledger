<?php

namespace App\Http\Controllers\Migration;

use App\Models\Company;
use App\Services\Migration\Importers\ChartOfAccountsImporter;
use App\Services\Migration\Importers\CustomersImporter;
use App\Services\Migration\Importers\FixedAssetsImporter;
use App\Services\Migration\Importers\GeneralLedgerReplayImporter;
use App\Services\Migration\Importers\Importer;
use App\Services\Migration\Importers\InventoryOpeningBalanceImporter;
use App\Services\Migration\Importers\ItemsImporter;
use App\Services\Migration\Importers\OpenBillsImporter;
use App\Services\Migration\Importers\OpenInvoicesImporter;
use App\Services\Migration\Importers\TrialBalanceImporter;
use App\Services\Migration\Importers\VendorsImporter;
use App\Services\Reporting\CsvExporter;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MigrationTemplateController
{
    public function __construct(protected CsvExporter $exporter) {}

    public function __invoke(Company $company, string $step): StreamedResponse
    {
        $importer = $this->resolveImporter($step);

        $headers = $importer->templateHeaders();
        $exampleRows = $importer->templateExampleRows();

        $rows = array_map(
            fn (array $row) => array_map(
                fn (string $header) => $row[$header] ?? '',
                $headers,
            ),
            $exampleRows,
        );

        return $this->exporter->stream(
            filename: "qb-import-{$step}-template.csv",
            headers: $headers,
            rows: $rows,
        );
    }

    protected function resolveImporter(string $step): Importer
    {
        return match ($step) {
            'chart_of_accounts' => app(ChartOfAccountsImporter::class),
            'customers' => app(CustomersImporter::class),
            'vendors' => app(VendorsImporter::class),
            'items' => app(ItemsImporter::class),
            'open_invoices' => app(OpenInvoicesImporter::class),
            'open_bills' => app(OpenBillsImporter::class),
            'general_ledger' => app(GeneralLedgerReplayImporter::class),
            'inventory_opening_balance' => app(InventoryOpeningBalanceImporter::class),
            'fixed_assets' => app(FixedAssetsImporter::class),
            'trial_balance' => app(TrialBalanceImporter::class),
            default => throw new NotFoundHttpException("No template for step '{$step}'."),
        };
    }
}
