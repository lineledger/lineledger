<?php

namespace App\Services\Deletion\Handlers;

use App\Enums\DeletableDocumentType;
use App\Enums\EstimateStatus;
use App\Services\Deletion\DeletionWork;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Deleting a sales order. It never posts; an invoice generated from it
 * blocks. The estimate it was converted from can be converted again.
 */
final class SalesOrderDeletionHandler extends DocumentDeletionHandler
{
    public function type(): DeletableDocumentType
    {
        return DeletableDocumentType::SalesOrder;
    }

    protected function lines(): array
    {
        return ['sales_order_lines', 'sales_order_id'];
    }

    public function blockers(Model $document): array
    {
        $invoices = DB::table('invoices')
            ->where('sales_order_id', $document->getKey())
            ->orWhereIn('id', DB::table('invoice_lines')
                ->join('sales_order_lines', 'sales_order_lines.id', '=', 'invoice_lines.sales_order_line_id')
                ->where('sales_order_lines.sales_order_id', $document->getKey())
                ->select('invoice_lines.invoice_id'))
            ->orderBy('id')
            ->get(['id', 'invoice_no', 'deleted_at']);

        return $invoices->map(fn ($invoice) => $this->blocker(
            $document,
            __('Invoice :no was created from this order. Delete the invoice, or cancel the order instead.', ['no' => (string) $invoice->invoice_no]),
            'invoices.show',
            ['invoice' => $invoice->id],
            $invoice->deleted_at,
        ))->values()->all();
    }

    protected function beforePurge(Model $document, DeletionWork $work): void
    {
        foreach (DB::table('estimates')->where('converted_sales_order_id', $document->getKey())->get(['id', 'converted_invoice_id']) as $estimate) {
            DB::table('estimates')->where('id', $estimate->id)->update(array_filter([
                'converted_sales_order_id' => null,
                'status' => $estimate->converted_invoice_id === null ? EstimateStatus::Accepted->value : false,
                'updated_at' => now(),
            ], fn ($value) => $value !== false));
        }
    }
}
