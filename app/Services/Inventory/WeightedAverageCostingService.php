<?php

namespace App\Services\Inventory;

use App\Exceptions\Inventory\InsufficientStockException;
use App\Models\Item;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Weighted-average costing:
 *   On receipt:  new_avg = (qty * old_avg + recv_qty * recv_cost) / (qty + recv_qty)
 *   On issue:    cost_cents = round(issue_qty * current_avg); avg stays.
 *
 * The cached fields `qty_on_hand_cached` and `unit_cost_cents_cached` on the
 * item are maintained incrementally. On reversal, we replay all non-reversal
 * movements (filtering out reversed pairs) to recompute exactly.
 */
class WeightedAverageCostingService implements InventoryCostingService
{
    public function recordReceipt(Item $item, string $qty, int $unitCostCents, MovementContext $ctx): StockMovement
    {
        if ((float) $qty <= 0) {
            throw new RuntimeException('Receipt qty must be positive.');
        }

        return DB::transaction(function () use ($item, $qty, $unitCostCents, $ctx) {
            $item->refresh();

            $oldQty = (float) $item->qty_on_hand_cached;
            $oldAvg = (int) $item->unit_cost_cents_cached;
            $recvQty = (float) $qty;
            $totalCost = (int) round($recvQty * $unitCostCents);

            $newQty = $oldQty + $recvQty;
            $newAvg = $newQty > 0
                ? (int) round(($oldQty * $oldAvg + $totalCost) / $newQty)
                : 0;

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

            $item->forceFill([
                'qty_on_hand_cached' => $newQty,
                'unit_cost_cents_cached' => $newAvg,
            ])->save();

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

            $avg = (int) $item->unit_cost_cents_cached;
            $costCents = (int) round($issueQty * $avg);

            $movement = StockMovement::create([
                'company_id' => $item->company_id,
                'item_id' => $item->id,
                'movement_date' => $ctx->movementDate->toDateString(),
                'qty_change' => '-'.ltrim($qty, '-'),
                'unit_cost_cents' => $avg,
                'total_cost_cents' => -$costCents,
                'source_type' => $ctx->sourceType,
                'source_id' => $ctx->sourceId,
                'source_line_id' => $ctx->sourceLineId,
                'journal_entry_id' => $ctx->journalEntryId,
                'notes' => $ctx->notes,
            ]);

            $newQty = $available - $issueQty;

            $item->forceFill([
                'qty_on_hand_cached' => $newQty,
                // Avg is unchanged on issue (WA semantics).
            ])->save();

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

            $this->recomputeItemFromMovements($item);

            return $reversal;
        });
    }

    public function projectedOnHandAfterIssues(Item $item, string $totalIssueQty): string
    {
        return bcsub((string) $item->qty_on_hand_cached, $totalIssueQty, 4);
    }

    /**
     * Recompute cached qty + avg from all live (non-reversed-pair) movements,
     * in chronological order. Used after reversals to keep caches exact.
     */
    public function recomputeItemFromMovements(Item $item): void
    {
        $movements = StockMovement::query()
            ->where('item_id', $item->id)
            ->orderBy('movement_date')
            ->orderBy('id')
            ->get();

        $live = $this->stripReversedPairs($movements);

        $qty = 0.0;
        $avgCents = 0;

        foreach ($live as $m) {
            $mQty = (float) $m->qty_change;
            if ($mQty > 0) {
                $newQty = $qty + $mQty;
                $avgCents = $newQty > 0
                    ? (int) round(($qty * $avgCents + (float) $m->total_cost_cents) / $newQty)
                    : 0;
                $qty = $newQty;
            } else {
                $qty += $mQty; // qty decreases; avg unchanged
            }
        }

        $item->forceFill([
            'qty_on_hand_cached' => $qty,
            'unit_cost_cents_cached' => $avgCents,
        ])->save();
    }

    /**
     * @param  iterable<StockMovement>  $movements
     * @return array<int, StockMovement>
     */
    protected function stripReversedPairs(iterable $movements): array
    {
        $reversedIds = [];
        foreach ($movements as $m) {
            if ($m->reversal_of_movement_id !== null) {
                $reversedIds[$m->reversal_of_movement_id] = true;
                $reversedIds[$m->id] = true;
            }
        }

        $out = [];
        foreach ($movements as $m) {
            if (! isset($reversedIds[$m->id])) {
                $out[] = $m;
            }
        }

        return $out;
    }
}
