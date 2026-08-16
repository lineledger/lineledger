<?php

namespace App\Mcp\Resources;

use App\Models\Company;
use App\Support\Gifi\GifiCatalog;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class GifiCatalogResource extends Resource
{
    protected string $uri = 'lineledger://reference/gifi';

    protected string $mimeType = 'text/plain';

    protected string $title = 'GIFI catalog (CRA reference)';

    protected string $description = 'The curated CRA GIFI (General Index of Financial Information) catalog: 4-digit codes grouped by Schedule 100 (balance sheet) and Schedule 125 (income statement) sections, with their labels. Use this to explain or map GIFI codes on the chart of accounts. Static reference, read-only.';

    /**
     * GIFI is CRA-only: advertise the catalog only to companies that file a
     * GIFI-based return (T2, T5013, or T2125).
     */
    public function shouldRegister(Request $request): bool
    {
        $company = app()->bound('current_company') ? app('current_company') : null;

        return $company instanceof Company && $company->mapsGifiCodes();
    }

    public function handle(Request $request): Response
    {
        $bySection = [];
        foreach (GifiCatalog::all() as $entry) {
            $bySection[$entry['section_label']][] = $entry;
        }

        $lines = [
            'CRA GIFI catalog — Schedule 100 (balance sheet) & Schedule 125 (income statement).',
            'A curated subset of the GIFI index; these codes can be mapped to chart-of-accounts lines.',
            '',
        ];

        foreach ($bySection as $sectionLabel => $entries) {
            $statement = $entries[0]['statement']->label();
            $lines[] = "{$sectionLabel} — {$statement}";
            foreach ($entries as $entry) {
                $lines[] = "  {$entry['code']}  {$entry['label']}";
            }
            $lines[] = '';
        }

        return Response::text(rtrim(implode("\n", $lines)));
    }
}
