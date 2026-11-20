<?php

namespace App\Services\Deletion\Handlers;

use App\Enums\DeletableDocumentType;
use App\Models\Company;
use App\Models\CustomerReceipt;
use App\Models\Invoice;
use App\Services\Deletion\DeletionBlocker;
use App\Services\Deletion\DeletionHandler;
use App\Services\Deletion\DeletionWork;
use App\Services\Deletion\SnapshotValue;
use App\Services\Posting\InvoiceReconciler;
use App\Services\Posting\ReceiptPoster;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Deleting a customer payment (or a refund recorded as a negative receipt).
 *
 * The payment's applications are its own, so they go with it and the invoices
 * they paid show as owing again. A deposit holding it, a refund paying back
 * its credit, an official donation receipt issued for it, or a Stripe card
 * payment (whose webhook would post it again) blocks.
 */
final class CustomerReceiptDeletionHandler implements DeletionHandler
{
    public function __construct(
        private readonly ReceiptPoster $receipts,
        private readonly InvoiceReconciler $reconciler,
    ) {}

    public function type(): DeletableDocumentType
    {
        return DeletableDocumentType::CustomerReceipt;
    }

    public function journalEntryIds(Model $document): array
    {
        $entryId = $document->getAttribute('journal_entry_id');

        return $entryId !== null ? [(int) $entryId] : [];
    }

    public function blockers(Model $document): array
    {
        $receipt = $this->own($document);

        $slug = $receipt->company->slug;
        $blockers = [];

        foreach (DB::table('deposit_lines as l')->join('deposits as d', 'd.id', '=', 'l.deposit_id')->where('l.customer_receipt_id', $receipt->id)->orderBy('d.id')->get(['d.id', 'd.deposit_no', 'd.deleted_at'])->unique('id') as $deposit) {
            $blockers[] = new DeletionBlocker(
                __('Deposit :no holds this :document. Take it off that deposit, or delete the deposit, first.', ['no' => (string) $deposit->deposit_no, 'document' => __('receipt')]),
                $deposit->deleted_at === null ? route('deposits.show', ['company' => $slug, 'deposit' => $deposit->id]) : null,
            );
        }

        foreach (DB::table('customer_receipts')->where('refunded_receipt_id', $receipt->id)->orderBy('id')->get(['id', 'receipt_no', 'deleted_at']) as $refund) {
            $blockers[] = new DeletionBlocker(
                __('Refund :no pays back this payment\'s credit. Delete the refund first.', ['no' => (string) $refund->receipt_no]),
                $refund->deleted_at === null ? route('receipts.show', ['company' => $slug, 'receipt' => $refund->id]) : null,
            );
        }

        foreach (DB::table('cheques')->where('refunded_receipt_id', $receipt->id)->orderBy('id')->get(['id', 'cheque_no', 'deleted_at']) as $cheque) {
            $blockers[] = new DeletionBlocker(
                __('Refund cheque :no pays back this payment\'s credit. Delete the cheque first.', ['no' => (string) $cheque->cheque_no]),
                $cheque->deleted_at === null ? route('cheques.show', ['company' => $slug, 'cheque' => $cheque->id]) : null,
            );
        }

        foreach (DB::table('donation_receipts')->where('customer_receipt_id', $receipt->id)->orderBy('id')->get(['id', 'receipt_no', 'deleted_at']) as $donationReceipt) {
            $blockers[] = new DeletionBlocker(
                __('Donation receipt :no was made for this payment. An issued official receipt must be kept, so void the payment instead; a draft one can be deleted first.', ['no' => (string) $donationReceipt->receipt_no]),
                $donationReceipt->deleted_at === null ? route('donation-receipts.show', ['company' => $slug, 'donationReceipt' => $donationReceipt->id]) : null,
            );
        }

        if ($receipt->stripe_payment_intent_id !== null) {
            $blockers[] = new DeletionBlocker(__('This payment came in by card through Stripe. Deleting it would let Stripe record it again, so refund it in Stripe and void it here instead.'));
        }

        return $blockers;
    }

