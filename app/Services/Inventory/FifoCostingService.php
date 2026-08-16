<?php

namespace App\Services\Inventory;

use App\Exceptions\Inventory\InsufficientStockException;
use App\Models\Item;
use App\Models\StockLayer;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * FIFO costing using a layer table:
 *   On receipt:  create a layer (qty_remaining = qty, unit_cost = received).
 *   On issue:    consume oldest layers until qty satisfied, recording each
 *                layer consumption in the movement's consumed_layers JSON.
 *   On reverse:  for a receipt, the layer is unwound (refund layer or fail
 *                if partially consumed). For an issue, each consumed layer
 *                gets its qty_remaining restored.
 */
class FifoCostingService implements InventoryCostingService
{
    public function recordReceipt(Item $item, string $qty, int $unitCostCents, MovementContext $ctx): StockMovement
    {
        if ((float) $qty <= 0) {
            throw new RuntimeException('Receipt qty must be positive.');
        }

        return DB::transaction(function () use ($item, $qty, $unitCostCents, $ctx) {
            $item->refresh();

            $recvQty = (float) $qty;
            $totalCost = (int) round($recvQty * $unitCostCents);

            $movement = StockMovement::create([
                'company_id' => $item->company_id,
                'item_id' => $item->id,
                'movement_date' => $ctx->movementDate->toDateString(),
                'qty_change' => $qty,
                'unit_cost_cents' => $unitCostCents,
                'total_cost_cents' => $totalCost,
                'source_type' => $ctx->sourceType,
                'source_id' => $ctx->sourceId,
                'source_line_id' => $ctx->sourceLineId,
                'journal_entry_id' => $ctx->journalEntryId,
                'notes' => $ctx->notes,
            ]);

            StockLayer::create([
                'company_id' => $item->company_id,
                'item_id' => $item->id,
                'stock_movement_id' => $movement->id,
                'qty_remaining' => $qty,
                'unit_cost_cents' => $unitCostCents,
            ]);

            $this->refreshItemCaches($item);

            return $movement;
        });
    }

    public function recordIssue(Item $item, string $qty, MovementContext $ctx): array
    {
        if ((float) $qty <= 0) {
            throw new RuntimeException('Issue qty must be positive.');
        }

        return DB::transaction(function () use ($item, $qty, $ctx) {
            $item->refresh();

            $available = (float) $item->qty_on_hand_cached;
            $issueQty = (float) $qty;

            if ($issueQty - $available > 0.00001) {
                throw InsufficientStockException::for($item, $qty, (string) $item->qty_on_hand_cached);
            }

            $remaining = $issueQty;
            $consumed = [];
            $costCents = 0;

            $layers = StockLayer::query()
                ->where('item_id', $item->id)
                ->where('qty_remaining', '>', 0)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($layers as $layer) {
                if ($remaining <= 0.00001) {
                    break;
                }

                $available = (float) $layer->qty_remaining;
                $take = min($available, $remaining);
                $layerCost = (int) round($take * $layer->unit_cost_cents);

                $consumed[] = [
                    'layer_id' => $layer->id,
                    'qty' => number_format($take, 4, '.', ''),
                    'unit_cost_cents' => (int) $layer->unit_cost_cents,
                    'cost_cents' => $layerCost,
                ];

                $costCents += $layerCost;
                $remaining -= $take;

                $layer->forceFill([
                    'qty_remaining' => $available - $take,
                ])->save();
            }

            if ($remaining > 0.00001) {
                // Should not happen because qty_on_hand_cached agrees with sum of layers,
                // but guard anyway.
                throw InsufficientStockException::for($item, $qty, (string) $item->qty_on_hand_cached);
            }

            $unitCostAvg = $issueQty > 0 ? (int) round($costCents / $issueQty) : 0;

            $movement = StockMovement::create([
                'company_id' => $item->company_id,
                'item_id' => $item->id,
                'movement_date' => $ctx->movementDate->toDateString(),
                'qty_change' => '-'.ltrim($qty, '-'),
                'unit_cost_cents' => $unitCostAvg,
                'total_cost_cents' => -$costCents,
                'source_type' => $ctx->sourceType,
                'source_id' => $ctx->sourceId,
                'source_line_id' => $ctx->sourceLineId,
                'journal_entry_id' => $ctx->journalEntryId,
                'consumed_layers' => $consumed,
                'notes' => $ctx->notes,
            ]);

            $this->refreshItemCaches($item);

            return ['movement' => $movement, 'cost_cents' => $costCents];
        });
    }

