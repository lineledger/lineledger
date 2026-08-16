<?php

namespace App\Mcp\Tools;

use App\Enums\Section;
use App\Mcp\Concerns\AnswersBusinessQuestions;
use App\Services\Reporting\InventoryReportBuilder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class InventoryStatusTool extends Tool
{
    use AnswersBusinessQuestions;

    protected string $title = 'Inventory status';

    protected string $description = 'A current snapshot of inventory-tracked items: quantity on hand, the reorder point, and which items are at or below their reorder point and should be restocked. Set low_only to see only items needing attention. This is read-only and all figures are in the company\'s home currency.';

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAbility('inventory:read')) {
            return $denied;
        }

        if ($denied = $this->requireSection(Section::Inventory)) {
            return $denied;
        }

        $lowOnly = (bool) $request->get('low_only', false);

        /** @var Collection<int, array{item_id: int, name: string, sku: ?string, qty_on_hand: float, reorder_point: ?float, unit_cost_cents: int, below_reorder: bool}> $items */
        $items = app(InventoryReportBuilder::class)->stockStatus($this->company());

        $lowCount = $items->where('below_reorder', true)->count();

        if ($lowOnly) {
            $items = $items->where('below_reorder', true)->values();
        }

        if ($items->isEmpty()) {
            $message = $lowOnly
                ? "No inventory-tracked items are at or below their reorder point for {$this->company()->name}."
                : "{$this->company()->name} has no inventory-tracked items.";

            return Response::text($message);
        }

        $heading = $lowOnly
            ? "Inventory items at or below their reorder point for {$this->company()->name} ({$items->count()}):"
            : "Inventory status for {$this->company()->name} ({$lowCount} of {$items->count()} item(s) at or below reorder point):";

        $lines = [$heading, ''];

        foreach ($items as $item) {
            $qty = (float) $item['qty_on_hand'];
            $reorder = $item['reorder_point'];
            $sku = $item['sku'] !== null && $item['sku'] !== '' ? " [{$item['sku']}]" : '';

            $reorderLabel = $reorder !== null
                ? 'reorder at '.(float) $reorder
                : 'no reorder point';

            $flag = $item['below_reorder'] ? ' — REORDER' : '';

            $lines[] = "• {$item['name']}{$sku}: {$qty} on hand, {$reorderLabel}, unit cost {$this->money($item['unit_cost_cents'])}{$flag}";
        }

        if (! $lowOnly && $lowCount === 0) {
            $lines[] = '';
            $lines[] = 'All items are above their reorder point.';
        }

        return Response::text(implode("\n", $lines));
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'low_only' => $schema->boolean()
                ->description('When true, list only items at or below their reorder point (default false, which lists every inventory-tracked item).'),
        ];
    }
}
