<?php

namespace App\Actions\Purchasing;

use App\Models\Bill;
use App\Models\PurchaseOrder;
use App\Services\Posting\BillPoster;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Receives against a purchase order by generating a single Draft bill for the
 * requested quantities, drawing down each order line. The new bill carries the
 * purchase_order_id, and each bill line carries its purchase_order_line_id, so the
 * order's billed/backordered quantities derive live from linked bills.
 *
 * No posting and no inventory movement happen here — the bill is a Draft. When the
 * caller posts it, the existing {@see BillPoster} receives stock for tracked items
 * exactly as for any other bill.
 */
final class FulfillPurchaseOrder
{
    public function __construct(protected SaveBill $saveBill) {}

    /**
     * @param  array<int|string, mixed>  $lineQuantities  map of purchase_order_line_id => qty to bill
     */
    public function handle(PurchaseOrder $purchaseOrder, array $lineQuantities): Bill
    {
        if (! $purchaseOrder->effectiveStatus()->canReceive()) {
            throw new RuntimeException(__('This purchase order cannot be received.'));
        }

        return DB::transaction(function () use ($purchaseOrder, $lineQuantities): Bill {
            $purchaseOrder->loadMissing('lines.billLines.bill');

            $billLines = [];

            foreach ($purchaseOrder->lines as $line) {
                $requested = (float) ($lineQuantities[$line->id] ?? 0);

                if ($requested <= 0.00001) {
                    continue;
                }

                if ($requested - $line->qtyBackordered() > 0.00001) {
                    throw new RuntimeException(
                        __('Cannot bill more than the outstanding quantity for a line.')
                    );
                }

                $billLines[] = [
                    'item_id' => $line->item_id,
                    'purchase_order_line_id' => $line->id,
                    'account_id' => $line->account_id,
                    'description' => $line->description,
                    'quantity' => $requested,
                    'unit_price_cents' => (int) $line->unit_price_cents,
                    'tax_code_id' => $line->tax_code_id,
                    'class_id' => $line->class_id,
                    'location_id' => $line->location_id,
                ];
            }

            if ($billLines === []) {
                throw new RuntimeException(__('Select at least one quantity to bill.'));
            }

            return $this->saveBill->handle([
                'contact_id' => $purchaseOrder->contact_id,
                'purchase_order_id' => $purchaseOrder->id,
                'bill_no' => null,
                'bill_date' => $purchaseOrder->company->currentDateTime()->toDateString(),
                'due_date' => null, // SaveBill derives from terms_id, else bill_date
                'terms_id' => $purchaseOrder->terms_id,
                'memo' => $purchaseOrder->memo,
                'lines' => $billLines,
            ]);
        });
    }
}
