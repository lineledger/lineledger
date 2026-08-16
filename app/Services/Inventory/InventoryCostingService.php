<?php

namespace App\Services\Inventory;

use App\Models\Item;
use App\Models\StockMovement;

interface InventoryCostingService
{
    /**
     * Record an inbound stock receipt at the given unit cost.
     * Updates cached on-hand and avg-cost on the item.
     */
    public function recordReceipt(Item $item, string $qty, int $unitCostCents, MovementContext $ctx): StockMovement;

    /**
     * Record an outbound stock issue. Throws InsufficientStockException if qty exceeds on-hand.
     *
     * @return array{movement: StockMovement, cost_cents: int}
     */
    public function recordIssue(Item $item, string $qty, MovementContext $ctx): array;

    /**
     * Write a reversing movement (and restore FIFO layers, if applicable).
     */
    public function reverse(StockMovement $movement, ?MovementContext $ctx = null): StockMovement;

    /**
     * Predict whether `recordIssue` would succeed for the given qty, without writing.
     */
    public function projectedOnHandAfterIssues(Item $item, string $totalIssueQty): string;
}