    public function reverse(StockMovement $movement, ?MovementContext $ctx = null): StockMovement
    {
        if ($movement->reversal_of_movement_id !== null) {
            throw new RuntimeException("Cannot reverse a reversing movement (id {$movement->id}).");
        }

        return DB::transaction(function () use ($movement, $ctx) {
            $item = Item::withoutGlobalScopes()->findOrFail($movement->item_id);

            if ((float) $movement->qty_change > 0) {
                // Receipt reversal: unwind the layer this movement created.
                $layer = StockLayer::query()
                    ->where('stock_movement_id', $movement->id)
                    ->lockForUpdate()
                    ->first();

                if ($layer) {
                    $originalQty = (float) $movement->qty_change;
                    $consumed = $originalQty - (float) $layer->qty_remaining;

                    if ($consumed > 0.00001) {
                        throw new RuntimeException(
                            "Cannot reverse receipt movement {$movement->id}: layer has been partially consumed ".
                            "({$consumed} units). Reverse the dependent issues first."
                        );
                    }

                    $layer->delete();
                }
            } else {
                // Issue reversal: restore each consumed layer's qty_remaining.
                $consumedLayers = is_array($movement->consumed_layers) ? $movement->consumed_layers : [];

                foreach ($consumedLayers as $row) {
                    $layer = StockLayer::query()->where('id', $row['layer_id'])->lockForUpdate()->first();
                    if (! $layer) {
                        continue; // Layer hard-deleted via a receipt reversal — skip.
                    }
                    $layer->forceFill([
                        'qty_remaining' => bcadd((string) $layer->qty_remaining, (string) $row['qty'], 4),
                    ])->save();
                }
            }

            $reversal = StockMovement::create([
                'company_id' => $movement->company_id,
                'item_id' => $movement->item_id,
                'movement_date' => $ctx?->movementDate?->toDateString() ?? $movement->movement_date->toDateString(),
                'qty_change' => bcmul((string) $movement->qty_change, '-1', 4),
                'unit_cost_cents' => $movement->unit_cost_cents,
                'total_cost_cents' => -$movement->total_cost_cents,
                'source_type' => $ctx?->sourceType ?? $movement->source_type,
                'source_id' => $ctx?->sourceId ?? $movement->source_id,
                'source_line_id' => $ctx?->sourceLineId ?? $movement->source_line_id,
                'journal_entry_id' => $ctx?->journalEntryId ?? $movement->journal_entry_id,
                'reversal_of_movement_id' => $movement->id,
                'notes' => $ctx?->notes ?? "Reversal of movement {$movement->id}",
            ]);

            $this->refreshItemCaches($item);

            return $reversal;
        });
    }

    public function projectedOnHandAfterIssues(Item $item, string $totalIssueQty): string
    {
        return bcsub((string) $item->qty_on_hand_cached, $totalIssueQty, 4);
    }

    protected function refreshItemCaches(Item $item): void
    {
        $totals = StockLayer::query()
            ->where('item_id', $item->id)
            ->selectRaw('COALESCE(SUM(qty_remaining), 0) as qty, COALESCE(SUM(qty_remaining * unit_cost_cents), 0) as value')
            ->first();

        $qty = (float) ($totals->qty ?? 0);
        $value = (float) ($totals->value ?? 0);
        $avgCents = $qty > 0 ? (int) round($value / $qty) : 0;

        $item->forceFill([
            'qty_on_hand_cached' => $qty,
            'unit_cost_cents_cached' => $avgCents,
        ])->save();
    }
}
