<?php

namespace App\Services\Reporting;

use App\Models\Company;
use App\Models\Item;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pure read service for Inventory reports. Stock status reads the cached
 * quantities on inventory items; valuation reads the remaining FIFO layers
 * (the authoritative current inventory value).
 *
 * Valuation is "as of now" — historical point-in-time valuation would require
 * replaying stock movements with FIFO-layer reconstruction and is deferred.
 * Stock movements carry no class/location, so by-location inventory is also
 * out of scope (see the Phase 2 plan).
 */
class InventoryReportBuilder
{
    /**
     * Current on-hand status for every inventory-tracked item.
     *
     * @return Collection<int, array{item_id: int, name: string, sku: ?string, qty_on_hand: float, reorder_point: ?float, unit_cost_cents: int, below_reorder: bool}>
     */
    public function stockStatus(Company $company): Collection
    {
        return Item::query()
            ->where('company_id', $company->id)
            ->where('track_inventory', true)
            ->orderBy('name')
            ->get()
            ->map(function (Item $item): array {
                $qty = (float) $item->qty_on_hand_cached;
                $reorder = $item->reorder_point !== null ? (float) $item->reorder_point : null;

                return [
                    'item_id' => $item->id,
                    'name' => $item->name,
                    'sku' => $item->sku,
                    'qty_on_hand' => $qty,
                    'reorder_point' => $reorder,
                    'unit_cost_cents' => (int) $item->unit_cost_cents_cached,
                    'below_reorder' => $reorder !== null && $qty <= $reorder,
                ];
            })
            ->values();
    }

    /**
     * Current inventory valuation per item, from remaining FIFO layers.
     *
     * @return array{rows: Collection<int, array{item_id: int, name: string, sku: ?string, qty: float, avg_cost_cents: int, value_cents: int}>, total_value_cents: int}
     */
    public function valuationSummary(Company $company): array
    {
        $layers = DB::table('stock_layers')
            ->where('company_id', $company->id)
            ->groupBy('item_id')
            ->selectRaw('item_id, SUM(qty_remaining) as qty, SUM(qty_remaining * unit_cost_cents) as value')
            ->get()
            ->keyBy('item_id');

        $rows = Item::query()
            ->where('company_id', $company->id)
            ->where('track_inventory', true)
            ->orderBy('name')
            ->get()
            ->map(function (Item $item) use ($layers): array {
                $layer = $layers->get($item->id);
                $qty = (float) ($layer->qty ?? 0);
                $value = (int) round((float) ($layer->value ?? 0));

                return [
                    'item_id' => $item->id,
                    'name' => $item->name,
                    'sku' => $item->sku,
                    'qty' => $qty,
                    'avg_cost_cents' => $qty > 0.0 ? (int) round($value / $qty) : 0,
                    'value_cents' => $value,
                ];
            })
            ->values();

        return [
            'rows' => $rows,
            'total_value_cents' => (int) $rows->sum('value_cents'),
        ];
    }
}
