<?php

namespace App\Actions\Sales;

use App\Enums\EstimateStatus;
use App\Models\Estimate;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

/**
 * Converts an estimate into a single Draft invoice, links the two records, and
 * marks the estimate Converted. Reuses {@see SaveInvoice} so tax recompute,
 * numbering, and line creation stay in one place. No posting occurs — the new
 * invoice is a Draft with no GL entry until the user explicitly posts it.
 */
final class ConvertEstimateToInvoice
{
    public function __construct(protected SaveInvoice $saveInvoice) {}

    public function handle(Estimate $estimate): Invoice
    {
        if (! $estimate->effectiveStatus()->canConvert()) {
            throw new \RuntimeException(__('This estimate cannot be converted.'));
        }

        return DB::transaction(function () use ($estimate): Invoice {
            $estimate->loadMissing('lines');
            $company = app('current_company');

            $invoice = $this->saveInvoice->handle([
                'contact_id' => $estimate->contact_id,
                'invoice_no' => null,
                'invoice_date' => $company->currentDateTime()->toDateString(),
                'due_date' => null, // SaveInvoice derives from terms_id, else invoice_date
                'terms_id' => $estimate->terms_id,
                'memo' => $estimate->memo,
                'customer_message' => $estimate->customer_message,
                'sales_rep_id' => $estimate->sales_rep_id,
                'customer_po' => $estimate->customer_po,
                'lines' => $estimate->lines->map(fn ($line): array => [
                    'item_id' => $line->item_id,
                    'account_id' => $line->account_id,
                    'description' => $line->description,
                    'service_date' => $line->service_date,
                    'quantity' => $line->quantity,
                    'unit_price_cents' => (int) $line->unit_price_cents,
                    'line_discount_cents' => (int) $line->line_discount_cents,
                    'line_discount_pct' => $line->line_discount_pct,
                    'tax_code_id' => $line->tax_code_id,
                    'secondary_tax_code_id' => $line->secondary_tax_code_id,
                    'class_id' => $line->class_id,
                    'location_id' => $line->location_id,
                ])->all(),
            ]);

            $estimate->forceFill([
                'status' => EstimateStatus::Converted,
                'converted_invoice_id' => $invoice->id,
            ])->save();

            return $invoice;
        });
    }
}
