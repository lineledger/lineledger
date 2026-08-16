<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\Migration\Importers\ChartOfAccountsImporter;
use App\Services\Reporting\CsvExporter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the blank Chart of Accounts import template (with a couple of example
 * rows) for the accounts page's "Download template" action. Reuses the same
 * column definition the importer validates against, so the template can't drift
 * from what the importer accepts.
 */
class ChartOfAccountsTemplateController
{
    public function __construct(protected CsvExporter $exporter, protected ChartOfAccountsImporter $importer) {}

    public function __invoke(Company $company): StreamedResponse
    {
        $headers = $this->importer->templateHeaders();

        $rows = array_map(
            fn (array $row) => array_map(
                fn (string $header) => $row[$header] ?? '',
                $headers,
            ),
            $this->importer->templateExampleRows(),
        );

        return $this->exporter->stream(
            filename: 'chart-of-accounts-import-template.csv',
            headers: $headers,
            rows: $rows,
        );
    }
}
