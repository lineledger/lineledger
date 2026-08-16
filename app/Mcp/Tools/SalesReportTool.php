<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\Section;
use App\Mcp\Concerns\AnswersBusinessQuestions;
use App\Services\Reporting\SalesPurchaseReportBuilder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class SalesReportTool extends Tool
{
    use AnswersBusinessQuestions;

    protected string $title = 'Sales report';

    protected string $description = 'Read-only sales report for a period: sales grouped by customer or by item, with the top results ranked by revenue. Use the "period" argument (this_month, last_month, this_quarter, last_quarter, this_year, last_year, ytd) or explicit "start"/"end" ISO dates. All figures are in the company\'s home currency. This tool never modifies any data.';

    /**
     * Map the friendly group_by argument to the builder's dimension key.
     */
    private const GROUP_BY_MAP = [
        'customer' => 'contact',
        'item' => 'item',
    ];

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAbility('sales:read')) {
            return $denied;
        }

        if ($denied = $this->requireSection(Section::Reports)) {
            return $denied;
        }

        $groupBy = strtolower((string) ($request->get('group_by') ?? 'customer'));

        if (! array_key_exists($groupBy, self::GROUP_BY_MAP)) {
            $groupBy = 'customer';
        }

        $limit = (int) ($request->get('limit') ?? 10);

        if ($limit < 1) {
            $limit = 10;
        }

        $period = $this->resolvePeriod($request);

        $builder = app(SalesPurchaseReportBuilder::class);

        $rows = $builder->salesByDimension(
            $this->company(),
            $period['start'],
            $period['end'],
            self::GROUP_BY_MAP[$groupBy],
        );

        $totalCents = (int) $rows->sum('amount_cents');
        $topRows = $rows->take($limit);

        $dimensionLabel = $groupBy === 'item' ? 'item' : 'customer';

        $lines = [];
        $lines[] = "Sales by {$dimensionLabel} for {$period['label']}.";
        $lines[] = "Total sales: {$this->money($totalCents)}.";

        if ($topRows->isEmpty()) {
            $lines[] = 'No sales were recorded in this period.';
        } else {
            $count = $topRows->count();
            $lines[] = "Top {$count} {$dimensionLabel}".($count === 1 ? '' : 's').' by revenue:';

            $rank = 1;
            foreach ($topRows as $row) {
                $label = $row['label'] ?? ($dimensionLabel === 'item' ? 'No item' : 'No contact');
                $lines[] = "{$rank}. {$label}: {$this->money((int) $row['amount_cents'])} (qty ".rtrim(rtrim(number_format((float) $row['qty'], 2), '0'), '.').')';
                $rank++;
            }
        }

        return Response::text(implode("\n", $lines));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'period' => $schema->string()
                ->description('Named period: this_month, last_month, this_quarter, last_quarter, this_year, last_year, or ytd (default). Ignored when start/end are provided.'),
            'start' => $schema->string()
                ->description('Optional start date (ISO YYYY-MM-DD). Use with "end" for an explicit custom range.'),
            'end' => $schema->string()
                ->description('Optional end date (ISO YYYY-MM-DD). Use with "start" for an explicit custom range.'),
            'group_by' => $schema->string()
                ->description('How to group sales: "customer" (default) or "item".'),
            'limit' => $schema->integer()
                ->description('Maximum number of rows to return, ranked by revenue. Defaults to 10.'),
        ];
    }
}
