<?php

namespace App\Services\Deletion\Handlers;

use App\Enums\DeletableDocumentType;
use App\Models\Company;
use App\Services\Deletion\DeletionWork;
use App\Services\Posting\BillReconciler;

/**
 * Deleting a vendor credit. Without the credit the vendor's AP goes up, so a
 * bill "Close settled balance" closed against it opens again.
 */
final class VendorCreditDeletionHandler extends DocumentDeletionHandler
{
    public function __construct(private readonly BillReconciler $reconciler) {}

    public function type(): DeletableDocumentType
    {
        return DeletableDocumentType::VendorCredit;
    }

    protected function lines(): array
    {
        return ['vendor_credit_lines', 'vendor_credit_id'];
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
