<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CustomerReceipt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomerReceipt
 */
class ReceiptResource extends JsonResource
{
    /**
     * Eager-load these before building the resource: the applications and the
     * refunds that unapplied_cents and refunded_cents are worked out from.
     */
    public const RELATIONS = ['applications', 'refundCheques', 'refundReceipts'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'receipt_no' => $this->receipt_no,
            'contact_id' => $this->contact_id,
            'receipt_date' => optional($this->receipt_date)->toDateString(),
            'deposit_to_account_id' => $this->deposit_to_account_id,
            'payment_method_id' => $this->payment_method_id,
            'reference' => $this->reference,
            'amount_cents' => (int) $this->amount_cents,
            // The part neither applied to an invoice nor refunded — the
            // customer's credit on this receipt. Applying it is a PUT/PATCH
            // with the full applications list (existing rows plus the new
            // one); refunding it is POST /invoices/{id}/refund. Always 0 on a
            // refund receipt.
            'unapplied_cents' => $this->isRefund()
                ? 0
                : (int) $this->amount_cents - (int) $this->applications->sum('amount_cents') - $this->refundedCents(),
            // Paid back to the customer out of this receipt's unapplied credit.
            'refunded_cents' => $this->isRefund() ? 0 : $this->refundedCents(),
            // On a refund receipt: the credit memo or payment it pays back, and
            // the invoice it was issued from.
            'credit_memo_id' => $this->credit_memo_id,
            'refunded_receipt_id' => $this->refunded_receipt_id,
            'from_invoice_id' => $this->from_invoice_id,
            'memo' => $this->memo,
            'status' => $this->status?->value,
            'posted_at' => optional($this->posted_at)->toIso8601String(),
            'journal_entry_id' => $this->journal_entry_id,
            'applications' => $this->applications->map(fn ($app) => [
                'id' => $app->id,
                'invoice_id' => $app->invoice_id,
                'amount_cents' => (int) $app->amount_cents,
            ])->all(),
        ];
    }
}
