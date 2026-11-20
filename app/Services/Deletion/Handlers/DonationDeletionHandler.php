<?php

namespace App\Services\Deletion\Handlers;

use App\Enums\DeletableDocumentType;
use App\Enums\DonationReceiptStatus;
use App\Services\Deletion\DeletionWork;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Deleting a donation. An official receipt issued for it, or voided after
 * being issued, must be kept (CRA), so it refuses the delete: void the
 * donation instead. A draft receipt was never issued and goes with it.
 */
final class DonationDeletionHandler extends DocumentDeletionHandler
{
    public function type(): DeletableDocumentType
    {
        return DeletableDocumentType::Donation;
    }

    protected function lines(): ?array
    {
        return null;
    }

    protected function totalColumn(): string
    {
        return 'amount_cents';
    }

    public function blockers(Model $document): array
    {
        $receipts = DB::table('donation_receipts')
            ->where('donation_id', $document->getKey())
            ->where('status', '!=', DonationReceiptStatus::Draft->value)
            ->orderBy('id')
            ->get(['id', 'receipt_no', 'deleted_at']);

        return $receipts->map(fn ($receipt) => $this->blocker(
            $document,
            __('Official donation receipt :no was issued for this donation. CRA rules mean it must be kept, so void the donation instead.', ['no' => (string) $receipt->receipt_no]),
            'donation-receipts.show',
            ['donationReceipt' => $receipt->id],
            $receipt->deleted_at,
        ))->values()->all();
    }

    protected function beforePurge(Model $document, DeletionWork $work): void
    {
        $drafts = DB::table('donation_receipts')
            ->where('donation_id', $document->getKey())
            ->where('status', DonationReceiptStatus::Draft->value)
            ->pluck('id');

        DB::table('donations')->where('id', $document->getKey())->update(['donation_receipt_id' => null]);
        DB::table('donation_receipts')->whereIn('reissued_from_id', $drafts)->update(['reissued_from_id' => null]);
        DB::table('donation_receipts')->whereIn('id', $drafts)->delete();
    }
}
