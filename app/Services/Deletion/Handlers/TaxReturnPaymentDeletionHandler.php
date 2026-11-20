<?php

namespace App\Services\Deletion\Handlers;

use App\Enums\DeletableDocumentType;

/**
 * Deleting a tax payment or refund. A filed return whose snapshot includes it
 * refuses the delete (TransactionDeleter); its status on the return is
 * derived, so nothing else needs recomputing.
 */
final class TaxReturnPaymentDeletionHandler extends DocumentDeletionHandler
{
    public function type(): DeletableDocumentType
    {
        return DeletableDocumentType::TaxReturnPayment;
    }

    protected function lines(): ?array
    {
        return null;
    }

    protected function contactColumn(): ?string
    {
        return null;
    }
}
