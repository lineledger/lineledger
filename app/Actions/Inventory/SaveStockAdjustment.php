<?php

namespace App\Actions\Inventory;

use App\Enums\StockAdjustmentReason;
use App\Models\StockAdjustment;
use App\Services\Posting\StockAdjustmentPoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds or updates a DRAFT stock adjustment header and its lines. Shared by the
 * Livewire form and the API. Does NOT post — the caller decides whether to hand
 * the result to StockAdjustmentPoster. There is no repost path; posted
 * adjustments must be voided and recreated.
 *
 * Expected $data shape (cents-based, framework-agnostic):
 *   adjustment_no:   ?string  (null → auto-generated ADJ-######)
 *   adjustment_date: string
 *   reason:          'opening_balance'|'shrinkage'|'damage'|'recount'|'write_off'|'other'
 *   notes:           ?string
 *   lines: array<int, array{
 *     item_id: int, qty_change: string|int|float, unit_cost_cents: ?int, notes: ?string,
 *     class_id: ?int, location_id: ?int
 *   }>
 */
final class SaveStockAdjustment
{
    public function __construct(protected StockAdjustmentPoster $poster) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?StockAdjustment $adjustment = null): StockAdjustment
    {
        return DB::transaction(function () use ($data, $adjustment): StockAdjustment {
            $company = app('current_company');

            $header = [
                'adjustment_date' => CarbonImmutable::parse($data['adjustment_date'])->toDateString(),
                'reason' => StockAdjustmentReason::from($data['reason']),
                'notes' => $data['notes'] ?? null,
            ];

            if ($adjustment && $adjustment->exists) {
                $adjustment->update($header);
            } else {
                $adjustment = StockAdjustment::create($header + [
                    'adjustment_no' => $data['adjustment_no']
                        ?? $this->poster->nextAdjustmentNumber($company),
                ]);
            }

            $adjustment->lines()->delete();

            foreach (array_values($data['lines']) as $index => $line) {
                $adjustment->lines()->create([
                    'item_id' => $line['item_id'],
                    'qty_change' => $line['qty_change'],
                    'unit_cost_cents' => (int) ($line['unit_cost_cents'] ?? 0),
                    'notes' => $line['notes'] ?? null,
                    'line_order' => $index,
                    'class_id' => $line['class_id'] ?? null,
                    'location_id' => $line['location_id'] ?? null,
                ]);
            }

            return $adjustment->fresh('lines.item');
        });
    }
}
