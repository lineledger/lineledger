<?php

namespace App\Support\Mcp;

use App\Mcp\Resources\ItemsResource;
use App\Mcp\Tools\ItemsCatalogTool;
use App\Models\Company;
use App\Models\Item;
use App\Support\Money;

/**
 * Renders a company's items/products catalog as plain text for the MCP server.
 * Shared by {@see ItemsResource} and its companion {@see ItemsCatalogTool}.
 * Each line carries the name, SKU, default price, inventory state, and the
 * numeric API id (see {@see ApiIdNote}).
 */
class ItemsPresenter
{
    public function render(Company $company): string
    {
        $items = Item::query()->orderBy('name')->get();

        if ($items->isEmpty()) {
            return "{$company->name} has no items.";
        }

        $lines = [
            "Items / products for {$company->name} ({$items->count()}):",
            ApiIdNote::for('item_id'),
            '',
        ];

        foreach ($items as $item) {
            $sku = filled($item->sku) ? " [{$item->sku}]" : '';
            $price = Money::fromCents((int) $item->default_price_cents, $company->currency_code)->format();
            $inactive = $item->is_active ? '' : ' (inactive)';

            $line = "• {$item->name}{$sku}: {$price}{$inactive}";

            if ($item->track_inventory) {
                $qty = (float) $item->qty_on_hand_cached;
                $line .= " — inventory-tracked, {$qty} on hand";
            }

            $lines[] = $line." (API id {$item->id})";
        }

        return implode("\n", $lines);
    }
}
