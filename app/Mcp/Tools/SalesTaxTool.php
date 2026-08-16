<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\Section;
use App\Mcp\Concerns\AnswersBusinessQuestions;
use App\Models\TaxAgency;
use App\Services\Reporting\ReportCalculator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class SalesTaxTool extends Tool
{
    use AnswersBusinessQuestions;

    protected string $title = 'Sales Tax Summary';

    protected string $description = 'Summarizes sales tax collected, paid, and net owed per tax agency for a period. All figures are in the company\'s home currency. This tool is read-only and never modifies data.';

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAbility('tax:read')) {
            return $denied;
        }

        if ($denied = $this->requireSection(Section::Reports)) {
            return $denied;
        }

        $period = $this->resolvePeriod($request);

        $agencies = TaxAgency::all();

        if ($agencies->isEmpty()) {
            return Response::text("No tax agencies are configured for {$this->company()->name}.");
        }

        $calculator = app(ReportCalculator::class);

        $totalCollected = 0;
        $totalPaid = 0;
        $totalNet = 0;

        $lines = [
            "Company: {$this->company()->name}",
            "Period: {$period['label']}",
            'Sales tax by agency (collected / paid / net owed):',
            '',
        ];

        foreach ($agencies as $agency) {
            $figures = $calculator->salesTaxForAgency($agency, $period['start'], $period['end']);

            $collected = (int) ($figures['collected'] ?? 0);
            $paid = (int) ($figures['paid'] ?? 0);
            $net = (int) ($figures['net'] ?? 0);

            $totalCollected += $collected;
            $totalPaid += $paid;
            $totalNet += $net;

            $lines[] = "- {$agency->name}: collected {$this->money($collected)}, paid {$this->money($paid)}, net owed {$this->money($net)}";
        }

        $lines[] = '';
        $lines[] = "Total collected: {$this->money($totalCollected)}";
        $lines[] = "Total paid: {$this->money($totalPaid)}";
        $lines[] = "Total net owed: {$this->money($totalNet)}";

        return Response::text(implode("\n", $lines));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'period' => $schema->string()->description('Named period: this_month, last_month, this_quarter, last_quarter, this_year, last_year, or ytd (default).'),
            'start' => $schema->string()->description('Explicit start date (ISO 8601, e.g. 2025-01-01). Overrides period when combined with end.'),
            'end' => $schema->string()->description('Explicit end date (ISO 8601, e.g. 2025-03-31). Overrides period when combined with start.'),
        ];
    }
}
