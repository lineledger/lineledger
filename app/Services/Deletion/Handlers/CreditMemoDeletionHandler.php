<?php

namespace App\Services\Deletion\Handlers;

use App\Enums\DeletableDocumentType;
use App\Models\Company;
use App\Services\Deletion\DeletionWork;
use App\Services\Posting\InvoiceReconciler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Deleting a credit memo. A refund paying back its credit (a cheque or a
 * negative receipt) blocks. Without the credit the customer's AR goes up, so
 * an invoice "Close settled balance" closed against it opens again.
 */
final class CreditMemoDeletionHandler extends DocumentDeletionHandler
{
    public function __construct(private readonly InvoiceReconciler $reconciler) {}

    public function type(): DeletableDocumentType
    {
        return DeletableDocumentType::CreditMemo;
    }

    protected function lines(): array
    {
        return ['credit_memo_lines', 'credit_memo_id'];
    }

    public function blockers(Model $document): array
    {
        $blockers = [];

        foreach (DB::table('cheques')->where('credit_memo_id', $document->getKey())->orderBy('id')->get(['id', 'cheque_no', 'deleted_at']) as $cheque) {
            $blockers[] = $this->blocker($document, __('Refund cheque :no pays back this credit. Delete the cheque first.', ['no' => (string) $cheque->cheque_no]), 'cheques.show', ['cheque' => $cheque->id], $cheque->deleted_at);
        }

        foreach (DB::table('customer_receipts')->where('credit_memo_id', $document->getKey())->orderBy('id')->get(['id', 'receipt_no', 'deleted_at']) as $refund) {
            $blockers[] = $this->blocker($document, __('Refund :no pays back this credit. Delete the refund first.', ['no' => (string) $refund->receipt_no]), 'receipts.show', ['receipt' => $refund->id], $refund->deleted_at);
        }

        return $blockers;
    }

    public function afterPurge(DeletionWork $work, int $companyId): void
    {
        $company = Company::query()->find($companyId);

        if ($company !== null) {
            foreach (array_keys($work->contactIds) as $contactId) {
                $this->reconciler->releaseExcessReconciliation($company, $contactId);
            }
        }
    }
}
