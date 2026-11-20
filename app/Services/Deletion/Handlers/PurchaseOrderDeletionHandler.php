<?php

namespace App\Services\Deletion\Handlers;

use App\Enums\DeletableDocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Deleting a purchase order. It never posts; a bill generated from it
 * blocks.
 */
final class PurchaseOrderDeletionHandler extends DocumentDeletionHandler
{
    public function type(): DeletableDocumentType
    {
        return DeletableDocumentType::PurchaseOrder;
    }

    protected function lines(): array
    {
        return ['purchase_order_lines', 'purchase_order_id'];
    }

    public function blockers(Model $document): array
    {
        $bills = DB::table('bills')
            ->where('purchase_order_id', $document->getKey())
            ->orWhereIn('id', DB::table('bill_lines')
                ->join('purchase_order_lines', 'purchase_order_lines.id', '=', 'bill_lines.purchase_order_line_id')
                ->where('purchase_order_lines.purchase_order_id', $document->getKey())
                ->select('bill_lines.bill_id'))
            ->orderBy('id')
            ->get(['id', 'bill_no', 'deleted_at']);

        return $bills->map(fn ($bill) => $this->blocker(
            $document,
            __('Bill :no was created from this order. Delete the bill, or cancel the order instead.', ['no' => (string) $bill->bill_no]),
            'bills.show',
            ['bill' => $bill->id],
            $bill->deleted_at,
        ))->values()->all();
    }
}