    public function warnings(Model $document): array
    {
        $receipt = $this->own($document);

        $numbers = DB::table('receipt_applications as a')
            ->join('invoices as i', 'i.id', '=', 'a.invoice_id')
            ->where('a.customer_receipt_id', $receipt->id)
            ->orderBy('i.invoice_no')
            ->pluck('i.invoice_no')
            ->unique()
            ->all();

        if ($numbers === [] || $receipt->voided_at !== null) {
            return [];
        }

        return [__('It is applied to :invoices, which will show as owing again.', ['invoices' => implode(', ', $numbers)])];
    }

    public function taxCodeIds(Model $document): array
    {
        $receipt = $this->own($document);

        return [];
    }

    public function summary(Model $document): array
    {
        $receipt = $this->own($document);

        return [
            'document_number' => $receipt->receipt_no,
            'document_date' => SnapshotValue::date($receipt->receipt_date),
            'contact_name' => $receipt->contact?->display_name,
            'total_cents' => (int) $receipt->amount_cents,
            'currency_code' => $receipt->getAttribute('currency_code'),
        ];
    }

    public function snapshot(Model $document): array
    {
        $receipt = $this->own($document);

        $receipt->loadMissing('contact', 'depositToAccount', 'paymentMethod');

        return [
            'number' => $receipt->receipt_no,
            'date' => SnapshotValue::date($receipt->receipt_date),
            'status' => $receipt->status->value,
            'contact_id' => (int) $receipt->contact_id,
            'contact_name' => $receipt->contact?->display_name,
            'deposit_to' => $receipt->depositToAccount?->name,
            'payment_method' => $receipt->paymentMethod?->name,
            'reference' => $receipt->reference,
            'memo' => $receipt->memo,
            'amount_cents' => (int) $receipt->amount_cents,
            'currency_code' => $receipt->getAttribute('currency_code'),
            'credit_memo_id' => $receipt->credit_memo_id !== null ? (int) $receipt->credit_memo_id : null,
            'refunded_receipt_id' => $receipt->refunded_receipt_id !== null ? (int) $receipt->refunded_receipt_id : null,
            'posted_at' => SnapshotValue::stamp($receipt->posted_at),
            'voided_at' => SnapshotValue::stamp($receipt->voided_at),
            'applications' => DB::table('receipt_applications as a')
                ->join('invoices as i', 'i.id', '=', 'a.invoice_id')
                ->where('a.customer_receipt_id', $receipt->id)
                ->orderBy('a.id')
                ->get(['i.invoice_no', 'a.amount_cents'])
                ->map(fn ($row): array => ['invoice_no' => (string) $row->invoice_no, 'amount_cents' => (int) $row->amount_cents])
                ->all(),
        ];
    }

    public function purge(Model $document, DeletionWork $work): void
    {
        $receipt = $this->own($document);

        $work->contact((int) $receipt->contact_id);

        foreach (DB::table('receipt_applications')->where('customer_receipt_id', $receipt->id)->pluck('invoice_id') as $invoiceId) {
            $work->invoice((int) $invoiceId);
        }

        DB::table('receipt_applications')->where('customer_receipt_id', $receipt->id)->delete();

        $receipt->forceDelete();
    }

    public function afterPurge(DeletionWork $work, int $companyId): void
    {
        foreach (Invoice::withoutGlobalScopes()->whereIn('id', array_keys($work->invoiceIds))->get() as $invoice) {
            $this->receipts->recomputeInvoicePaidFromAllReceipts($invoice);
        }

        // AR went up: an invoice "Close settled balance" closed against this
        // payment's credit opens again.
        $company = Company::query()->find($companyId);

        if ($company !== null) {
            foreach (array_keys($work->contactIds) as $contactId) {
                $this->reconciler->releaseExcessReconciliation($company, $contactId);
            }
        }
    }

    private function own(Model $document): CustomerReceipt
    {
        if (! $document instanceof CustomerReceipt) {
            throw new InvalidArgumentException('Expected a CustomerReceipt, got '.$document::class.'.');
        }

        return $document;
    }
}
