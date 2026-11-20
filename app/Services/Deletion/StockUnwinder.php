<?php

namespace App\Services\Deletion;

use App\Models\Company;
use App\Models\Item;
use App\Models\StockMovement;
use App\Services\Inventory\InventoryCostingFactory;
use App\Services\Inventory\WeightedAverageCostingService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Takes a deleted transaction's stock movements out of the inventory, as if
 * they had never been recorded.
 *
 * Every movement a posting writes carries its journal entry's id, and so does
 * the movement a void writes to undo it, so the movements of a transaction's
 * entries are all of its stock.
 *
 * A movement can only come out when nothing has happened to the item since:
 * a later sale consumed FIFO layers in order, and a later purchase blended the
 * weighted average from what was on hand. Removing an earlier movement would
 * change what those later transactions cost, but their cost of goods is
 * already posted. So {@see blockers()} refuses while another transaction moved
 * the same item later (by id, the FIFO order, or by date, the weighted-average
 * replay order), and the user voids instead.
 */
final class StockUnwinder
{
    public function __construct(private readonly InventoryCostingFactory $costing) {}

    /**
     * @param  list<int>  $entryIds
     * @return list<DeletionBlocker>
     */
    public function blockers(Company $company, array $entryIds, string $noun): array
    {
        $own = $this->movements($company, $entryIds)->get(['id', 'item_id', 'movement_date']);

        $blockers = [];

        foreach ($own->groupBy('item_id') as $itemId => $moves) {
            $firstId = (int) $moves->min('id');
            $firstDate = (string) $moves->map(fn (StockMovement $m): string => (string) SnapshotValue::date($m->movement_date))->min();

            $movedSince = StockMovement::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('item_id', $itemId)
                ->where(fn (Builder $q) => $q->whereNull('journal_entry_id')->orWhereNotIn('journal_entry_id', $entryIds))
                ->where(fn (Builder $q) => $q->where('id', '>', $firstId)->orWhere('movement_date', '>', $firstDate))
                ->exists();

            if ($movedSince) {
                $item = Item::withoutGlobalScopes()->find($itemId);

                $blockers[] = new DeletionBlocker(__('Stock of :item has moved since this :document (a later sale, purchase or adjustment). Deleting it would change what those transactions cost, so void it instead.', [
                    'item' => (string) ($item->name ?? '#'.$itemId),
                    'document' => $noun,
                ]));
            }
        }

        return $blockers;
    }

    /**
     * Undo and remove every stock movement of these entries. Movements a void
     * already undid are just removed; the rest are reversed first, which puts
     * FIFO layers back as they were (or removes the layer a receipt created),
     * then removed with the reversal rows that writes.
     *
     * @param  list<int>  $entryIds
     */
    public function unwind(Company $company, array $entryIds, DeletionWork $work): void
    {
        $movements = $this->movements($company, $entryIds)->orderByDesc('id')->get();

        if ($movements->isEmpty()) {
            return;
        }

        $costing = $this->costing->for($company);
        $undone = $movements->pluck('reversal_of_movement_id')->filter()->map(fn ($id): int => (int) $id)->all();

        foreach ($movements as $movement) {
            $work->item((int) $movement->item_id);

            if ($movement->reversal_of_movement_id === null && ! in_array((int) $movement->id, $undone, true)) {
                $costing->reverse($movement);
            }
        }

        $this->movements($company, $entryIds)->delete();

        // FIFO caches come from the layers, which removing movement rows leaves
        // alone. The weighted average replays the movements, so replay it now
        // that these are gone.
        if ($costing instanceof WeightedAverageCostingService) {
            foreach (array_keys($work->itemIds) as $itemId) {
                $item = Item::withoutGlobalScopes()->find($itemId);

                if ($item !== null) {
                    $costing->recomputeItemFromMovements($item);
                }
            }
        }
    }

    /**
     * @param  list<int>  $entryIds
     * @return Builder<StockMovement>
     */
    private function movements(Company $company, array $entryIds): Builder
    {
        return StockMovement::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereIn('journal_entry_id', $entryIds === [] ? [0] : $entryIds);
    }
}
