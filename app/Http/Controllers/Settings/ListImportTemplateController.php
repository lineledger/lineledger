<?php

namespace App\Http\Controllers\Settings;

use App\Models\Company;
use App\Services\Migration\Importers\CompanyCsvImporter;
use App\Services\Migration\Importers\ItemCategoriesImporter;
use App\Services\Migration\Importers\ItemsImporter;
use App\Services\Reporting\CsvExporter;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Streams the blank CSV import template for a settings list (Items, Item
 * categories) — the "Download template" action on those pages. Reuses the same
 * column definition the importer validates against, so the template can't drift
 * from what the importer accepts.
 */
class ListImportTemplateController
{
    public function __construct(protected CsvExporter $exporter) {}

    public function __invoke(Company $company, string $list): StreamedResponse
    {
        $importer = $this->resolveImporter($list);

        $headers = $importer->templateHeaders();

        $rows = array_map(
            fn (array $row) => array_map(
                fn (string $header) => $row[$header] ?? '',
                $headers,
            ),
            $importer->templateExampleRows(),
        );

        return $this->exporter->stream(
            filename: "{$list}-import-template.csv",
            headers: $headers,
            rows: $rows,
        );
    }

    protected function resolveImporter(string $list): CompanyCsvImporter
    {
        return match ($list) {
            'items' => app(ItemsImporter::class),
            'item-categories' => app(ItemCategoriesImporter::class),
            default => throw new NotFoundHttpException("No template for list '{$list}'."),
        };
    }
}
