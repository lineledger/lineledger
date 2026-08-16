<?php

namespace App\Actions\Sales;

use App\Exceptions\Posting\PostingValidationException;
use App\Models\Invoice;
use App\Models\SalesOrder;
use App\Services\Posting\InvoicePoster;
use Illuminate\Support\Facades\DB;

/**
 * Fulfills a sales order by generating a single Draft invoice for the requested
 * quantities, drawing down each order line. The new invoice carries the
 * sales_order_id, and each invoice line carries its sales_order_line_id, so the
 * order's fulfilled/backordered quantities derive live from linked invoices.
 *
 * No posting and no inventory movement happen here — the invoice is a Draft.
 * When the caller posts it, the existing {@see InvoicePoster}
 * issues stock and COGS exactly as for any other invoice.
 */
final class FulfillSalesOrder
{
    public function __construct(protected SaveInvoice $saveInvoice) {}

    /**
     * @param  array<int|string, mixed>  $lineQuantities  map of sales_order_line_id => qty to invoice
     */
    public function handle(SalesOrder $salesOrder, array $lineQuantities): Invoice
    {
        if (! $salesOrder->effectiveStatus()->canFulfill()) {
            throw new PostingValidationException(__('This sales order cannot be fulfilled.'));
        }

        return DB::transaction(function () use ($salesOrder, $lineQuantities): Invoice {
            $salesOrder->loadMissing('lines.invoiceLines.invoice');
            $company = app('current_company');

            $invoiceLines = [];

            foreach ($salesOrder->lines as $line) {
                $requested = (float) ($lineQuantities[$line->id] ?? 0);

                if ($requested <= 0.00001) {
                    continue;
                }

                if ($requested - $line->qtyBackordered() > 0.00001) {
                    throw new PostingValidationException(
                        __('Cannot invoice more than the outstanding quantity for a line.')
                    );
                }

                $invoiceLines[] = [
                    'item_id' => $line->item_id,
                    'sales_order_line_id' => $line->id,
                    'account_id' => $line->account_id,
                    'description' => $line->description,
                    'quantity' => $requested,
                    'unit_price_cents' => (int) $line->unit_price_cents,
                    'tax_code_id' => $line->tax_code_id,
                ];
            }

            if ($invoiceLines === []) {
                throw new PostingValidationException(__('Select at least one quantity to invoice.'));
            }

            return $this->saveInvoice->handle([
                'contact_id' => $salesOrder->contact_id,
                'sales_order_id' => $salesOrder->id,
                'invoice_no' => null,
                'invoice_date' => $company->currentDateTime()->toDateString(),
                'due_date' => null, // SaveInvoice derives from terms_id, else invoice_date
                'terms_id' => $salesOrder->terms_id,
                'memo' => $salesOrder->memo,
                'lines' => $invoiceLines,
            ]);
        });
    }
}
