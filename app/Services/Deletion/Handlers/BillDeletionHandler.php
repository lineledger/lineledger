<?php

namespace App\Services\Deletion\Handlers;

use App\Enums\DeletableDocumentType;
use App\Services\Deletion\DeletionWork;
use App\Services\Deletion\InboxPromotion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Deleting a bill or a reimbursement. A payment applied to it (any status) or
 * a balance closed against a vendor credit blocks. The inbox document it was
 * made from goes back to review, and bank-feed suggestions to pay it are
 * dropped. Purchase-order status is derived from bill lines, so the order
 * reopens by itself.
 */
final class BillDeletionHandler extends DocumentDeletionHandler
{
    public function type(): DeletableDocumentType
    {
        return DeletableDocumentType::Bill;
    }

    protected function lines(): array
    {
        return ['bill_lines', 'bill_id'];
    }

    public function blockers(Model $document): array
    {
        $blockers = [];

        $payments = DB::table('bill_payment_applications as a')
            ->join('bill_payments as p', 'p.id', '=', 'a.bill_payment_id')
            ->where('a.bill_id', $document->getKey())
            ->orderBy('p.id')
            ->get(['p.id', 'p.payment_no', 'p.deleted_at'])
            ->unique('id');

        foreach ($payments as $payment) {
            $blockers[] = $this->blocker($document, __('Bill payment :no is applied to this bill. Delete that payment, or take this bill off it, first.', ['no' => (string) $payment->payment_no]), 'bill-payments.show', ['payment' => $payment->id], $payment->deleted_at);
        }

        if ((int) $document->getAttribute('reconciled_cents') > 0) {
            $blockers[] = $this->blocker($document, __('This bill was closed against a vendor credit (Close settled balance). Reopen it first.'), 'bills.show', ['bill' => $document->getKey()]);
        }

        return $blockers;
    }

    protected function beforePurge(Model $document, DeletionWork $work): void
    {
        InboxPromotion::release(['bill', 'reimbursement'], (int) $document->getKey());

        DB::table('bank_statement_lines')->where('suggested_bill_id', $document->getKey())->update(['suggested_bill_id' => null]);

        $lines = DB::table('bank_statement_lines')
            ->where('company_id', $document->getAttribute('company_id'))
            ->whereNotNull('suggested_bill_allocations')
            ->get(['id', 'suggested_bill_allocations']);

        foreach ($lines as $line) {
            $allocations = json_decode((string) $line->suggested_bill_allocations, true);

            if (! is_array($allocations)) {
                continue;
            }

            $kept = array_values(array_filter($allocations, fn ($a): bool => (int) ($a['bill_id'] ?? 0) !== (int) $document->getKey()));

            if (count($kept) !== count($allocations)) {
                DB::table('bank_statement_lines')->where('id', $line->id)->update(['suggested_bill_allocations' => $kept === [] ? null : json_encode($kept)]);
            }
        }
    }
}
