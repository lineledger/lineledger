<?php

namespace App\Actions\MasterData;

use App\Enums\ItemType;
use App\Enums\StockAdjustmentReason;
use App\Models\Item;
use App\Models\StockAdjustment;
use App\Services\Posting\StockAdjustmentPoster;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates an inventory/service item. Shared by the Livewire items
 * page and the API.
 *
 * When inventory tracking is first enabled on an item (it was not tracked
 * before) and an opening quantity is supplied, this posts a one-time
 * OpeningBalance StockAdjustment via StockAdjustmentPoster — exactly as the
 * Livewire form does. The opening-balance posting is the only GL side effect.
 *
 * Expected $data shape (cents-based):
 *   name:                       string
 *   sku:                        ?string
 *   description:                ?string
 *   income_account_id:          int
 *   expense_account_id:         ?int   (purchase/expense account; falls back to income on purchase lines)
 *   default_tax_code_id:        ?int
 *   default_secondary_tax_code_id: ?int   (optional second default tax, e.g. PST alongside GST)
 *   item_category_id:           ?int   (item category for grouping/filtering)
 *   default_price_cents:        ?int
 *   is_active:                  ?bool
 *   track_inventory:            ?bool
 *   inventory_asset_account_id: ?int   (required when tracking)
 *   cogs_account_id:            ?int   (required when tracking)
 *   reorder_point:              ?numeric-string|float|null
 *   opening_qty:                ?float (only honored on first enabling tracking)
 *   opening_cost_cents:         ?int
 */
final class SaveItem
{
    public function __construct(protected StockAdjustmentPoster $stockPoster) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?Item $item = null): Item
    {
        return DB::transaction(function () use ($data, $item): Item {
            $company = app('current_company');

            // `type` is authoritative when given (the Livewire form sends it);
            // the API still sends track_inventory, from which we derive a type.
            $type = isset($data['type'])
                ? ($data['type'] instanceof ItemType ? $data['type'] : ItemType::from((string) $data['type']))
                : null;

            if ($type !== null) {
                $tracking = $type->tracksInventory();
            } else {
                $tracking = (bool) ($data['track_inventory'] ?? false);
                $type = $tracking ? ItemType::Inventory : ItemType::Service;
            }

            $payload = [
                'name' => $data['name'],
                'sku' => $data['sku'] ?? null,
                'description' => $data['description'] ?? null,
                'type' => $type->value,
                'item_category_id' => $data['item_category_id'] ?? null,
                'income_account_id' => $data['income_account_id'],
                'expense_account_id' => $data['expense_account_id'] ?? null,
                'default_tax_code_id' => $data['default_tax_code_id'] ?? null,
                'default_secondary_tax_code_id' => $data['default_secondary_tax_code_id'] ?? null,
                'default_price_cents' => (int) ($data['default_price_cents'] ?? 0),
                'track_inventory' => $tracking,
                'inventory_asset_account_id' => $tracking ? ($data['inventory_asset_account_id'] ?? null) : null,
                'cogs_account_id' => $tracking ? ($data['cogs_account_id'] ?? null) : null,
                'reorder_point' => isset($data['reorder_point']) && $data['reorder_point'] !== '' ? $data['reorder_point'] : null,
            ];

            if (array_key_exists('is_active', $data)) {
                $payload['is_active'] = (bool) $data['is_active'];
            }

            $wasTrackedBefore = (bool) ($item?->track_inventory);

            if ($item && $item->exists) {
                $item->update($payload);
            } else {
                $item = Item::create($payload + ['is_active' => $data['is_active'] ?? true]);
            }

            // Bundle components: always cleared first so an item that is no longer a
            // bundle drops any it used to carry, then rebuilt for a bundle.
            $item->components()->delete();

            if ($type === ItemType::Bundle) {
                foreach (array_values($data['components'] ?? []) as $index => $component) {
                    if (empty($component['component_item_id'])) {
                        continue;
                    }

                    $item->components()->create([
                        'component_item_id' => $component['component_item_id'],
                        'quantity' => $component['quantity'] ?? '1',
                        'line_order' => $index,
                    ]);
                }
            }

            $openingQty = (float) ($data['opening_qty'] ?? 0);

            if ($tracking && ! $wasTrackedBefore && $openingQty > 0) {
                $adjustment = StockAdjustment::create([
                    'adjustment_no' => $this->stockPoster->nextAdjustmentNumber($company),
                    'adjustment_date' => $company->currentDateTime()->toDateString(),
                    'reason' => StockAdjustmentReason::OpeningBalance,
                    'notes' => 'Opening balance for '.$item->name,
                ]);

                $adjustment->lines()->create([
                    'item_id' => $item->id,
                    'qty_change' => $openingQty,
                    'unit_cost_cents' => (int) ($data['opening_cost_cents'] ?? 0),
                    'line_order' => 0,
                ]);

                $this->stockPoster->post($adjustment->fresh('lines.item'));
            }

            return $item->fresh();
        });
    }
}
